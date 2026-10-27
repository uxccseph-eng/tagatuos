<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Services\CompanyLedgerService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyLedger> */
class CompanyLedgerFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'revision' => 0, 'state' => CompanyLedgerService::emptyState(), 'payroll' => []];
    }
}
