<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\TaxCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Tenancy;
use Throwable;

class ClientProvisioner
{
    public function provision(int $companyId): void
    {
        if (! config('tenancy.enabled')) {
            throw new RuntimeException('Client database provisioning is disabled.');
        }
        if (app(Tenancy::class)->initialized) {
            throw new RuntimeException('Provision client databases from a central background job.');
        }
        $central = app(ClientDatabase::class)->central();
        try {
            $central->transaction(function () use ($companyId, $central): void {
                $company = Company::query()->lockForUpdate()->findOrFail($companyId);
                if ($company->databaseReady()) {
                    return;
                }
                foreach (['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs', 'company_settings'] as $table) {
                    if ($central->table($table)->where('workspace_key', $company->workspaceKey())->exists()) {
                        throw new RuntimeException('Existing shared records require a verified transfer before provisioning.');
                    }
                }
                foreach (['company_ledgers', 'journal_entries', 'company_ledger_changes', 'sheet_import_profiles', 'sheet_import_batches'] as $table) {
                    if ($central->table($table)->where('company_id', $company->id)->exists()) {
                        throw new RuntimeException('Existing shared records require a verified transfer before provisioning.');
                    }
                }
                $name = 'client_'.substr($company->workspaceKey(), 0, 40);
                $driver = config('database.connections.client_template.driver');
                if ($driver === 'sqlite') {
                    if (! app()->environment(['local', 'testing'])) {
                        throw new RuntimeException('SQLite client databases are restricted to local testing.');
                    }
                    $name .= '.sqlite';
                }
                if ($company->tenancy_db_name !== null && $company->tenancy_db_name !== $name) {
                    throw new RuntimeException('The reserved database does not match this client.');
                }
                $company->forceFill(['tenancy_db_name' => $name, 'tenancy_db_connection' => 'client_template', 'provisioning_status' => 'provisioning', 'provisioning_attempts' => $company->provisioning_attempts + 1, 'provisioning_error' => null])->save();
                $this->createDatabase($company, $driver);
                $manager = app(DatabaseManager::class);
                $manager->createTenantConnection($company);
                try {
                    $connection = DB::connection('tenant');
                    $tables = array_column($connection->getSchemaBuilder()->getTables(), 'name');
                    if ($tables !== []) {
                        if (! in_array('client_database_identity', $tables, true)) {
                            throw new RuntimeException('The destination is not an empty client database.');
                        }
                        $identity = $connection->table('client_database_identity')->where('id', 1)->first();
                        if (! $identity || (int) $identity->company_id !== $company->id || $identity->workspace_key !== $company->workspaceKey()) {
                            throw new RuntimeException('The destination belongs to another client.');
                        }
                    } else {
                        $connection->getSchemaBuilder()->create('client_database_identity', function (Blueprint $table): void {
                            $table->unsignedTinyInteger('id')->primary();
                            $table->unsignedBigInteger('company_id');
                            $table->char('workspace_key', 64)->unique();
                        });
                        $connection->table('client_database_identity')->insert(['id' => 1, 'company_id' => $company->id, 'workspace_key' => $company->workspaceKey()]);
                    }
                    $this->migrate();
                } finally {
                    DB::purge('tenant');
                }
                $folder = $company->storageDirectory();
                $disk = Storage::disk('client_files');
                if (! $disk->makeDirectory($folder.'/documents')) {
                    throw new RuntimeException('The private client directory could not be created.');
                }
                $manifest = json_encode(['company_id' => $company->id, 'workspace_key' => $company->workspaceKey()], JSON_THROW_ON_ERROR);
                if ($disk->exists($folder.'/identity.json') && $disk->get($folder.'/identity.json') !== $manifest) {
                    throw new RuntimeException('The private folder belongs to another client.');
                }
                $disk->put($folder.'/identity.json', $manifest);
                $company->forceFill(['provisioning_status' => 'ready', 'tenancy_activated_at' => now()]);
                app(TenantContext::class)->run($company, function () use ($company): void {
                    ChartAccount::seedWorkspace($company->workspaceKey());
                    TaxCode::seedWorkspace($company->workspaceKey());
                });
                $company->save();
            });
        } catch (Throwable $exception) {
            Company::query()->whereKey($companyId)->whereNull('tenancy_activated_at')->update(['provisioning_status' => 'failed', 'provisioning_attempts' => DB::raw('provisioning_attempts + 1'), 'provisioning_error' => 'Client setup failed. Ask the administrator to review the server logs and retry.']);
            throw $exception;
        }
    }

    public function migrate(): void
    {
        $exit = Artisan::call('migrate', ['--database' => 'tenant', '--path' => database_path('migrations/clients'), '--realpath' => true, '--force' => true, '--no-interaction' => true]);
        if ($exit !== 0) {
            throw new RuntimeException('Client schema migration failed.');
        }
    }

    private function createDatabase(Company $company, string $driver): void
    {
        if ($driver === 'sqlite') {
            $path = database_path($company->tenancy_db_name);
            if (! file_exists($path)) {
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Cannot create the client database file.');
                }
                fclose($handle);
            }

            return;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Client databases require MySQL or MariaDB.');
        }
        $name = $company->tenancy_db_name;
        if (! preg_match('/^client_[a-f0-9]{40}$/D', $name)) {
            throw new RuntimeException('Invalid generated database identifier.');
        }
        $runtime = config('database.connections.client_template');
        $adminConfig = config('database.connections.'.config('tenancy.provisioner_connection'));
        if (! $runtime['username'] || ! $adminConfig['username'] || $runtime['username'] === $adminConfig['username']) {
            throw new RuntimeException('Configure separate runtime and provisioning database users.');
        }
        $admin = DB::connection(config('tenancy.provisioner_connection'));
        try {
            $admin->statement('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $username = $admin->getPdo()->quote($runtime['username']);
            $host = $admin->getPdo()->quote(config('tenancy.runtime_user_host'));
            $admin->statement('GRANT ALL PRIVILEGES ON `'.$name.'`.* TO '.$username.'@'.$host);
        } finally {
            DB::purge(config('tenancy.provisioner_connection'));
        }
    }
}
