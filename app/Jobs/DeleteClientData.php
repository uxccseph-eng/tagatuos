<?php

namespace App\Jobs;

use App\Services\ClientDeletion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteClientData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $companyId, public int $ownerId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ClientDeletion $deletion): void
    {
        $deletion->purge($this->companyId, $this->ownerId);
    }
}
