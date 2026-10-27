<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\MonthlyClosing;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_board_and_months_does_not_create_any_records(): void
    {
        [$owner, $book] = $this->book();
        $this->actingAs($owner)->get(route('my-work', ['month' => '2026-10']))->assertOk()->assertViewHas('closingCounts', ['not_started' => 1, 'in_progress' => 0, 'for_review' => 0, 'completed' => 0])->assertSee('Open checklist');
        foreach (['2026-10', '2026-11'] as $month) {
            $this->get(route('closing.show', ['companyId' => $book->id, 'month' => $month]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('No changes recorded yet');
        }
        $this->assertDatabaseCount('monthly_closings', 0);
        $this->assertDatabaseCount('monthly_closing_events', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_assigned_accountant_submits_and_owner_approves_with_audited_reopening(): void
    {
        [$owner, $book, $accountant] = $this->book();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($accountant))->assertRedirect();
        $this->assertDatabaseHas('monthly_closings', ['company_id' => $book->id, 'assignee_id' => $accountant->id, 'status' => 'in_progress', 'version' => 1]);
        $this->actingAs($accountant)->putJson($this->url($book), $this->data($accountant, 1, 'for_review', true))->assertRedirect();
        $this->putJson($this->url($book), $this->data($accountant, 2, 'completed', true))->assertForbidden();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($accountant, 2, 'completed', true))->assertRedirect();
        $closing = MonthlyClosing::query()->sole();
        $this->assertSame($owner->id, $closing->completed_by);
        $this->assertNotNull($closing->completed_at);
        $this->get(route('closing.show', ['companyId' => $book->id, 'month' => '2026-10']))->assertOk()->assertSee('Completed by')->assertSee('Reopen this month');
        $this->putJson($this->url($book), $this->data($accountant, 3, 'in_progress', true))->assertUnprocessable();
        $this->putJson($this->url($book), ['version' => 3, 'action' => 'reopen', 'reason' => ''])->assertUnprocessable();
        $this->putJson($this->url($book), ['version' => 3, 'action' => 'reopen', 'reason' => 'Additional source document received'])->assertRedirect();
        $this->assertDatabaseHas('monthly_closings', ['company_id' => $book->id, 'status' => 'in_progress', 'version' => 4, 'completed_by' => null, 'completed_at' => null]);
        $this->assertDatabaseHas('monthly_closing_events', ['company_id' => $book->id, 'actor_id' => $owner->id, 'action' => 'reopened', 'reason' => 'Additional source document received']);
        $this->assertDatabaseCount('monthly_closing_events', 4);
    }

    public function test_incomplete_checklists_unassigned_work_and_skipped_review_are_rejected(): void
    {
        [$owner, $book, $accountant] = $this->book();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($accountant, 0, 'completed', true))->assertUnprocessable();
        $this->putJson($this->url($book), $this->data($accountant, 0, 'for_review', true))->assertUnprocessable();
        $this->putJson($this->url($book), $this->data($accountant))->assertRedirect();
        $this->putJson($this->url($book), $this->data($accountant, 1, 'for_review'))->assertUnprocessable();
        $this->putJson($this->url($book), [...$this->data($accountant, 1, 'for_review', true), 'assignee_id' => null])->assertUnprocessable();
        $this->putJson($this->url($book), [...$this->data($accountant, 1), 'checklist' => ['documents' => true]])->assertUnprocessable();
        $this->assertDatabaseHas('monthly_closings', ['version' => 1, 'status' => 'in_progress']);
        $this->assertDatabaseCount('monthly_closing_events', 1);
    }

    public function test_unrelated_members_cannot_read_edit_or_be_assigned_and_membership_is_rechecked(): void
    {
        [$owner, $book, $accountant] = $this->book();
        [$foreignOwner, $foreign] = $this->book();
        $this->get(route('closing.show', ['companyId' => $book->id, 'month' => '2026-10']))->assertRedirect(route('login'));
        $this->actingAs($foreignOwner)->get(route('closing.show', ['companyId' => $book->id, 'month' => '2026-10']))->assertNotFound();
        $this->putJson($this->url($book), $this->data($foreignOwner))->assertForbidden();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($foreignOwner))->assertUnprocessable();
        $this->putJson($this->url($book), $this->data($accountant))->assertRedirect();
        $book->users()->updateExistingPivot($accountant->id, ['is_suspended' => true]);
        $this->actingAs($accountant)->get(route('closing.show', ['companyId' => $book->id, 'month' => '2026-10']))->assertNotFound();
        $this->putJson($this->url($book), $this->data($accountant, 1))->assertForbidden();
        $this->assertDatabaseMissing('monthly_closings', ['company_id' => $foreign->id]);
    }

    public function test_only_assignee_can_prepare_and_only_owner_can_reassign_or_reopen(): void
    {
        [$owner, $book, $accountant] = $this->book();
        $other = User::factory()->accountant()->create(['must_change_password' => false]);
        $book->users()->attach($other, ['role' => 'accountant']);
        $this->actingAs($owner)->putJson($this->url($book), $this->data($accountant))->assertRedirect();
        $this->actingAs($other)->get(route('closing.show', ['companyId' => $book->id, 'month' => '2026-10']))->assertOk()->assertDontSee('id="closing-status"', false);
        $this->putJson($this->url($book), $this->data($other, 1))->assertForbidden();
        $this->actingAs($accountant)->putJson($this->url($book), $this->data($other, 1))->assertForbidden();
        $this->putJson($this->url($book), ['version' => 1, 'action' => 'reopen', 'reason' => 'Try reopening'])->assertForbidden();
        $book->users()->updateExistingPivot($accountant->id, ['allowed_tabs' => json_encode(['dashboard', 'masters'])]);
        $this->get(route('closing.show', ['companyId' => $book->id, 'month' => '2026-10']))->assertForbidden();
        $this->putJson($this->url($book), $this->data($accountant, 1))->assertForbidden();
    }

    public function test_stale_saves_cannot_overwrite_another_accountants_changes(): void
    {
        [$owner, $book, $accountant] = $this->book();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($accountant))->assertRedirect();
        $this->actingAs($accountant)->putJson($this->url($book), [...$this->data($accountant, 1), 'notes' => 'Prepared by accountant'])->assertRedirect();
        $this->actingAs($owner)->putJson($this->url($book), [...$this->data($accountant, 1), 'notes' => 'Stale replacement'])->assertConflict();
        $this->assertDatabaseHas('monthly_closings', ['notes' => 'Prepared by accountant', 'version' => 2]);
        $this->assertDatabaseCount('monthly_closing_events', 2);
    }

    public function test_months_and_companies_are_independent_and_summary_respects_memberships(): void
    {
        [$owner, $book, $accountant] = $this->book();
        [, $foreign, $foreignAccountant] = $this->book();
        MonthlyClosing::factory()->create(['company_id' => $foreign->id, 'status' => 'completed', 'assignee_id' => $foreignAccountant->id]);
        $this->actingAs($owner)->putJson($this->url($book), $this->data($accountant))->assertRedirect();
        $this->get(route('my-work', ['month' => '2026-10']))->assertOk()->assertViewHas('closingCounts', ['not_started' => 0, 'in_progress' => 1, 'for_review' => 0, 'completed' => 0])->assertDontSee($foreignAccountant->name);
        $this->get(route('my-work', ['month' => '2026-11']))->assertOk()->assertViewHas('closingCounts', ['not_started' => 1, 'in_progress' => 0, 'for_review' => 0, 'completed' => 0]);
        $this->getJson(route('my-work', ['month' => '2026-13']))->assertUnprocessable();
        $this->get('/account/companies/'.$book->id.'/closing/2026-13')->assertNotFound();
        $this->assertDatabaseCount('monthly_closings', 2);
    }

    public function test_closing_does_not_change_financial_entries_or_company_settings(): void
    {
        [$owner, $book] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 1, 'status' => 'draft', 'customer' => 'Preserved customer']];
        $ledger = CompanyLedger::factory()->create(['company_id' => $book->id, 'state' => $state]);
        $settings = CompanySetting::query()->sole()->getAttributes();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($owner))->assertRedirect();
        $this->putJson($this->url($book), $this->data($owner, 1, 'for_review', true))->assertRedirect();
        $this->putJson($this->url($book), $this->data($owner, 2, 'completed', true))->assertRedirect();
        $this->assertSame($state, $ledger->fresh()->state);
        $this->assertSame($settings, CompanySetting::query()->sole()->getAttributes());
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('company_ledger_changes', 0);
    }

    public function test_failed_audit_write_rolls_back_the_closing(): void
    {
        [$owner, $book] = $this->book();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'monthly_closing_events')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $this->actingAs($owner)->putJson($this->url($book), $this->data($owner))->assertStatus(500);
        $this->assertDatabaseCount('monthly_closings', 0);
        $this->assertDatabaseCount('monthly_closing_events', 0);
    }

    public function test_submitted_work_requires_return_to_in_progress_for_edits(): void
    {
        [$owner, $book, $accountant] = $this->book();
        MonthlyClosing::factory()->create(['company_id' => $book->id, 'assignee_id' => $accountant->id, 'status' => 'for_review', 'checklist' => array_fill_keys(array_keys(MonthlyClosing::CHECKLIST), true)]);
        $this->actingAs($owner)->putJson($this->url($book), [...$this->data($accountant, 1, 'completed', true), 'notes' => 'Changed submitted notes'])->assertUnprocessable();
        $this->putJson($this->url($book), [...$this->data($accountant, 1), 'notes' => 'Returned for changes'])->assertRedirect();
        $this->assertDatabaseHas('monthly_closings', ['status' => 'in_progress', 'notes' => 'Returned for changes']);
    }

    public function test_assignment_requires_active_approved_ready_members_with_sheets_access(): void
    {
        [$owner, $book, $accountant] = $this->book();
        $this->actingAs($owner);
        foreach ([['registration_status' => 'pending'], ['must_change_password' => true]] as $attributes) {
            $accountant->forceFill($attributes)->save();
            $this->putJson($this->url($book), $this->data($accountant))->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
            $accountant->forceFill(['registration_status' => 'approved', 'must_change_password' => false])->save();
        }
        foreach ([['is_suspended' => true], ['is_suspended' => false, 'allowed_tabs' => json_encode(['dashboard', 'books'])]] as $attributes) {
            $book->users()->updateExistingPivot($accountant->id, $attributes);
            $this->putJson($this->url($book), $this->data($accountant))->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        }
        $this->assertDatabaseCount('monthly_closings', 0);
    }

    public function test_setup_and_csrf_are_required_before_closing_updates(): void
    {
        [$owner, $book] = $this->book();
        CompanySetting::query()->delete();
        $this->actingAs($owner)->putJson($this->url($book), $this->data($owner))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->app->instance('env', 'production');
        $this->put($this->url($book), $this->data($owner))->assertStatus(419);
        $this->assertDatabaseCount('monthly_closings', 0);
    }

    /** @return array{User, Company, User} */
    private function book(): array
    {
        $owner = User::factory()->firm()->create();
        $accountant = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach([$owner->id => ['role' => 'owner'], $accountant->id => ['role' => 'accountant']]);
        CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey()]);

        return [$owner, $book, $accountant];
    }

    private function url(Company $book): string
    {
        return route('closing.update', ['companyId' => $book->id, 'month' => '2026-10']);
    }

    /** @return array<string, mixed> */
    private function data(User $assignee, int $version = 0, string $status = 'in_progress', bool $complete = false): array
    {
        return ['version' => $version, 'action' => 'save', 'assignee_id' => $assignee->id, 'status' => $status, 'checklist' => array_fill_keys(array_keys(MonthlyClosing::CHECKLIST), $complete), 'notes' => null];
    }
}
