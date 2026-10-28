<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class ClientDatabase
{
    public function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    public function run(Company|int $company, callable $callback): mixed
    {
        $company = Company::query()->findOrFail($company instanceof Company ? $company->id : $company);

        return app(TenantContext::class)->run($company, $callback);
    }

    public function configured(Company $company): bool
    {
        return $this->run($company, fn (): bool => CompanySetting::query()->exists());
    }
}
