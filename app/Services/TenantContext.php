<?php

namespace App\Services;

use App\Models\Company;
use LogicException;

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
        abort_if($company->tenancy_activated_at !== null, 503, 'This book has a separate database. Reconcile its records before enabling shared-database access.');
        $previous = $this->snapshot();
        $this->restore([$company, null, false]);
        try {
            return $callback();
        } finally {
            $this->restore($previous);
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
