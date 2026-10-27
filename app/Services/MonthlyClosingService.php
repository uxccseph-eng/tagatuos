<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MonthlyClosing;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyClosingService
{
    public function __construct(private CompanyBookService $books) {}

    public function company(User $user, int $companyId): Company
    {
        $company = $this->books->findAccessibleBook($user, $companyId);
        abort_unless(array_intersect(['sheets', 'books', 'reports'], TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs)) !== [], 403, 'Your book owner has not enabled closing review access for your account.');

        return $company;
    }

    public function canEdit(Company $company, User $user, ?MonthlyClosing $closing): bool
    {
        return TeamPermissions::canApproveClosing($company->pivot->role) || ((int) $closing?->assignee_id === (int) $user->id && in_array('sheets', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true));
    }

    /** @param array{version: int, action: string, assignee_id?: ?int, status?: string, checklist?: array<string, bool>, notes?: ?string, reason?: ?string} $data */
    public function save(User $actor, int $companyId, string $month, array $data): MonthlyClosing
    {
        return DB::transaction(function () use ($actor, $companyId, $month, $data): MonthlyClosing {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $actor = User::query()->findOrFail($actor->id);
            $company = $this->company($actor, $companyId);
            $closing = MonthlyClosing::query()->where('company_id', $companyId)->where('month', $month)->lockForUpdate()->first();
            abort_unless($this->canEdit($company, $actor, $closing), 403, 'Only the assigned accountant, manager or book owner can update this closing.');
            abort_unless((int) $data['version'] === ($closing?->version ?? 0), 409, 'This closing changed in another session. Reload the closing before saving again.');
            if (! CompanySetting::query()->where('workspace_key', $company->workspaceKey())->exists()) {
                throw ValidationException::withMessages(['status' => 'Complete company setup before starting monthly closing.']);
            }
            $closing ??= (new MonthlyClosing)->forceFill(['company_id' => $companyId, 'month' => $month, 'status' => 'not_started', 'checklist' => MonthlyClosing::emptyChecklist(), 'version' => 0]);
            $before = $this->snapshot($closing);
            $reviewer = TeamPermissions::canApproveClosing($company->pivot->role);
            if ($data['action'] === 'reopen') {
                abort_unless($reviewer && $closing->status === 'completed', 403, 'Only a manager or book owner can reopen a completed closing.');
                $closing->forceFill(['status' => 'in_progress', 'completed_by' => null, 'completed_at' => null]);
            } else {
                abort_if($closing->status === 'completed', 422, 'This month is completed. A manager or book owner must reopen it before making changes.');
                $assigneeId = isset($data['assignee_id']) ? (int) $data['assignee_id'] : null;
                abort_if(! $reviewer && $assigneeId !== (int) $closing->assignee_id, 403, 'Only a manager or book owner can change the assigned accountant.');
                if ($assigneeId !== null) {
                    $assignee = $company->users()->where('users.id', $assigneeId)->where('registration_status', 'approved')->where('must_change_password', false)->wherePivot('is_suspended', false)->first();
                    if (! $assignee || ! in_array('sheets', TeamPermissions::allowed($assignee->pivot->role, $assignee->pivot->allowed_tabs), true)) {
                        throw ValidationException::withMessages(['assignee_id' => 'Choose an active member of this book with Sheets access and completed password setup.']);
                    }
                }
                $checklist = array_map(fn (mixed $value): bool => (bool) $value, $data['checklist']);
                $status = $data['status'];
                $allowed = match ($closing->status) {
                    'not_started' => ['not_started', 'in_progress'],
                    'in_progress' => ['not_started', 'in_progress', 'for_review'],
                    'for_review' => ['in_progress', 'for_review', 'completed'],
                    default => [],
                };
                if (! in_array($status, $allowed, true)) {
                    throw ValidationException::withMessages(['status' => 'Move through In progress and For review before completing a month.']);
                }
                if ($status === 'completed' && ! $reviewer) {
                    abort(403, 'Only a manager or book owner can approve completion.');
                }
                if (in_array($status, ['for_review', 'completed'], true) && ($assigneeId === null || in_array(false, $checklist, true))) {
                    throw ValidationException::withMessages(['status' => 'Assign an accountant and complete every checklist item before submitting for review.']);
                }
                if ($status === 'not_started' && in_array(true, $checklist, true)) {
                    throw ValidationException::withMessages(['status' => 'Choose In progress when checklist work has started.']);
                }
                if ($closing->status === 'for_review' && $status !== 'in_progress' && ($checklist !== $closing->checklist || $assigneeId !== (int) $closing->assignee_id || ($data['notes'] ?? null) !== $closing->notes)) {
                    throw ValidationException::withMessages(['status' => 'Return this month to In progress before changing submitted work.']);
                }
                $closing->forceFill(['assignee_id' => $assigneeId, 'status' => $status, 'checklist' => $checklist, 'notes' => $data['notes'] ?? null]);
                if ($status === 'completed') {
                    $closing->forceFill(['completed_by' => $actor->id, 'completed_at' => now()]);
                }
            }
            $closing->version++;
            $closing->save();
            DB::table('monthly_closing_events')->insert(['monthly_closing_id' => $closing->id, 'company_id' => $companyId, 'actor_id' => $actor->id, 'action' => $data['action'] === 'reopen' ? 'reopened' : $closing->status, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($this->snapshot($closing), JSON_THROW_ON_ERROR), 'reason' => $data['action'] === 'reopen' ? $data['reason'] : null, 'created_at' => now()]);

            return $closing;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(MonthlyClosing $closing): array
    {
        return $closing->only(['assignee_id', 'status', 'checklist', 'notes', 'version', 'completed_by', 'completed_at']);
    }
}
