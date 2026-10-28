<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ClientDatabase;
use App\Services\ClientDatabaseTransfer;
use Illuminate\Console\Command;

class MigrateClientDatabases extends Command
{
    protected $signature = 'clients:migrate {--company= : Migrate only this company ID}';

    protected $description = 'Apply accounting schema migrations to activated client databases';

    public function handle(ClientDatabaseTransfer $transfer, ClientDatabase $databases): int
    {
        foreach (Company::query()->whereNotNull('tenancy_activated_at')->when($this->option('company'), fn ($query) => $query->whereKey($this->option('company')))->orderBy('id')->get() as $company) {
            try {
                $databases->central()->transaction(function () use ($company, $transfer): void {
                    Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $company->run(fn () => $transfer->migrate($company->tenancy_db_connection));
                });
            } catch (\Throwable $exception) {
                report($exception);
                $this->error('Client '.$company->id.' migration failed: '.$exception->getMessage());

                return self::FAILURE;
            }
            $this->info('Client '.$company->id.' schema is current.');
        }

        return self::SUCCESS;
    }
}
