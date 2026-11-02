<?php

namespace App\Services;

use RuntimeException;

class ClientDatabaseTransfer
{
    /** @return array<string, int> */
    public function transfer(int $companyId, string $connection): array
    {
        throw new RuntimeException('Separate database activation is disabled for manual connections. Use automatic client provisioning. Existing shared records require a verified transfer.');
    }

    public function migrate(string $connection): void
    {
        throw new RuntimeException('Use clients:migrate to apply migrations to provisioned client databases.');
    }
}
