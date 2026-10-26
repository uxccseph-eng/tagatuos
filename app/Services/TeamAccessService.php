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
            return DB::transaction(function () use ($owner, $companyId, $data): array {
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
                $company->users()->attach($user->id, ['role' => $data['role'], 'allowed_tabs' => json_encode($tabs, JSON_THROW_ON_ERROR)]);
                $this->record($company, $owner, $user, $password === null ? 'Member added' : 'Accountant provisioned', ['name' => $user->name, 'role' => $data['role'], 'tabs' => $tabs]);

                return ['user' => $user, 'password' => $password, 'url' => $password === null ? null : $this->newToken($company, $owner, $user)];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email or membership already exists. Refresh the member list before continuing.']);
        }
    }

    /** @param array{role: string, tabs: list<string>, is_suspended?: bool} $data */
    public function update(User $owner, int $companyId, int $userId, array $data): void
    {
        DB::transaction(function () use ($owner, $companyId, $userId, $data): void {
            $company = $this->lockedCompany($owner, $companyId);
            $member = $this->editableMember($company, $userId);
            $tabs = TeamPermissions::allowed($data['role'], $data['tabs']);
            $suspended = (bool) ($data['is_suspended'] ?? false);
            $before = ['role' => $member->pivot->role, 'tabs' => TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), 'suspended' => (bool) $member->pivot->is_suspended];
            $company->users()->updateExistingPivot($userId, ['role' => $data['role'], 'allowed_tabs' => json_encode($tabs, JSON_THROW_ON_ERROR), 'is_suspended' => $suspended]);
            $this->revokeTokens($company, $userId);
            $this->record($company, $owner, $member, 'Access updated', ['name' => $member->name, 'before' => $before, 'after' => ['role' => $data['role'], 'tabs' => $tabs, 'suspended' => $suspended]]);
        }, attempts: 3);
    }

    public function remove(User $owner, int $companyId, int $userId): void
    {
        DB::transaction(function () use ($owner, $companyId, $userId): void {
            $company = $this->lockedCompany($owner, $companyId);
            $member = $this->editableMember($company, $userId);
            $this->revokeTokens($company, $userId);
            $company->users()->detach($userId);
            $this->record($company, $owner, $member, 'Member removed', ['name' => $member->name, 'role' => $member->pivot->role]);
        }, attempts: 3);
    }

    public function setupLink(User $owner, int $companyId, int $userId): string
    {
        return DB::transaction(function () use ($owner, $companyId, $userId): string {
            $company = $this->lockedCompany($owner, $companyId);
            $member = $this->editableMember($company, $userId);
            if ($member->pivot->is_suspended || ! $member->must_change_password || $member->type !== 'accountant' || $member->companies()->count() !== 1) {
                throw ValidationException::withMessages(['member' => 'Setup links are only available for active, newly provisioned accountants with one assigned book.']);
            }
            $url = $this->newToken($company, $owner, $member);
            $this->record($company, $owner, $member, 'Setup link issued', ['name' => $member->name]);

            return $url;
        }, attempts: 3);
    }

    public function changePortal(User $owner, int $companyId, string $slug): void
    {
        try {
            DB::transaction(function () use ($owner, $companyId, $slug): void {
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
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);
        $this->ownedCompany($owner, $companyId);

        return $company;
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
        TeamEvent::query()->create(['company_id' => $company->id, 'actor_id' => $actor->id, 'subject_id' => $subject?->id, 'action' => $action, 'details' => $details]);
    }
}
