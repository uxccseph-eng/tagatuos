<?php

namespace App\Services;

use App\Jobs\DeleteClientData;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stancl\Tenancy\Tenancy;
use Throwable;

class ClientDeletion
{
    public function authorize(User $user, Company $company): void
    {
        $membership = $user->companies()->whereKey($company->id)->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->exists();
        abort_unless($user->hasApprovedAccess() && ! $user->must_change_password && in_array($user->type, ['firm', 'individual'], true) && $membership && ($company->organization_owner_id === null || (int) $company->organization_owner_id === $user->id), 403, 'Only the firm or book owner can permanently delete this client.');
        abort_unless(config('tenancy.enabled'), 409, 'Permanent client database deletion requires the separate database version.');
    }

    public function queue(User $user, int $companyId, string $confirmedName): void
    {
        app(ClientDatabase::class)->central()->transaction(function () use ($user, $companyId, $confirmedName): void {
            $company = Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->authorize($user, $company);
            if ($company->name !== $confirmedName) {
                throw ValidationException::withMessages(['confirmation_name' => 'The client name changed. Reopen deletion and confirm its current name.']);
            }
            if ($company->provisioning_status === 'deleting') {
                return;
            }
            $this->databaseName($company);
            $company->forceFill(['provisioning_status' => 'deleting', 'provisioning_error' => null])->save();
            DeleteClientData::dispatch($company->id, $user->id)->afterCommit();
        });
    }

    public function purge(int $companyId, int $ownerId): void
    {
        if (app(Tenancy::class)->initialized || ! config('tenancy.enabled')) {
            throw new RuntimeException('Delete client databases only from a central background job.');
        }
        $central = app(ClientDatabase::class)->central();
        try {
            $central->transaction(function () use ($central, $companyId, $ownerId): void {
                $company = Company::query()->lockForUpdate()->find($companyId);
                if (! $company) {
                    return;
                }
                if (! $company->deletionPending()) {
                    throw new RuntimeException('Client deletion has not been requested.');
                }
                $this->authorize(User::query()->findOrFail($ownerId), $company);
                $name = $this->databaseName($company);
                $this->assertNoSharedAccountingData($company);
                $this->verifyFolder($company);
                $this->verifyDatabase($company, $name);
                $this->removeDatabase($name);
                $disk = Storage::disk('client_files');
                if ($disk->directoryExists($company->storageDirectory()) && ! $disk->deleteDirectory($company->storageDirectory())) {
                    throw new RuntimeException('The client folder could not be deleted. Retry deletion.');
                }
                foreach (['document_backup_events', 'inbox_document_events', 'client_follow_up_events', 'client_follow_ups', 'monthly_closing_events', 'monthly_closings', 'company_document_backups', 'inbox_documents', 'document_purge_batches', 'team_login_tokens', 'team_events', 'tax_preflight_reviews', 'company_user'] as $table) {
                    $central->table($table)->where('company_id', $company->id)->delete();
                }
                $company->delete();
                Log::notice('Client database and private folder deleted.', ['company_id' => $companyId, 'owner_id' => $ownerId]);
            });
        } catch (Throwable $exception) {
            Company::query()->whereKey($companyId)->whereIn('provisioning_status', ['deleting', 'delete_failed'])->update(['provisioning_status' => 'delete_failed', 'provisioning_error' => 'Client deletion needs attention. Review the server logs, then retry deletion.']);
            throw $exception;
        }
    }

    private function databaseName(Company $company): string
    {
        $driver = config('database.connections.client_template.driver');
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported client database driver.');
        }
        if ($driver === 'sqlite' && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('SQLite client deletion is restricted to local testing.');
        }
        $expected = 'client_'.substr($company->workspaceKey(), 0, 40).($driver === 'sqlite' ? '.sqlite' : '');
        if (($company->tenancy_db_name !== null && $company->tenancy_db_name !== $expected) || ($company->tenancy_db_connection !== null && $company->tenancy_db_connection !== 'client_template')) {
            throw new RuntimeException('The stored database does not match this client. No database was deleted.');
        }

        return $expected;
    }

    private function assertNoSharedAccountingData(Company $company): void
    {
        $central = app(ClientDatabase::class)->central();
        foreach (['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs', 'company_settings', 'company_ledgers', 'journal_entries', 'journal_entry_lines', 'company_ledger_changes', 'sheet_import_profiles', 'sheet_import_batches'] as $table) {
            $records = $central->table($table)->where('company_id', $company->id);
            if (in_array($table, ['chart_accounts', 'parties', 'tax_codes', 'bank_accounts', 'employees', 'ppe_assets', 'depreciation_runs', 'company_settings'], true)) {
                $records->orWhere('workspace_key', $company->workspaceKey());
            }
            if ($records->exists()) {
                throw new RuntimeException('Shared accounting records still exist for this client. Reconcile them before permanent deletion.');
            }
        }
    }

    private function verifyFolder(Company $company): void
    {
        $disk = Storage::disk('client_files');
        $folder = $company->storageDirectory();
        $root = realpath($disk->path(''));
        $path = $disk->path($folder);
        $resolved = realpath($path);
        if (! $root || is_link(dirname($path)) || is_link($path) || ($resolved !== false && $resolved !== $root.DIRECTORY_SEPARATOR.'clients'.DIRECTORY_SEPARATOR.$company->workspaceKey())) {
            throw new RuntimeException('The client folder is outside its expected private location.');
        }
        $documents = app(ClientDatabase::class)->central()->table('inbox_documents')->where('company_id', $company->id)->pluck('path');
        foreach ($documents as $document) {
            if (! str_starts_with($document, $folder.'/documents/') || str_contains($document, '..') || Storage::disk('documents')->path($folder) !== $path) {
                throw new RuntimeException('A client document is outside the private client folder. Reconcile it before deletion.');
            }
        }
        if ($disk->exists($folder.'/identity.json')) {
            $identity = json_decode($disk->get($folder.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIdentity($company, $identity);
        } elseif ($disk->allFiles($folder) !== []) {
            throw new RuntimeException('The client folder has files without an ownership identity.');
        }
    }

    /** @param array<string, mixed>|null $identity */
    private function assertIdentity(Company $company, ?array $identity): void
    {
        if (! $identity || (int) ($identity['company_id'] ?? 0) !== $company->id || ($identity['workspace_key'] ?? null) !== $company->workspaceKey()) {
            throw new RuntimeException('The database or folder belongs to another client. No data was deleted.');
        }
    }

    private function verifyDatabase(Company $company, string $name): bool
    {
        if (config('database.connections.client_template.driver') === 'sqlite') {
            $path = database_path($name);
            if (! file_exists($path)) {
                return false;
            }
            if (is_link($path) || realpath(dirname($path)) !== realpath(database_path())) {
                throw new RuntimeException('The SQLite database path is outside the client database directory.');
            }
            config(['database.connections.client_delete' => [...config('database.connections.sqlite'), 'url' => null, 'database' => $path]]);
            try {
                $connection = DB::connection('client_delete');
                $schema = $connection->getSchemaBuilder();
                $tables = array_column($schema->getTables($schema->getCurrentSchemaListing()), 'name');
                if ($tables !== []) {
                    if (! in_array('client_database_identity', $tables, true)) {
                        throw new RuntimeException('The database has tables without a client identity.');
                    }
                    $identity = $connection->table('client_database_identity')->where('id', 1)->first();
                    $this->assertIdentity($company, $identity ? (array) $identity : null);
                }
            } finally {
                DB::purge('client_delete');
                config(['database.connections.client_delete' => null]);
            }

            return true;
        }
        try {
            $admin = DB::connection(config('tenancy.provisioner_connection'));
            if (! $admin->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name])) {
                return false;
            }
            $tables = array_column($admin->select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$name]), 'name');
            if ($tables !== []) {
                if (! in_array('client_database_identity', $tables, true)) {
                    throw new RuntimeException('The database has tables without a client identity.');
                }
                $identity = $admin->table($name.'.client_database_identity')->where('id', 1)->first();
                $this->assertIdentity($company, $identity ? (array) $identity : null);
            }
        } finally {
            DB::purge(config('tenancy.provisioner_connection'));
        }

        return true;
    }

    private function removeDatabase(string $name): void
    {
        if (config('database.connections.client_template.driver') === 'sqlite') {
            DB::purge('tenant');
            foreach (['', '-wal', '-shm'] as $suffix) {
                $path = database_path($name.$suffix);
                if (is_link($path) || (file_exists($path) && realpath(dirname($path)) !== realpath(database_path()))) {
                    throw new RuntimeException('An unexpected SQLite database path was found.');
                }
                if (is_file($path) && ! unlink($path)) {
                    throw new RuntimeException('The client database file could not be deleted.');
                }
            }

            return;
        }
        try {
            $admin = DB::connection(config('tenancy.provisioner_connection'));
            $admin->statement('DROP DATABASE IF EXISTS `'.$name.'`');
            $runtime = $admin->getPdo()->quote(config('database.connections.client_template.username'));
            $host = $admin->getPdo()->quote(config('tenancy.runtime_user_host'));
            try {
                $admin->statement('REVOKE ALL PRIVILEGES ON `'.str_replace('_', '\\_', $name).'`.* FROM '.$runtime.'@'.$host);
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1141) {
                    throw $exception;
                }
            }
        } finally {
            DB::purge(config('tenancy.provisioner_connection'));
        }
    }
}
