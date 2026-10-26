<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const TYPES = ['individual', 'firm', 'accountant'];

    public const USERNAME_PATTERN = '/^[a-z][a-z0-9._-]{2,63}$/D';

    public const RESERVED_USERNAMES = ['admin', 'administrator', 'owner', 'root', 'support', 'system'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function hasApprovedAccess(): bool
    {
        return $this->registration_status === 'approved';
    }

    public function accountHomeRoute(): string
    {
        return match (true) {
            ! $this->hasApprovedAccess() => 'account.registration-status',
            $this->must_change_password => 'account.password.edit',
            $this->is_system_owner => 'system.dashboard',
            default => 'account.dashboard',
        };
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role', 'is_suspended', 'allowed_tabs')->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'is_system_owner' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
