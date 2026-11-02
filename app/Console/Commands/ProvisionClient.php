<?php

namespace App\Console\Commands;

use App\Services\ClientProvisioner;
use Illuminate\Console\Command;
use Throwable;

class ProvisionClient extends Command
{
    protected $signature = 'clients:provision {company : Central company ID}';

    protected $description = 'Create or safely retry a new client database and private folder';

    public function handle(ClientProvisioner $provisioner): int
    {
        try {
            $provisioner->provision((int) $this->argument('company'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Client setup failed. Review the application log before retrying.');

            return self::FAILURE;
        }
        $this->info('Client database and private folder are ready.');

        return self::SUCCESS;
    }
}
