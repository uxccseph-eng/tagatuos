<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TeamEvent;
use App\Models\TeamLoginToken;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationTeamService
{
    /** Books with multiple owners require an explicit organization assignment. */
    public function claimBooks(User $owner): void
    {
        if (! in_array($owner->type, ['firm', 'individual'], true)) {
            return;
        }
        $books = $owner->companies()->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->whereNull('organization_owner_id')->get();
        foreach ($books as $book) {
            if ($book->users()->wherePivot('role', 'owner')->count() === 1) {
                $book->forceFill(['organization_owner_id' => $owner->id])->save();
            }
        }
    }

    /** @return Collection<int, Company> */
    public function books(User $owner): Collection
    {
        return $owner->companies()->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->where('organization_owner_id', $owner->id)->orderBy('companies.id')->get();
    }

    public function contains(User $owner, int $userId): bool
    {
        return DB::connection(config('tenancy.database.central_connection'))->table('organization_members')->where('owner_id', $owner->id)->where('user_id', $userId)->exists();
    }

    /** Preserve the most restrictive existing module access when upgrading legacy teams. */
    public function upgradeExistingTeam(User $owner): void
    {
        $this->claimBooks($owner);
        $books = $this->books($owner);
        $memberships = DB::connection(config('tenancy.database.central_connection'))->table('company_user')->join('users', 'users.id', '=', 'company_user.user_id')
            ->whereIn('company_id', $books->modelKeys())->where('users.type', 'accountant')
            ->whereIn('role', TeamPermissions::MEMBER_ROLES)->select('company_user.*')->get()->groupBy('user_id');
        foreach ($memberships as $userId => $membershipsForUser) {
            if ($this->contains($owner, $userId)) {
                continue;
            }
            $member = User::query()->findOrFail($userId);
            if ($member->companies()->whereIn('companies.id', $books->modelKeys())->wherePivot('role', 'owner')->exists()) {
                continue;
            }
            $tabs = array_keys(TeamPermissions::TABS);
            foreach ($membershipsForUser as $membership) {
                $tabs = array_values(array_intersect($tabs, TeamPermissions::allowed($membership->role, $membership->allowed_tabs)));
            }
            $role = $membershipsForUser->contains('role', 'staff') ? 'staff' : ($membershipsForUser->contains('role', 'accountant') ? 'accountant' : 'manager');
            $this->save($owner, $member, ['role' => $role, 'tabs' => $tabs, 'is_suspended' => $membershipsForUser->contains(fn (object $membership): bool => (bool) $membership->is_suspended)]);
            $provisioners = TeamEvent::query()->where('subject_id', $userId)->where('action', 'Accountant provisioned')->distinct()->pluck('actor_id');
            if ($member->provisioned_by === null && $provisioners->count() === 1 && (int) $provisioners->first() === (int) $owner->id) {
                $member->forceFill(['provisioned_by' => $owner->id])->save();
            }
            foreach ($books as $book) {
                TeamEvent::query()->create(['company_id' => $book->id, 'actor_id' => $owner->id, 'subject_id' => $userId, 'action' => 'Organization access enabled', 'details' => ['name' => $member->name, 'scope' => 'organization']]);
            }
        }
    }

    /** @param array{role: string, tabs: list<string>, is_suspended?: bool} $data */
    public function save(User $owner, User $member, array $data): void
    {
        $attributes = ['role' => $data['role'], 'allowed_tabs' => json_encode(TeamPermissions::allowed($data['role'], $data['tabs']), JSON_THROW_ON_ERROR), 'is_suspended' => (bool) ($data['is_suspended'] ?? false), 'updated_at' => now()];
        if ($this->contains($owner, $member->id)) {
            DB::connection(config('tenancy.database.central_connection'))->table('organization_members')->where('owner_id', $owner->id)->where('user_id', $member->id)->update($attributes);
        } else {
            DB::connection(config('tenancy.database.central_connection'))->table('organization_members')->insert(['owner_id' => $owner->id, 'user_id' => $member->id, 'created_at' => now(), ...$attributes]);
        }
        foreach ($this->books($owner) as $book) {
            $this->attach($book, $member->id, $attributes);
        }
    }

    public function inherit(Company $company, User $owner): void
    {
        foreach (DB::connection(config('tenancy.database.central_connection'))->table('organization_members')->where('owner_id', $owner->id)->get() as $membership) {
            $this->attach($company, $membership->user_id, ['role' => $membership->role, 'allowed_tabs' => $membership->allowed_tabs, 'is_suspended' => (bool) $membership->is_suspended, 'updated_at' => now()]);
        }
    }

    public function remove(User $owner, User $member): void
    {
        foreach ($this->books($owner) as $book) {
            $this->assertNotOwner($book, $member->id);
            $book->users()->detach($member->id);
            $this->revoke($book, $member->id);
        }
        DB::connection(config('tenancy.database.central_connection'))->table('organization_members')->where('owner_id', $owner->id)->where('user_id', $member->id)->delete();
    }

    public function canSetUp(User $owner, User $member): bool
    {
        if ($member->provisioned_by === null) {
            return $member->companies()->count() === 1;
        }
        if ((int) $member->provisioned_by !== (int) $owner->id) {
            return false;
        }
        if (! $this->contains($owner, $member->id)) {
            return $member->companies()->count() === 1;
        }
        $bookIds = $this->books($owner)->modelKeys();

        return $bookIds !== [] && ! $member->companies()->whereNotIn('companies.id', $bookIds)->exists();
    }

    /** @param array{role: string, allowed_tabs: string, is_suspended: bool, updated_at: mixed} $attributes */
    private function attach(Company $book, int $memberId, array $attributes): void
    {
        $this->assertNotOwner($book, $memberId);
        $book->users()->syncWithoutDetaching([$memberId => $attributes]);
        $this->revoke($book, $memberId);
    }

    private function assertNotOwner(Company $book, int $memberId): void
    {
        if ($book->users()->where('users.id', $memberId)->wherePivot('role', 'owner')->exists()) {
            throw ValidationException::withMessages(['member' => 'Owner memberships cannot be changed through organization team access.']);
        }
    }

    private function revoke(Company $book, int $memberId): void
    {
        TeamLoginToken::query()->where('company_id', $book->id)->where('user_id', $memberId)->whereNull('used_at')->update(['used_at' => now()]);
    }
}
