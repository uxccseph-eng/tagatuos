<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FirmOwnershipTransfer;
use App\Models\TeamEvent;
use App\Models\TeamLoginToken;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class FirmOwnershipService
{
    private function central(): Connection
    {
        return DB::connection(config('tenancy.database.central_connection'));
    }

    public function assertOwner(User $owner): void
    {
        abort_unless($owner->type === 'firm' && $owner->hasApprovedAccess() && ! $owner->must_change_password, 403);
    }

    /** @return array{owner: array{id: int, name: string}, books: array<mixed>, members: array<mixed>, assignments: array<mixed>} */
    public function scope(User $owner): array
    {
        $this->assertOwner($owner);
        $books = Company::query()->where(function ($query) use ($owner): void {
            $query->where('organization_owner_id', $owner->id)->orWhereHas('users', fn ($users) => $users->where('users.id', $owner->id)->where('company_user.role', 'owner'));
        })->orderBy('id')->get();
        $assignments = $this->central()->table('company_user')->whereIn('company_id', $books->modelKeys())->orderBy('company_id')->orderBy('user_id')->get();
        foreach ($books as $book) {
            $owners = $assignments->where('company_id', $book->id)->where('role', 'owner');
            $membership = $owners->firstWhere('user_id', $owner->id);
            if ($owners->count() !== 1 || ! $membership || $membership->is_suspended || ($book->organization_owner_id !== null && (int) $book->organization_owner_id !== $owner->id) || $book->deletionPending()) {
                $this->invalid('Resolve client ownership or pending deletion before transferring the firm.');
            }
        }

        return [
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
            'books' => $books->map(fn (Company $book): array => ['id' => $book->id, 'name' => $book->name, 'workspace_key' => $book->workspace_key, 'organization_owner_id' => $book->organization_owner_id])->all(),
            'members' => $this->central()->table('organization_members')->where('owner_id', $owner->id)->orderBy('user_id')->get()->map(fn ($row): array => (array) $row)->all(),
            'assignments' => $assignments->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /** @param array<string, mixed> $scope */
    public function scopeHash(array $scope): string
    {
        return hash_hmac('sha256', json_encode($scope, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function fingerprint(User $owner): string
    {
        return hash_hmac('sha256', $owner->password, config('app.key'));
    }

    /** @param array<string, mixed> $scope */
    private function assertRecipient(User $owner, User $target, array $scope): void
    {
        $ids = array_column($scope['books'], 'id');
        $membership = $this->central()->table('organization_members')->where('owner_id', $owner->id)->where('user_id', $target->id)->first();
        $eligibleType = $target->type === 'firm' || ($target->type === 'accountant' && $membership && ! $membership->is_suspended && in_array($membership->role, ['accountant', 'manager'], true));
        $outside = $target->companies()->whereNotIn('companies.id', $ids)->exists()
            || $this->central()->table('organization_members')->where('user_id', $target->id)->where('owner_id', '!=', $owner->id)->exists()
            || $this->central()->table('organization_members')->where('owner_id', $target->id)->exists()
            || Company::query()->where('organization_owner_id', $target->id)->exists();
        $suspended = $target->companies()->whereIn('companies.id', $ids)->wherePivot('is_suspended', true)->exists();
        if ($target->id === $owner->id || ! $eligibleType || ! $target->hasApprovedAccess() || $target->must_change_password || $outside || $suspended || ($membership && $membership->is_suspended)) {
            $this->invalid('Choose an approved firm account with no existing firm, or an active Accountant or Manager belonging only to this firm. Password setup must be complete.');
        }
    }

    public function request(User $actor, string $email, string $password, string $confirmation): FirmOwnershipTransfer
    {
        return $this->central()->transaction(function () use ($actor, $email, $password, $confirmation): FirmOwnershipTransfer {
            $owner = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->assertOwner($owner);
            $this->confirm($owner, $password, $confirmation, $owner->name);
            $scope = $this->scope($owner);
            if (empty($scope['books']) && empty($scope['members'])) {
                $this->invalid('Create a client book or team before transferring your firm.');
            }
            $target = User::query()->where('email', $email)->first();
            if (! $target) {
                $this->invalid('The recipient must already have an approved account.');
            }
            $this->assertRecipient($owner, $target, $scope);
            if (FirmOwnershipTransfer::query()->where('source_owner_id', $owner->id)->where('status', 'pending')->where('expires_at', '>', now())->exists()) {
                $this->invalid('Cancel the existing request before requesting another handover.');
            }
            $transfer = new FirmOwnershipTransfer;
            $transfer->forceFill(['source_owner_id' => $owner->id, 'target_owner_id' => $target->id, 'status' => 'pending', 'source_password_fingerprint' => $this->fingerprint($owner), 'scope_summary' => $scope, 'expires_at' => now()->addDays(7)])->save();

            return $transfer;
        }, 3);
    }

    public function accept(User $actor, int $transferId, string $password, string $confirmation, string $scopeHash): void
    {
        $reference = FirmOwnershipTransfer::query()->findOrFail($transferId);
        abort_unless($reference->target_owner_id === $actor->id, 403);
        $this->central()->transaction(function () use ($actor, $transferId, $reference, $password, $confirmation, $scopeHash): void {
            $owner = User::query()->lockForUpdate()->findOrFail($reference->source_owner_id);
            $target = User::query()->lockForUpdate()->findOrFail($actor->id);
            $transfer = FirmOwnershipTransfer::query()->lockForUpdate()->findOrFail($transferId);
            if ($transfer->status === 'accepted') {
                return;
            }
            $this->assertPending($transfer);
            if (! hash_equals($transfer->source_password_fingerprint, $this->fingerprint($owner))) {
                $this->invalid('The owner changed their password. Ask them to cancel this request and start a new handover.');
            }
            $scope = $this->scope($owner);
            $this->assertRecipient($owner, $target, $scope);
            $this->confirm($target, $password, $confirmation, $owner->name);
            if (! hash_equals($this->scopeHash($scope), $scopeHash)) {
                $this->invalid('The client books or team changed. Reload this review and confirm the latest details.');
            }
            $ids = array_column($scope['books'], 'id');
            foreach ($scope['books'] as $book) {
                Company::query()->whereKey($book['id'])->update(['organization_owner_id' => $target->id]);
                $target->companies()->syncWithoutDetaching([$book['id'] => ['role' => 'owner', 'is_suspended' => false, 'allowed_tabs' => null]]);
                $owner->companies()->detach($book['id']);
                TeamEvent::query()->create(['company_id' => $book['id'], 'actor_id' => $target->id, 'subject_id' => $owner->id, 'action' => 'Firm ownership transferred', 'details' => ['transfer_id' => $transfer->id, 'previous_owner_id' => $owner->id, 'new_owner_id' => $target->id]]);
            }
            $this->central()->table('organization_members')->where('owner_id', $owner->id)->where('user_id', $target->id)->delete();
            $this->central()->table('organization_members')->where('owner_id', $owner->id)->update(['owner_id' => $target->id, 'updated_at' => now()]);
            foreach (array_column($scope['members'], 'user_id') as $memberId) {
                $member = User::query()->find($memberId);
                if ($member && (int) $member->provisioned_by === $owner->id && ! $member->companies()->whereNotIn('companies.id', $ids)->exists() && ! $this->central()->table('organization_members')->where('user_id', $memberId)->where('owner_id', '!=', $target->id)->exists()) {
                    $member->forceFill(['provisioned_by' => $memberId === $target->id ? null : $target->id])->save();
                }
            }
            $target->forceFill(['type' => 'firm', 'provisioned_by' => (int) $target->provisioned_by === $owner->id ? null : $target->provisioned_by])->save();
            TeamLoginToken::query()->whereIn('company_id', $ids)->whereNull('used_at')->update(['used_at' => now()]);
            $transfer->forceFill(['status' => 'accepted', 'completed_at' => now(), 'scope_summary' => $scope])->save();
        }, 3);
    }

    public function close(User $actor, int $transferId): void
    {
        $transfer = FirmOwnershipTransfer::query()->findOrFail($transferId);
        abort_unless(in_array($actor->id, [$transfer->source_owner_id, $transfer->target_owner_id], true), 403);
        $this->central()->transaction(function () use ($actor, $transfer): void {
            User::query()->whereKey($transfer->source_owner_id)->lockForUpdate()->firstOrFail();
            $locked = FirmOwnershipTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertPending($locked);
            $locked->forceFill(['status' => $actor->id === $locked->source_owner_id ? 'cancelled' : 'declined', 'completed_at' => now()])->save();
        });
    }

    private function assertPending(FirmOwnershipTransfer $transfer): void
    {
        if ($transfer->status !== 'pending' || $transfer->expires_at->isPast()) {
            $this->invalid('This handover is no longer pending or has expired. Ask the firm owner to start a new request.');
        }
    }

    private function confirm(User $actor, string $password, string $confirmation, string $firmName): void
    {
        if (! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }
        if ($confirmation !== $firmName) {
            throw ValidationException::withMessages(['confirmation' => 'Type the firm name exactly as shown.']);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['ownership' => $message]);
    }
}
