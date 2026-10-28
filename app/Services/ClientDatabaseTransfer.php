<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClientDatabaseTransfer
{
    public const WORKSPACE_TABLES = ['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs', 'company_settings'];

    public const COMPANY_TABLES = ['company_ledgers', 'journal_entries', 'journal_entry_lines', 'company_ledger_changes', 'sheet_import_profiles', 'sheet_import_batches'];

    /** @return array<string, int> */
    public function transfer(int $companyId, string $connection): array
    {
        if (! preg_match('/^client_[a-zA-Z0-9_]+$/D', $connection) || ! is_array(config('database.connections.'.$connection))) {
            throw new RuntimeException('Configure a server-side client_ connection before transferring a client.');
        }
        $target = DB::connection($connection);
        if (! in_array($target->getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Client databases must use SQLite, MySQL or MariaDB.');
        }
        $central = app(ClientDatabase::class)->central();
        if ($target->getSchemaBuilder()->hasTable('users') || $target->getSchemaBuilder()->hasTable('companies')) {
            throw new RuntimeException('The destination contains central or unrelated application tables. Use a dedicated empty database.');
        }

        return $central->transaction(function () use ($companyId, $connection, $target, $central): array {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ($company->tenancy_activated_at !== null) {
                throw new RuntimeException('This client already has a separate database. Its original shared records must not be copied again.');
            }
            foreach (Company::query()->whereNotNull('tenancy_db_connection')->get() as $assigned) {
                $existing = DB::connection($assigned->tenancy_db_connection);
                if ($this->identity($existing) === $this->identity($target)) {
                    throw new RuntimeException('The destination is already assigned to another client.');
                }
            }
            if ($this->identity($central) === $this->identity($target)) {
                throw new RuntimeException('The client database must be different from the central database.');
            }
            $existingTables = array_column($target->getSchemaBuilder()->getTables(), 'name');
            $allowed = [...self::WORKSPACE_TABLES, ...self::COMPANY_TABLES, 'client_database_identity', 'migrations'];
            if (array_diff($existingTables, $allowed) !== []) {
                throw new RuntimeException('The destination contains unrelated tables. Use a dedicated empty database.');
            }
            if (in_array('client_database_identity', $existingTables, true)) {
                $identity = $target->table('client_database_identity')->where('id', 1)->first();
                if ($identity && ((int) $identity->company_id !== $company->id || $identity->workspace_key !== $company->workspaceKey())) {
                    throw new RuntimeException('The destination belongs to another client. No data was replaced.');
                }
            }
            $this->migrate($connection);
            $counts = $target->transaction(function () use ($company, $central, $target): array {
                $identity = $target->table('client_database_identity')->where('id', 1)->lockForUpdate()->first();
                if ($identity && ((int) $identity->company_id !== $company->id || $identity->workspace_key !== $company->workspaceKey())) {
                    throw new RuntimeException('The destination belongs to another client. No data was replaced.');
                }
                $counts = [];
                foreach ([...self::WORKSPACE_TABLES, ...self::COMPANY_TABLES] as $table) {
                    $query = $this->source($central, $table, $company);
                    if ($target->table($table)->exists()) {
                        if ($this->fingerprint($query) !== $this->fingerprint($target->table($table))) {
                            throw new RuntimeException('The destination already contains different records. No data was replaced.');
                        }
                        $counts[$table] = $query->count();

                        continue;
                    }
                    $query->orderBy('id')->chunkById(100, function (Collection $rows) use ($target, $table): void {
                        $target->table($table)->insert($rows->map(fn (object $row): array => (array) $row)->all());
                    });
                    if ($this->fingerprint($query) !== $this->fingerprint($target->table($table))) {
                        throw new RuntimeException('Verification failed for '.$table.'. The client remains on its original database.');
                    }
                    $counts[$table] = $query->count();
                }
                if (! $identity) {
                    $target->table('client_database_identity')->insert(['id' => 1, 'company_id' => $company->id, 'workspace_key' => $company->workspaceKey()]);
                }

                return $counts;
            });
            $database = (string) $target->getDatabaseName();
            if ($target->getDriverName() === 'sqlite') {
                if (dirname(realpath($database)) !== realpath(database_path())) {
                    throw new RuntimeException('SQLite client files must be inside the database directory.');
                }
                $database = basename($database);
            }
            $company->forceFill(['tenancy_db_connection' => $connection, 'tenancy_db_name' => $database, 'tenancy_activated_at' => now()])->save();

            return $counts;
        });
    }

    public function migrate(string $connection): void
    {
        $exit = Artisan::call('migrate', ['--database' => $connection, '--path' => database_path('migrations/clients'), '--realpath' => true, '--force' => true, '--no-interaction' => true]);
        if ($exit !== 0) {
            throw new RuntimeException('Client schema migration failed. The client database assignment was not changed.');
        }
    }

    private function source(Connection $connection, string $table, Company $company): Builder
    {
        return $connection->table($table)->where(in_array($table, self::WORKSPACE_TABLES, true) ? 'workspace_key' : 'company_id', in_array($table, self::WORKSPACE_TABLES, true) ? $company->workspaceKey() : $company->id);
    }

    /** @return array{string, string, string, string, string} */
    private function identity(Connection $connection): array
    {
        return [$connection->getDriverName(), (string) $connection->getConfig('host'), (string) $connection->getConfig('port'), (string) $connection->getConfig('unix_socket'), $connection->getDriverName() === 'sqlite' ? (realpath($connection->getDatabaseName()) ?: $connection->getDatabaseName()) : $connection->getDatabaseName()];
    }

    private function fingerprint(Builder $query): string
    {
        $hash = hash_init('sha256');
        foreach ((clone $query)->orderBy('id')->cursor() as $row) {
            $values = (array) $row;
            ksort($values);
            foreach ($values as &$value) {
                if ($value !== null) {
                    $value = (string) $value;
                    if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $value = $this->canonical($decoded);
                        }
                    } elseif (preg_match('/^-?\d+\.\d+$/D', $value)) {
                        $value = rtrim(rtrim($value, '0'), '.');
                    }
                }
            }
            unset($value);
            hash_update($hash, json_encode($values, JSON_THROW_ON_ERROR));
        }

        return hash_final($hash);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
