<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use LogicException;
use Stancl\Tenancy\Tenancy;

class TenantContext
{
    public ?Company $company = null;

    public ?string $previewWorkspace = null;

    public bool $centralQueries = false;

    /** @return array{?Company, ?string, bool} */
    public function snapshot(): array
    {
        return [$this->company, $this->previewWorkspace, $this->centralQueries];
    }

    /** @param array{?Company, ?string, bool} $snapshot */
    public function restore(array $snapshot): void
    {
        [$this->company, $this->previewWorkspace, $this->centralQueries] = $snapshot;
    }

    public function run(Company $company, callable $callback): mixed
    {
        if (! config('tenancy.enabled')) {
            abort_if($company->tenancy_activated_at !== null, 503, 'Separate client databases require tenancy to be enabled.');
        } else {
            abort_unless($company->databaseReady(), 503, 'This client database is not ready. Check setup status from Company books.');
        }
        $previous = $this->snapshot();
        $tenancy = app(Tenancy::class);
        $previousTenant = $tenancy->initialized ? $tenancy->tenant : null;
        $switch = config('tenancy.enabled') && $previousTenant?->getTenantKey() !== $company->id;
        if ($switch && $previousTenant !== null && DB::connection('tenant')->transactionLevel() > 0) {
            throw new LogicException('Finish the current client transaction before switching databases.');
        }
        $this->restore([$company, null, false]);
        try {
            if ($switch) {
                $tenancy->initialize($company);
                $identity = DB::table('client_database_identity')->where('id', 1)->first();
                if (! $identity || (int) $identity->company_id !== $company->id || $identity->workspace_key !== $company->workspaceKey()) {
                    throw new LogicException('The client database identity does not match this company.');
                }
            }

            return $callback();
        } finally {
            try {
                if ($switch) {
                    $tenancy->end();
                    if ($previousTenant !== null) {
                        $tenancy->initialize($previousTenant);
                    }
                }
            } finally {
                $this->restore($previous);
            }
        }
    }

    public function cacheKey(string $key): string
    {
        if ($this->company === null) {
            throw new LogicException('A company context is required for company cache keys.');
        }

        return 'company:'.$this->company->id.':'.$key;
    }
}
