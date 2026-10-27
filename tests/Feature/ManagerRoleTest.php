<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MonthlyClosing;
use App\Models\User;
use App\Services\CompanyBookService;
use App\Services\TeamAccessService;
use App\TeamPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_promote_a_manager_across_current_and_future_organization_books(): void
    {
        [$owner, $first, $manager] = $this->team();
        $second = $this->book($owner, 'Second Client');
        $third = $this->book($owner, 'Future Client');
        foreach ([$first, $second, $third] as $company) {
            $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager']);
        }
        $this->assertDatabaseHas('organization_members', ['owner_id' => $owner->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->actingAs($owner)->get(route('team.index', ['companyId' => $first->id, 'role' => 'manager']))->assertOk()->assertSee($manager->name)->assertSee('<option value="manager">Manager</option>', false);
        $this->assertSame('manager', TeamPermissions::templates()['manager']['role']);
        $this->actingAs($manager)->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_manager_can_assign_review_approve_and_reopen_with_own_identity_in_history(): void
    {
        [$owner, $company, $manager] = $this->team();
        $accountant = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($accountant, ['role' => 'accountant']);
        $payload = ['version' => 0, 'action' => 'save', 'assignee_id' => $accountant->id, 'status' => 'in_progress', 'checklist' => MonthlyClosing::emptyChecklist(), 'notes' => null];
        $this->actingAs($manager)->putJson($this->url($company), $payload)->assertRedirect();
        $this->assertDatabaseHas('monthly_closings', ['assignee_id' => $accountant->id, 'status' => 'in_progress']);
        $payload['version'] = 1;
        $payload['status'] = 'for_review';
        $payload['checklist'] = array_fill_keys(array_keys(MonthlyClosing::CHECKLIST), true);
        $this->actingAs($accountant)->putJson($this->url($company), $payload)->assertRedirect();
        $this->actingAs($manager)->get(route('closing.show', ['companyId' => $company->id, 'month' => '2026-10']))->assertOk()->assertSee('<option value="completed"', false);
        $payload['version'] = 2;
        $payload['status'] = 'completed';
        $this->putJson($this->url($company), $payload)->assertRedirect();
        $this->assertDatabaseHas('monthly_closings', ['status' => 'completed', 'completed_by' => $manager->id, 'version' => 3]);
        $this->assertDatabaseHas('monthly_closing_events', ['company_id' => $company->id, 'actor_id' => $manager->id, 'action' => 'completed']);
        $this->get(route('closing.show', ['companyId' => $company->id, 'month' => '2026-10']))->assertOk()->assertSee('Reopen this month')->assertSee($manager->name);
        $this->putJson($this->url($company), ['version' => 3, 'action' => 'reopen', 'reason' => 'New source documents require review'])->assertRedirect();
        $this->assertDatabaseHas('monthly_closings', ['status' => 'in_progress', 'completed_by' => null, 'version' => 4]);
        $this->assertDatabaseHas('monthly_closing_events', ['actor_id' => $manager->id, 'action' => 'reopened', 'reason' => 'New source documents require review']);
    }

    public function test_managers_cannot_manage_members_ownership_or_system_approvals_and_settings_still_require_valid_data(): void
    {
        [$owner, $company, $manager] = $this->team();
        $this->actingAs($manager)->get(route('team.index', $company->id))->assertNotFound();
        $this->postJson(route('team.store', $company->id), ['mode' => 'existing', 'email' => $owner->email, 'role' => 'manager', 'tabs' => ['dashboard']])->assertForbidden();
        $this->putJson(route('team.update', ['companyId' => $company->id, 'memberId' => $manager->id]), ['role' => 'owner', 'tabs' => ['dashboard']])->assertForbidden();
        $this->deleteJson(route('team.remove', ['companyId' => $company->id, 'memberId' => $owner->id]), ['confirmation' => 1])->assertForbidden();
        $this->withSession(['active_company_id' => $company->id])->postJson(route('company.store'), [])->assertUnprocessable()->assertJsonValidationErrors('company_name');
        $this->putJson(route('settings.update', ['section' => 'bir']), [])->assertUnprocessable()->assertJsonValidationErrors('accounting_period');
        $this->getJson(route('system.dashboard'))->assertForbidden();
        $this->postJson(route('account.companies.store'), ['name' => 'Unauthorized Client'])->assertForbidden();
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager']);
    }

    public function test_manager_cannot_access_another_organizations_closing(): void
    {
        [, $company, $manager] = $this->team();
        $foreignOwner = User::factory()->firm()->create();
        $foreign = $this->book($foreignOwner, 'Private Foreign Client');
        $closing = $this->review($foreign, $foreignOwner);
        $this->actingAs($manager)->get(route('closing.show', ['companyId' => $foreign->id, 'month' => '2026-10']))->assertNotFound();
        $this->putJson($this->url($foreign), $this->approval($closing))->assertForbidden();
        $this->assertDatabaseHas('monthly_closings', ['id' => $closing->id, 'status' => 'for_review', 'version' => 1]);
    }

    public function test_demotion_suspension_and_module_restrictions_remove_manager_approval_immediately(): void
    {
        [$owner, $company, $manager] = $this->team();
        $closing = $this->review($company, $owner);
        app(TeamAccessService::class)->update($owner, $company->id, $manager->id, ['role' => 'accountant', 'tabs' => array_keys(TeamPermissions::TABS)]);
        $this->actingAs($manager)->putJson($this->url($company), [...$this->approval($closing), 'role' => 'manager'])->assertForbidden();
        app(TeamAccessService::class)->update($owner, $company->id, $manager->id, ['role' => 'manager', 'tabs' => ['dashboard', 'masters']]);
        $this->get(route('closing.show', ['companyId' => $company->id, 'month' => '2026-10']))->assertForbidden();
        $this->putJson($this->url($company), $this->approval($closing))->assertForbidden();
        app(TeamAccessService::class)->update($owner, $company->id, $manager->id, ['role' => 'manager', 'tabs' => array_keys(TeamPermissions::TABS), 'is_suspended' => true]);
        $this->putJson($this->url($company), $this->approval($closing))->assertForbidden();
        $this->assertDatabaseHas('monthly_closings', ['id' => $closing->id, 'status' => 'for_review']);
        $this->assertDatabaseCount('monthly_closing_events', 0);
    }

    public function test_managers_still_need_complete_review_steps_and_the_latest_version(): void
    {
        [$owner, $company, $manager] = $this->team();
        $closing = $this->review($company, $owner);
        $this->actingAs($manager)->putJson($this->url($company), [...$this->approval($closing), 'version' => 0])->assertConflict();
        $this->putJson($this->url($company), [...$this->approval($closing), 'checklist' => MonthlyClosing::emptyChecklist()])->assertUnprocessable();
        $this->putJson($this->url($company), ['version' => 1, 'action' => 'reopen', 'reason' => ''])->assertUnprocessable();
        $this->assertDatabaseHas('monthly_closings', ['status' => 'for_review', 'version' => 1]);
    }

    /** @return array{User, Company, User} */
    private function team(): array
    {
        $owner = User::factory()->firm()->create();
        $company = $this->book($owner, 'Example Client');
        $manager = User::factory()->accountant()->create(['must_change_password' => false, 'name' => 'Review Manager']);
        $this->actingAs($owner)->post(route('team.store', $company->id), ['mode' => 'existing', 'email' => $manager->email, 'role' => 'manager', 'tabs' => array_keys(TeamPermissions::TABS)])->assertRedirect()->assertSessionHasNoErrors();

        return [$owner, $company, $manager];
    }

    private function book(User $owner, string $name): Company
    {
        $company = app(CompanyBookService::class)->createBook($owner, $name);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey()]);

        return $company;
    }

    private function review(Company $company, User $assignee): MonthlyClosing
    {
        return MonthlyClosing::factory()->create(['company_id' => $company->id, 'assignee_id' => $assignee->id, 'status' => 'for_review', 'checklist' => array_fill_keys(array_keys(MonthlyClosing::CHECKLIST), true)]);
    }

    /** @return array<string, mixed> */
    private function approval(MonthlyClosing $closing): array
    {
        return ['version' => $closing->version, 'action' => 'save', 'status' => 'completed', 'assignee_id' => $closing->assignee_id, 'checklist' => $closing->checklist, 'notes' => null];
    }

    private function url(Company $company): string
    {
        return route('closing.update', ['companyId' => $company->id, 'month' => '2026-10']);
    }
}
