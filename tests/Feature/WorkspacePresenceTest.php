<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspacePresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_an_accountant_can_see_a_teammates_book_and_sheet_without_a_selected_session_book(): void
    {
        [$book, $viewer, $member] = $this->team();
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($book))->assertOk();
        $this->actingAs($viewer)->getJson('/account/presence')->assertOk()
            ->assertJsonCount(1, 'members')->assertJsonPath('members.0.user_id', $member->id)
            ->assertJsonPath('members.0.company_name', $book->name)->assertJsonPath('members.0.section', 'Sales')
            ->assertJsonPath('members.0.status', 'online')->assertJsonMissingPath('members.0.email');
    }

    public function test_other_firms_and_the_members_current_private_book_are_not_disclosed(): void
    {
        [$shared, $viewer, $member] = $this->team();
        $outsider = User::factory()->create();
        $private = Company::factory()->create(['name' => 'Private client']);
        $private->users()->attach([$member->id => ['role' => 'accountant'], $outsider->id => ['role' => 'owner']]);
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($shared))->assertOk();
        $this->actingAs($outsider)->postJson('/account/presence', $this->heartbeat($private))->assertOk();
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(1, 'members');
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($private, 'payroll'))->assertOk();
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(0, 'members')->assertDontSee('Private client');
    }

    public function test_multiple_tabs_count_once_and_a_hidden_tab_does_not_replace_the_active_book(): void
    {
        [$first, $viewer, $member] = $this->team();
        $second = Company::factory()->create();
        $second->users()->attach([$member->id => ['role' => 'accountant'], $viewer->id => ['role' => 'owner']]);
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($first))->assertOk();
        $this->postJson('/account/presence', [...$this->heartbeat($second, 'payroll'), 'visible' => false])->assertOk();
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(1, 'members')->assertJsonPath('members.0.company_id', $first->id);
    }

    public function test_idle_users_are_away_and_missing_heartbeats_expire(): void
    {
        [$book, $viewer, $member] = $this->team();
        $this->actingAs($member)->postJson('/account/presence', [...$this->heartbeat($book), 'idle' => true])->assertOk();
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonPath('members.0.status', 'away');
        $this->travel(91)->seconds();
        $this->getJson('/account/presence')->assertJsonCount(0, 'members');
    }

    public function test_suspension_and_module_revocation_hide_previously_cached_activity(): void
    {
        [$book, $viewer, $member] = $this->team();
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($book))->assertOk();
        $book->users()->updateExistingPivot($member->id, ['allowed_tabs' => json_encode(['dashboard', 'books'])]);
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(0, 'members');
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($book))->assertForbidden();
        $book->users()->updateExistingPivot($member->id, ['allowed_tabs' => null, 'is_suspended' => true]);
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(0, 'members');
    }

    public function test_a_forged_company_or_section_cannot_publish_activity(): void
    {
        [$book, $viewer, $member] = $this->team();
        $private = Company::factory()->create();
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($private))->assertForbidden();
        $this->postJson('/account/presence', $this->heartbeat($book, 'script>alert'))->assertUnprocessable();
        $this->postJson('/account/presence', [...$this->heartbeat($book), 'company_id' => null])->assertUnprocessable();
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(0, 'members');
    }

    public function test_anonymous_and_unapproved_accounts_cannot_read_the_roster(): void
    {
        $this->getJson('/account/presence')->assertUnauthorized();
        $pending = User::factory()->pending()->create();
        $this->actingAs($pending)->getJson('/account/presence')->assertForbidden();
    }

    public function test_viewer_permissions_are_checked_again_when_reading_cached_activity(): void
    {
        [$book, $viewer, $member] = $this->team();
        $this->actingAs($member)->postJson('/account/presence', $this->heartbeat($book))->assertOk();
        $book->users()->updateExistingPivot($viewer->id, ['role' => 'accountant', 'allowed_tabs' => json_encode(['dashboard'])]);
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(0, 'members');
        $book->users()->updateExistingPivot($viewer->id, ['allowed_tabs' => null, 'is_suspended' => true]);
        $this->getJson('/account/presence')->assertJsonCount(0, 'members');
    }

    public function test_global_activity_does_not_change_the_selected_company_and_shows_once(): void
    {
        [$book, $viewer, $member] = $this->team();
        $payload = [...$this->heartbeat($book), 'company_id' => null, 'section' => 'my-work'];
        $this->actingAs($member)->withSession(['active_company_id' => $book->id])
            ->postJson('/account/presence', $payload)->assertOk()->assertSessionHas('active_company_id', $book->id);
        $this->postJson('/account/presence', [...$payload, 'idle' => true])->assertOk();
        $this->actingAs($viewer)->getJson('/account/presence')->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.company_id', null)->assertJsonPath('members.0.section', 'My work')
            ->assertJsonPath('members.0.status', 'away');
    }

    public function test_presence_assets_are_only_rendered_for_approved_signed_in_accounts(): void
    {
        $this->view('workspace-presence-assets')->assertDontSee('workspace-presence-config');
        $approved = User::factory()->create();
        $this->actingAs($approved)->view('workspace-presence-assets')
            ->assertSee('workspace-presence-config')->assertSee('workspace-presence.js')->assertSee('account\/presence', false);
        $this->actingAs(User::factory()->pending()->create())->view('workspace-presence-assets')
            ->assertDontSee('workspace-presence-config');
    }

    /** @return array{Company, User, User} */
    private function team(): array
    {
        $book = Company::factory()->create();
        $viewer = User::factory()->firm()->create();
        $member = User::factory()->create(['type' => 'accountant']);
        $book->users()->attach([$viewer->id => ['role' => 'owner'], $member->id => ['role' => 'accountant']]);

        return [$book, $viewer, $member];
    }

    /** @return array{tab_id: string, company_id: int, section: string, idle: bool, visible: bool} */
    private function heartbeat(Company $book, string $section = 'sales'): array
    {
        return ['tab_id' => (string) Str::uuid(), 'company_id' => $book->id, 'section' => $section, 'idle' => false, 'visible' => true];
    }
}
