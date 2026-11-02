<?php

namespace App\Jobs;

use App\Services\ClientProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionClientDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $companyId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ClientProvisioner $provisioner): void
    {
        $provisioner->provision($this->companyId);
    }
}
