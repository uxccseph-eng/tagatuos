<?php

namespace App\Console\Commands;

use App\Services\ClientDatabaseTransfer;
use Illuminate\Console\Command;

class SeparateClientDatabase extends Command
{
    protected $signature = 'clients:separate {company : Central company ID} {connection : Configured client_ database connection}';

    protected $description = 'Copy and verify one client accounting database, then activate its separate connection';

    public function handle(ClientDatabaseTransfer $transfer): int
    {
        try {
            $counts = $transfer->transfer((int) $this->argument('company'), (string) $this->argument('connection'));
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Transfer did not activate: '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->table(['Table', 'Verified records'], collect($counts)->map(fn (int $count, string $table): array => [$table, $count])->values()->all());
        $this->info('Client database activated. The original shared records were retained.');

        return self::SUCCESS;
    }
}
