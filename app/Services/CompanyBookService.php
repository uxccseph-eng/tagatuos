<?php

namespace App\Services;

use App\Jobs\ProvisionClientDatabase;
use App\Models\Company;
use App\Models\TeamEvent;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
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

    public function createBook(User $user, string $name, ?string $slug = null, ?int $firmId = null): Company
    {
        try {
            return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($user, $name, $slug, $firmId): Company {
                $owner = $user->type === 'accountant'
                    ? User::query()->findOrFail($user->getKey())
                    : User::query()->lockForUpdate()->findOrFail($user->getKey());
                if ($owner->type !== $user->type) {
                    throw new AuthorizationException('Your account changed. Sign in again before creating clients.');
                }
                $this->ensureAccountIsReady($owner);

                $actor = $owner;
                if ($actor->type === 'accountant') {
                    $firms = $this->creationFirms($actor);
                    if ($firms->isEmpty()) {
                        throw new AuthorizationException('Your firm must enable Accountant or Manager access with Settings before you can create clients.');
                    }
                    if ($firmId === null && $firms->count() !== 1) {
                        throw ValidationException::withMessages(['firm_id' => 'Choose the firm this client belongs to.']);
                    }
                    $firmId ??= $firms->first()->id;
                    if (! $firms->contains('id', $firmId)) {
                        throw new AuthorizationException('You cannot create clients for this firm.');
                    }
                    $owner = User::query()->lockForUpdate()->findOrFail($firmId);
                    $this->ensureAccountIsReady($owner);
                    $actor = User::query()->sharedLock()->findOrFail($actor->id);
                    $this->ensureAccountIsReady($actor);
                    if ($actor->type !== 'accountant') {
                        throw new AuthorizationException('Your account changed. Reopen Company books before creating a client.');
                    }
                    $membership = DB::connection(config('tenancy.database.central_connection'))->table('organization_members')->where('owner_id', $owner->id)->where('user_id', $actor->id)->lockForUpdate()->first();
                    if ($owner->type !== 'firm' || ! $membership || $membership->is_suspended || ! in_array($membership->role, ['accountant', 'manager'], true) || ! TeamPermissions::canEditBookSettings($membership->role, $membership->allowed_tabs)) {
                        throw new AuthorizationException('Your firm access changed. Reopen Company books before creating a client.');
                    }
                } elseif (! in_array($owner->type, ['individual', 'firm'], true) || ($firmId !== null && $firmId !== $owner->id)) {
                    throw new AuthorizationException('You cannot create company books for another owner.');
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

                if ($actor->id !== $owner->id) {
                    TeamEvent::query()->create(['company_id' => $company->id, 'actor_id' => $actor->id, 'subject_id' => $actor->id, 'action' => 'Client book created', 'details' => ['name' => $company->name, 'organization_owner_id' => $owner->id]]);
                }

                return $this->findAccessibleBook($actor, $company->getKey());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($slug !== null && Company::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'This portal slug is already in use.']);
            }

            throw $exception;
        }
    }

    /** @return Collection<int, User> */
    public function creationFirms(User $user): Collection
    {
        if ($user->type !== 'accountant' || ! $user->hasApprovedAccess() || $user->must_change_password) {
            return new Collection;
        }

        return User::query()->join('organization_members as membership', 'membership.owner_id', '=', 'users.id')
            ->where('membership.user_id', $user->id)->where('membership.is_suspended', false)->whereIn('membership.role', ['accountant', 'manager'])
            ->where('users.type', 'firm')->where('users.registration_status', 'approved')->where('users.must_change_password', false)
            ->select('users.*', 'membership.role as creation_role', 'membership.allowed_tabs as creation_tabs')->orderBy('users.name')->get()
            ->filter(fn (User $firm): bool => TeamPermissions::canEditBookSettings($firm->creation_role, $firm->creation_tabs))->values();
    }

    public function canRetrySetup(User $user, Company $company): bool
    {
        return $company->pivot->role === 'owner' || ($user->type === 'accountant'
            && in_array($company->pivot->role, ['accountant', 'manager'], true)
            && TeamPermissions::canEditBookSettings($company->pivot->role, $company->pivot->allowed_tabs)
            && $this->creationFirms($user)->contains('id', $company->organization_owner_id));
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
