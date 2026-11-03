<?php

namespace App\Models;

use App\Services\ClientDatabase;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasInternalKeys;

#[Fillable(['name', 'slug'])]
#[Hidden(['workspace_key', 'tenancy_db_connection', 'tenancy_db_name', 'tenancy_activated_at'])]
class Company extends Model implements TenantWithDatabase
{
    use HasDatabase;
    use HasInternalKeys;
    use UsesCentralDatabase;

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey(): int
    {
        return (int) $this->getKey();
    }

    public function storageDirectory(): string
    {
        return 'clients/'.$this->workspaceKey();
    }

    public function databaseReady(): bool
    {
        return $this->provisioning_status === 'ready' && $this->tenancy_activated_at !== null;
    }

    public function deletionPending(): bool
    {
        return in_array($this->provisioning_status, ['deleting', 'delete_failed'], true);
    }

    public function run(callable $callback): mixed
    {
        return app(ClientDatabase::class)->run($this, fn (): mixed => $callback($this));
    }

    public const ROLES = ['owner', 'manager', 'accountant', 'staff'];

    public const RESERVED_SLUGS = ['account', 'api', 'books', 'company', 'dashboard', 'documents', 'generate', 'lapsing', 'login', 'logout', 'masters', 'my-work', 'password', 'portal', 'preview', 'register', 'reports', 'settings', 'sheets', 'signin', 'signup', 'system', 'tax-reports', 'tax-returns', 'team', 'up'];

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public function workspaceKey(): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', (string) $this->workspace_key)) {
            throw new \LogicException('This company does not have a permanent data scope. Run the company migrations.');
        }

        return $this->workspace_key;
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            $company->workspace_key = bin2hex(random_bytes(32));
        });
        static::updating(function (Company $company): void {
            if ($company->isDirty('workspace_key')) {
                throw new \LogicException('A company data scope cannot be changed.');
            }
        });
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'is_suspended', 'allowed_tabs')->withTimestamps();
    }
}
