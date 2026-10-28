<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class ListClientDatabases extends Command
{
    protected $signature = 'clients:list';

    protected $description = 'List client book IDs and database activation status without credentials';

    public function handle(): int
    {
        $this->table(['Client ID', 'Client company', 'Firm owner ID', 'Database status'], Company::query()->orderBy('id')->get()->map(fn (Company $company): array => [$company->id, $company->name, $company->organization_owner_id, $company->tenancy_activated_at ? 'Needs reconciliation' : 'Company-scoped database'])->all());

        return self::SUCCESS;
    }
}
