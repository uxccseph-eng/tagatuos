<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ClientDatabase;
use App\Services\ClientProvisioner;
use Illuminate\Console\Command;

class MigrateClientDatabases extends Command
{
    protected $signature = 'clients:migrate {--company= : Migrate only this company ID}';

    protected $description = 'Apply accounting schema migrations to activated client databases';

    public function handle(ClientProvisioner $provisioner, ClientDatabase $databases): int
    {
        $failed = false;
        foreach (Company::query()->where('provisioning_status', 'ready')->whereNotNull('tenancy_activated_at')->when($this->option('company'), fn ($query) => $query->whereKey($this->option('company')))->orderBy('id')->cursor() as $company) {
            try {
                $databases->central()->transaction(function () use ($company, $provisioner): void {
                    Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $company->run(fn () => $provisioner->migrate());
                });
            } catch (\Throwable $exception) {
                report($exception);
                $this->error('Client '.$company->id.' migration failed: '.$exception->getMessage());

                $failed = true;

                continue;
            }
            $this->info('Client '.$company->id.' schema is current.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
