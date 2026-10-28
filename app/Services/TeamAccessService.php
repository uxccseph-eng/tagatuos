<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TeamEvent;
use App\Models\TeamLoginToken;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamAccessService
{
    public function ownedCompany(User $owner, int $companyId): Company
    {
        abort_unless($owner->hasApprovedAccess(), 403, 'Your registration must be approved before managing team access.');

        return $owner->companies()->where('companies.id', $companyId)
            ->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->firstOrFail();
    }

    /** @param array{mode: string, name?: ?string, email: string, role: string, tabs: list<string>} $data
     * @return array{user: User, password: ?string, url: ?string}
     */
    public function add(User $owner, int $companyId, array $data): array
    {
        try {
            return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($owner, $companyId, $data): array {
                $company = $this->lockedCompany($owner, $companyId);
                $password = null;
                if ($data['mode'] === 'new') {
                    if (User::query()->where('email', $data['email'])->exists()) {
                        throw ValidationException::withMessages(['email' => 'This email already has an account. Use Add existing member instead.']);
                    }
                    $password = Str::password(length: 10, spaces: false);
                    $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $password]);
                    $user->type = 'accountant';
                    $user->must_change_password = true;
                    $user->registration_status = 'approved';
                    $user->is_system_owner = false;
                    $user->provisioned_by = $owner->id;
                    $user->save();
                } else {
                    $user = User::query()->where('email', $data['email'])->lockForUpdate()->first();
                    if (! $user || ! $user->hasApprovedAccess() || $user->type !== 'accountant' || $user->must_change_password) {
                        throw ValidationException::withMessages(['email' => 'Use an existing accountant account that has completed its password setup.']);
                    }
                }
                if ($company->users()->where('users.id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['email' => 'This person is already a member of this book. Edit their access instead.']);
                }
                $tabs = TeamPermissions::allowed($data['role'], $data['tabs']);
                if ((int) $company->organization_owner_id === (int) $owner->id) {
                    app(OrganizationTeamService::class)->save($owner, $user, $data);
                } else {
                    $company->users()->attach($user->id, ['role' => $data['role'], 'allowed_tabs' => json_encode($tabs, JSON_THROW_ON_ERROR)]);
                }
                $this->record($company, $owner, $user, $password === null ? 'Member added' : 'Accountant provisioned', ['name' => $user->name, 'role' => $data['role'], 'tabs' => $tabs, 'scope' => $this->isOrganizationMember($owner, $company, $user->id) ? 'organization' : 'book']);

                return ['user' => $user, 'password' => $password, 'url' => $password === null ? null : $this->newToken($company, $owner, $user)];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email or membership already exists. Refresh the member list before continuing.']);
        }
    }

    /** @param array{role: string, tabs: list<string>, is_suspended?: bool} $data */
    public function update(User $owner, int $companyId, int $userId, array $data): void
    {
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($owner, $companyId, $userId, $data): void {
            $company = $this->lockedCompany($owner, $companyId);
            $member = $this->editableMember($company, $userId);
            $tabs = TeamPermissions::allowed($data['role'], $data['tabs']);
            $suspended = (bool) ($data['is_suspended'] ?? false);
            $scope = $this->isOrganizationMember($owner, $company, $userId) ? 'organization' : 'book';
            $before = ['role' => $member->pivot->role, 'tabs' => TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), 'suspended' => (bool) $member->pivot->is_suspended];
            if ($this->isOrganizationMember($owner, $company, $userId)) {
                app(OrganizationTeamService::class)->save($owner, $member, $data);
            } else {
                $company->users()->updateExistingPivot($userId, ['role' => $data['role'], 'allowed_tabs' => json_encode($tabs, JSON_THROW_ON_ERROR), 'is_suspended' => $suspended]);
            }
            $this->revokeTokens($company, $userId);
            $this->record($company, $owner, $member, 'Access updated', ['name' => $member->name, 'scope' => $scope, 'before' => $before, 'after' => ['role' => $data['role'], 'tabs' => $tabs, 'suspended' => $suspended]]);
        }, attempts: 3);
    }

    public function remove(User $owner, int $companyId, int $userId): void
    {
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($owner, $companyId, $userId): void {
            $company = $this->lockedCompany($owner, $companyId);
            $member = $this->editableMember($company, $userId);
            $this->revokeTokens($company, $userId);
            $scope = $this->isOrganizationMember($owner, $company, $userId) ? 'organization' : 'book';
            if ($this->isOrganizationMember($owner, $company, $userId)) {
                app(OrganizationTeamService::class)->remove($owner, $member);
            } else {
                $company->users()->detach($userId);
            }
            $this->record($company, $owner, $member, 'Member removed', ['name' => $member->name, 'scope' => $scope, 'role' => $member->pivot->role]);
        }, attempts: 3);
    }

    public function setupLink(User $owner, int $companyId, int $userId): string
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($owner, $companyId, $userId): string {
            $company = $this->lockedCompany($owner, $companyId);
            $member = $this->editableMember($company, $userId);
            if ($member->pivot->is_suspended || ! $member->must_change_password || $member->type !== 'accountant' || ! app(OrganizationTeamService::class)->canSetUp($owner, $member)) {
                throw ValidationException::withMessages(['member' => 'Setup links are only available to the provisioning owner for active, newly provisioned accountants.']);
            }
            $url = $this->newToken($company, $owner, $member);
            $this->record($company, $owner, $member, 'Setup link issued', ['name' => $member->name]);

            return $url;
        }, attempts: 3);
    }

    public function resetPassword(User $owner, int $companyId, int $userId): string
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($owner, $companyId, $userId): string {
            $company = $this->lockedCompany($owner, $companyId);
            $membership = $this->editableMember($company, $userId);
            $member = User::query()->lockForUpdate()->findOrFail($membership->id);
            if ($membership->pivot->is_suspended || ! $member->hasApprovedAccess() || $member->type !== 'accountant'
                || $member->is_system_owner || $member->companies()->wherePivot('role', 'owner')->exists()
                || ! app(OrganizationTeamService::class)->canSetUp($owner, $member)) {
                throw ValidationException::withMessages(['member' => 'Password recovery is only available for active accountant accounts managed exclusively by your organization.']);
            }

            $member->forceFill([
                'password' => Str::password(length: 64, spaces: false),
                'remember_token' => Str::random(60),
                'must_change_password' => true,
            ])->save();
            TeamLoginToken::query()->where('user_id', $member->id)->whereNull('used_at')->update(['used_at' => now()]);
            $url = $this->newToken($company, $owner, $member);
            $this->record($company, $owner, $member, 'Password recovery issued', [
                'name' => $member->name,
                'scope' => $this->isOrganizationMember($owner, $company, $member->id) ? 'organization' : 'book',
            ]);

            return $url;
        }, attempts: 3);
    }

    public function changePortal(User $owner, int $companyId, string $slug): void
    {
        try {
            DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($owner, $companyId, $slug): void {
                $company = $this->lockedCompany($owner, $companyId);
                $old = $company->slug;
                $company->update(['slug' => $slug]);
                TeamLoginToken::query()->where('company_id', $company->id)->whereNull('used_at')->update(['used_at' => now()]);
                $this->record($company, $owner, null, 'Portal changed', ['before' => $old, 'after' => $slug]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'This sign-in address is already in use.']);
        }
    }

    private function lockedCompany(User $owner, int $companyId): Company
    {
        $owner = User::query()->lockForUpdate()->findOrFail($owner->id);
        $this->ownedCompany($owner, $companyId);
        app(OrganizationTeamService::class)->claimBooks($owner);
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);

        return $company;
    }

    private function isOrganizationMember(User $owner, Company $company, int $memberId): bool
    {
        return (int) $company->organization_owner_id === (int) $owner->id && app(OrganizationTeamService::class)->contains($owner, $memberId);
    }

    private function editableMember(Company $company, int $userId): User
    {
        $member = $company->users()->where('users.id', $userId)->firstOrFail();
        if ($member->pivot->role === 'owner') {
            throw ValidationException::withMessages(['member' => 'Owner memberships cannot be changed or removed here.']);
        }

        return $member;
    }

    private function newToken(Company $company, User $owner, User $member): string
    {
        if (! $company->slug) {
            $company->update(['slug' => 'book-'.$company->id.'-'.Str::lower(Str::random(8))]);
        }
        $this->revokeTokens($company, $member->id);
        if ($this->isOrganizationMember($owner, $company, $member->id)) {
            TeamLoginToken::query()->whereIn('company_id', app(OrganizationTeamService::class)->books($owner)->modelKeys())->where('user_id', $member->id)->whereNull('used_at')->update(['used_at' => now()]);
        }
        $token = TeamLoginToken::query()->create(['company_id' => $company->id, 'user_id' => $member->id, 'issued_by' => $owner->id, 'expires_at' => now()->addMinutes(15)]);

        return URL::temporarySignedRoute('portal.access', $token->expires_at, ['firmSlug' => $company->slug, 'tokenId' => $token->id]);
    }

    private function revokeTokens(Company $company, int $userId): void
    {
        TeamLoginToken::query()->where('company_id', $company->id)->where('user_id', $userId)->whereNull('used_at')->update(['used_at' => now()]);
    }

    /** @param array<string, mixed> $details */
    private function record(Company $company, User $actor, ?User $subject, string $action, array $details): void
    {
        $books = ($details['scope'] ?? null) === 'organization' ? app(OrganizationTeamService::class)->books($actor) : collect([$company]);
        foreach ($books as $book) {
            TeamEvent::query()->create(['company_id' => $book->id, 'actor_id' => $actor->id, 'subject_id' => $subject?->id, 'action' => $action, 'details' => $details]);
        }
    }
}
