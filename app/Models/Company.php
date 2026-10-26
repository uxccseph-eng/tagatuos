<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug'])]
#[Hidden(['workspace_key'])]
class Company extends Model
{
    public const ROLES = ['owner', 'accountant', 'staff'];

    public const RESERVED_SLUGS = ['account', 'api', 'books', 'company', 'dashboard', 'generate', 'lapsing', 'login', 'logout', 'masters', 'password', 'portal', 'preview', 'register', 'reports', 'settings', 'sheets', 'signin', 'signup', 'system', 'tax-reports', 'team', 'up'];

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
