<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
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
        $previous = tenancy()->tenant;
        if ($previous !== null && $previous->getTenantKey() === $company->getTenantKey()) {
            return $callback();
        }
        if ($previous === null && $company->tenancy_activated_at === null) {
            return $callback();
        }
        if ($previous !== null && DB::connection('tenant')->transactionLevel() > 0) {
            throw new \LogicException('Finish the current client transaction before switching client databases.');
        }
        try {
            if ($company->tenancy_activated_at !== null) {
                $template = $company->getInternal('db_connection');
                abort_unless(is_string($template) && str_starts_with($template, 'client_') && is_array(config('database.connections.'.$template)), 503, 'This client database is unavailable. Contact the firm owner.');
                tenancy()->initialize($company);
                $identity = DB::table('client_database_identity')->where('id', 1)->first();
                abort_unless($identity && (int) $identity->company_id === $company->id && $identity->workspace_key === $company->workspaceKey(), 503, 'This database does not belong to the selected client. Contact the firm owner.');
            } else {
                tenancy()->end();
            }

            return $callback();
        } catch (QueryException|\PDOException $exception) {
            report($exception);
            abort(503, 'This client database is unavailable. Contact the firm owner.');
        } finally {
            tenancy()->end();
            if ($previous !== null) {
                tenancy()->initialize($previous);
            }
        }
    }

    public function configured(Company $company): bool
    {
        return $this->run($company, fn (): bool => CompanySetting::query()->where('workspace_key', $company->workspaceKey())->exists());
    }
}
