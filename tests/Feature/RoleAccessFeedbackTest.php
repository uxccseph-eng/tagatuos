<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_denied_browser_action_shows_role_popup_without_changing_book_details(): void
    {
        [$member, $book] = $this->book('staff', ['dashboard', 'settings']);
        $before = CompanySetting::query()->sole()->profile;
        $this->actingAs($member)->withSession(['active_company_id' => $book->id])
            ->put(route('settings.update', ['section' => 'address']), ['city' => 'Forbidden change'])
            ->assertForbidden()->assertViewIs('permission-denied')->assertSee('role-access-feedback.js')
            ->assertSee('"role":"Staff"', false)->assertDontSee('403')->assertSee('owner, manager or accountant')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, CompanySetting::query()->sole()->profile);
    }

    public function test_api_denials_keep_forbidden_status_and_include_current_role(): void
    {
        [$member, $book] = $this->book('accountant', ['dashboard', 'sheets']);
        $this->actingAs($member)->withSession(['active_company_id' => $book->id])
            ->putJson(route('settings.update', ['section' => 'address']), ['city' => 'Forbidden'])
            ->assertForbidden()->assertJsonPath('permission_denied', true)->assertJsonPath('role', 'Accountant');
        $this->getJson(route('settings'))->assertForbidden()->assertJsonPath('role', 'Accountant');
    }

    public function test_missing_company_memberships_still_hide_foreign_records(): void
    {
        [$member] = $this->book('accountant', ['dashboard']);
        $foreign = Company::factory()->create();
        $this->actingAs($member)->get(route('team.index', $foreign->id))->assertNotFound()->assertDontSee($foreign->name);
    }

    public function test_authorized_accountant_settings_config_allows_browser_editing(): void
    {
        [$member, $book] = $this->book('accountant', ['dashboard', 'settings']);
        $this->actingAs($member)->withSession(['active_company_id' => $book->id])->get(route('settings'))
            ->assertOk()->assertSee('"canEditSettings":true', false)->assertSee('role-access-feedback.js');
    }

    public function test_system_owner_denial_uses_role_feedback_for_approved_accounts(): void
    {
        [$member, $book] = $this->book('accountant', ['dashboard']);
        $this->actingAs($member)->withSession(['active_company_id' => $book->id])->get(route('system.dashboard'))
            ->assertForbidden()->assertViewIs('permission-denied')->assertSee('Only the system owner');
    }

    /** @param list<string> $tabs
     * @return array{User, Company}
     */
    private function book(string $role, array $tabs): array
    {
        $member = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($member, ['role' => $role, 'allowed_tabs' => json_encode($tabs)]);
        CompanySetting::factory()->create(['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey()]);

        return [$member, $book];
    }
}
