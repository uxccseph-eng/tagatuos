<?php

namespace App\Services;

use App\Models\RegistrationReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationApprovalService
{
    public function approve(User $actor, int $applicantId, string $username): void
    {
        $this->withUniqueUsername(function () use ($actor, $applicantId, $username): void {
            $this->ensureSystemOwner($actor);
            $applicant = $this->applicant($applicantId);
            if (! in_array($applicant->registration_status, ['pending', 'rejected'], true)) {
                throw ValidationException::withMessages(['registration' => 'This registration has already been approved. Refresh the dashboard.']);
            }
            $username = $this->validateUsername($username);
            $previous = $applicant->registration_status;
            $applicant->forceFill(['registration_status' => 'approved', 'username' => $username, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'rejection_reason' => null])->save();
            $this->record($actor, $applicant, 'approved', $previous);
        });
    }

    public function reject(User $actor, int $applicantId, string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate();
        DB::transaction(function () use ($actor, $applicantId, $reason): void {
            $this->ensureSystemOwner($actor);
            $applicant = $this->applicant($applicantId);
            if ($applicant->registration_status !== 'pending') {
                throw ValidationException::withMessages(['registration' => 'Only a pending registration can be rejected. Refresh the dashboard.']);
            }
            $applicant->forceFill(['registration_status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'rejection_reason' => trim($reason)])->save();
            $this->record($actor, $applicant, 'rejected', 'pending', trim($reason));
        }, attempts: 3);
    }

    public function changeUsername(User $actor, int $applicantId, string $username): void
    {
        $this->withUniqueUsername(function () use ($actor, $applicantId, $username): void {
            $this->ensureSystemOwner($actor);
            $applicant = $this->applicant($applicantId);
            if (! $applicant->hasApprovedAccess()) {
                throw ValidationException::withMessages(['registration' => 'Approve the registration before changing its username.']);
            }
            $previousUsername = $applicant->username;
            $applicant->forceFill(['username' => $this->validateUsername($username)])->save();
            $this->record($actor, $applicant, 'username_changed', 'approved', $previousUsername === null ? 'Username assigned' : 'Previous username: '.$previousUsername);
        });
    }

    public function appointOwner(string $email, ?string $username = null): User
    {
        return DB::transaction(function () use ($email, $username): User {
            $user = User::query()->where('email', mb_strtolower(trim($email)))->lockForUpdate()->first();
            if (! $user || ! in_array($user->type, ['individual', 'firm'], true)) {
                throw ValidationException::withMessages(['email' => 'Use the exact email of an existing individual or firm account.']);
            }
            if (User::query()->where('is_system_owner', true)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['owner' => 'A different system owner is already configured. This command cannot replace them.']);
            }
            if ($user->is_system_owner && $username === null) {
                return $user;
            }
            if ($username !== null) {
                $username = $this->validateUsername($username, true);
                Validator::make(['username' => $username], ['username' => [Rule::unique('users', 'username')->ignore($user->id)]])->validate();
            }
            $previous = $user->registration_status;
            $user->forceFill(['is_system_owner' => true, 'registration_status' => 'approved', 'username' => $username ?? $user->username, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => null])->save();
            $this->record($user, $user, 'owner_appointed', $previous);

            return $user;
        }, attempts: 3);
    }

    private function ensureSystemOwner(User $actor): void
    {
        $authorized = User::query()->whereKey($actor->id)->where('is_system_owner', true)->where('registration_status', 'approved')->exists();
        if (! $authorized) {
            throw new AuthorizationException('Only the system owner can review registrations.');
        }
    }

    private function applicant(int $id): User
    {
        return User::query()->whereKey($id)->whereIn('type', ['individual', 'firm'])->where('is_system_owner', false)->lockForUpdate()->firstOrFail();
    }

    private function validateUsername(string $username, bool $allowReserved = false): string
    {
        $username = strtolower(trim($username));
        $rules = ['required', 'string', 'max:64', 'regex:'.User::USERNAME_PATTERN];
        if (! $allowReserved) {
            $rules[] = Rule::notIn(User::RESERVED_USERNAMES);
        }
        Validator::make(['username' => $username], ['username' => $rules])->validate();

        return $username;
    }

    private function withUniqueUsername(\Closure $change): void
    {
        try {
            DB::transaction($change, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => 'This username is already assigned. Choose another one.']);
        }
    }

    private function record(User $actor, User $applicant, string $action, string $previous, ?string $reason = null): void
    {
        RegistrationReview::query()->create(['actor_id' => $actor->id, 'applicant_id' => $applicant->id, 'action' => $action, 'previous_status' => $previous, 'username' => $applicant->username, 'reason' => $reason]);
    }
}
