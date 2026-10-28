<?php

namespace App\Services;

use RuntimeException;

class ClientDatabaseTransfer
{
    /** @return array<string, int> */
    public function transfer(int $companyId, string $connection): array
    {
        throw new RuntimeException('Separate database activation is disabled. This application uses company-scoped records in one database.');
    }

    public function migrate(string $connection): void
    {
        throw new RuntimeException('Separate database migrations are disabled. Run the primary application migrations.');
    }
}
