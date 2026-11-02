<?php

namespace App\Services;

use App\Jobs\ProvisionClientDatabase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyBookService
{
    /** @return BelongsToMany<Company, User> */
    public function accessibleBooks(User $user): BelongsToMany
    {
        $this->ensureAccountIsReady($user);

        return $user->companies()->wherePivot('is_suspended', false)->orderBy('companies.name')->orderBy('companies.id');
    }

    public function findAccessibleBook(User $user, int $companyId): Company
    {
        return $this->accessibleBooks($user)->where('companies.id', $companyId)->firstOrFail();
    }

    public function createBook(User $user, string $name, ?string $slug = null): Company
    {
        try {
            return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($user, $name, $slug): Company {
                $owner = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $this->ensureAccountIsReady($owner);

                if (! in_array($owner->type, ['individual', 'firm'], true)) {
                    throw new AuthorizationException('Only individuals and firms can create company books.');
                }

                if ($owner->type === 'individual' && $owner->companies()->exists()) {
                    throw ValidationException::withMessages([
                        'name' => 'An individual account can only have one company book.',
                    ]);
                }

                $company = Company::query()->create(['name' => $name, 'slug' => $slug]);
                $company->forceFill(['organization_owner_id' => $owner->id])->save();
                $company->users()->attach($owner->getKey(), ['role' => 'owner']);
                app(OrganizationTeamService::class)->inherit($company, $owner);
                if (config('tenancy.enabled')) {
                    ProvisionClientDatabase::dispatch($company->id)->afterCommit();
                }

                return $this->findAccessibleBook($owner, $company->getKey());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($slug !== null && Company::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'This portal slug is already in use.']);
            }

            throw $exception;
        }
    }

    private function ensureAccountIsReady(User $user): void
    {
        if (! $user->hasApprovedAccess()) {
            throw new AuthorizationException('Your registration must be approved before accessing company books.');
        }
        if ($user->must_change_password) {
            throw new AuthorizationException('Change your temporary password before accessing company books.');
        }
    }
}
