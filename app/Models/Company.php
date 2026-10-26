<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug'])]
class Company extends Model
{
    public const ROLES = ['owner', 'accountant', 'staff'];

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public function workspaceKey(): string
    {
        return hash_hmac('sha256', 'company-book:'.$this->getKey(), config('app.key'));
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}
