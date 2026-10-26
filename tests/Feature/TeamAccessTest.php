<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\TeamEvent;
use App\Models\TeamLoginToken;
use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_design_preview_uses_sample_members_without_creating_accounts(): void
    {
        $this->get(route('team.preview'))->assertOk()->assertSee('Read-only design preview')->assertSee('Sample Accountant');
        $this->get(route('team.preview', ['section' => 'templates']))->assertOk()->assertSee('Use template');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->app->instance('env', 'production');
        $this->get(route('team.preview'))->assertNotFound();
    }

    public function test_team_pages_require_an_authenticated_owner_of_the_requested_company(): void
    {
        [$owner, $company] = $this->ownedBook();
        $this->get(route('team.index', $company->id))->assertRedirect(route('login'));
        $outsider = User::factory()->firm()->create();
        $this->actingAs($outsider)->get(route('team.index', $company->id))->assertNotFound();
        $this->postJson(route('team.store', $company->id), $this->newMemberData())->assertForbidden();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_owner_sees_all_team_sections_and_filters_only_members_of_this_book(): void
    {
        [$owner, $company] = $this->ownedBook();
        $member = User::factory()->accountant()->create(['must_change_password' => false, 'name' => 'Visible Accountant']);
        $company->users()->attach($member, ['role' => 'accountant']);
        User::factory()->accountant()->create(['name' => 'Foreign Secret User']);
        foreach (['members', 'access', 'templates', 'history'] as $section) {
            $this->actingAs($owner)->get(route('team.index', ['companyId' => $company->id, 'section' => $section]))
                ->assertOk()->assertSee('Users &amp; access', false)->assertDontSee('Foreign Secret User');
        }
        $this->get(route('team.index', ['companyId' => $company->id, 'q' => 'Visible']))
            ->assertOk()->assertSee('Visible Accountant')->assertDontSee($owner->email);
    }

    public function test_provisioning_creates_ten_character_hashed_credentials_membership_and_signed_qr(): void
    {
        $this->freezeTime();
        [$owner, $company] = $this->ownedBook();
        $this->actingAs($owner)->post(route('team.store', $company->id), $this->newMemberData())
            ->assertRedirect(route('team.index', $company->id))->assertSessionHasNoErrors();
        $credential = session('team_credential');
        $user = User::query()->where('email', 'new-accountant@example.test')->sole();
        $this->assertSame(10, strlen($credential['password']));
        $this->assertTrue(Hash::check($credential['password'], $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame('accountant', $user->type);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'accountant', 'is_suspended' => false]);
        $token = TeamLoginToken::query()->sole();
        $this->assertSame(now()->addMinutes(15)->format('Y-m-d H:i:s'), $token->expires_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('/portal/'.$company->fresh()->slug.'/access/', $credential['url']);
        $this->get(route('team.index', $company->id))->assertOk()->assertSee('data:image/svg+xml;base64,', false)->assertSee('Accountant login created');
        $this->get(route('team.index', $company->id))->assertOk()->assertDontSee('id="temporary-password"', false);
        $event = TeamEvent::query()->sole();
        $this->assertStringNotContainsString($credential['password'], json_encode($event->details));
        $this->assertStringNotContainsString('signature', json_encode($event->details));
    }

    public function test_existing_accountants_can_be_added_without_resetting_credentials_or_other_memberships(): void
    {
        [$owner, $company] = $this->ownedBook();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $otherCompany = Company::factory()->create();
        $otherCompany->users()->attach($user, ['role' => 'staff']);
        $password = $user->password;
        $this->actingAs($owner)->post(route('team.store', $company->id), [
            ...$this->newMemberData(), 'mode' => 'existing', 'email' => $user->email,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('team_credential');
        $this->assertSame($password, $user->fresh()->password);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(2, $user->companies()->count());
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    public function test_duplicate_and_incomplete_accountant_accounts_are_not_reprovisioned_or_assigned_elsewhere(): void
    {
        [$owner, $company] = $this->ownedBook();
        $user = User::factory()->accountant()->create();
        $this->actingAs($owner)->postJson(route('team.store', $company->id), [...$this->newMemberData(), 'email' => $user->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(route('team.store', $company->id), [...$this->newMemberData(), 'mode' => 'existing', 'email' => $user->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('company_user', 1);
    }

    public function test_owners_are_protected_from_demotion_and_removal_and_cannot_be_created_through_member_forms(): void
    {
        [$owner, $company] = $this->ownedBook();
        $this->actingAs($owner)->putJson(route('team.update', ['companyId' => $company->id, 'memberId' => $owner->id]), ['role' => 'staff', 'tabs' => ['dashboard']])
            ->assertUnprocessable()->assertJsonValidationErrors('member');
        $this->deleteJson(route('team.remove', ['companyId' => $company->id, 'memberId' => $owner->id]), ['confirmation' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('member');
        $this->postJson(route('team.store', $company->id), [...$this->newMemberData(), 'role' => 'owner', 'tabs' => ['invalid-module']])
            ->assertUnprocessable()->assertJsonValidationErrors(['role', 'tabs.0']);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_setup_links_are_confirmation_based_single_use_and_force_password_setup(): void
    {
        [$owner, $company] = $this->ownedBook();
        $created = app(TeamAccessService::class)->add($owner, $company->id, $this->newMemberData());
        $url = $created['url'];
        $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Continue as New Accountant');
        $this->assertNull(TeamLoginToken::query()->sole()->used_at);
        $this->assertGuest();
        $this->post($url)->assertRedirect(route('account.password.edit'))->assertSessionHas('onboarding_user_id', $created['user']->id);
        $this->assertAuthenticatedAs($created['user']);
        $this->get(route('sheets.sales'))->assertRedirect(route('account.password.edit'));
        $this->get(route('account.password.edit'))->assertOk()->assertDontSee('id="current-password"', false);
        $this->put(route('account.password.update'), ['password' => 'MyNewStrongPassword123!', 'password_confirmation' => 'MyNewStrongPassword123!'])
            ->assertRedirect(route('account.dashboard'))->assertSessionMissing('onboarding_user_id');
        $this->assertFalse($created['user']->fresh()->must_change_password);
        $this->post(route('logout'))->assertRedirect();
        $this->get($url)->assertStatus(410);
        $this->post($url)->assertStatus(410);
    }

    public function test_expired_tampered_and_cross_company_setup_links_are_rejected(): void
    {
        [$owner, $company] = $this->ownedBook();
        $created = app(TeamAccessService::class)->add($owner, $company->id, $this->newMemberData());
        $this->get($created['url'].'x')->assertForbidden();
        $token = TeamLoginToken::query()->sole();
        $other = Company::factory()->create(['slug' => 'foreign-portal']);
        $foreignUrl = URL::temporarySignedRoute('portal.access', now()->addMinutes(15), ['firmSlug' => $other->slug, 'tokenId' => $token->id]);
        $this->get($foreignUrl)->assertNotFound();
        $this->travel(16)->minutes();
        $this->get($created['url'])->assertForbidden();
        $this->assertGuest();
    }

    public function test_updating_access_revokes_old_setup_links_and_reissuing_revokes_the_previous_link(): void
    {
        [$owner, $company] = $this->ownedBook();
        $access = app(TeamAccessService::class);
        $created = $access->add($owner, $company->id, $this->newMemberData());
        $replacement = $access->setupLink($owner, $company->id, $created['user']->id);
        $this->get($created['url'])->assertStatus(410);
        $this->get($replacement)->assertOk();
        $access->update($owner, $company->id, $created['user']->id, ['role' => 'staff', 'tabs' => ['dashboard'], 'is_suspended' => true]);
        $this->get($replacement)->assertNotFound();
        $this->actingAs($created['user']->forceFill(['must_change_password' => false]))
            ->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_completed_accounts_cannot_receive_setup_links_that_impersonate_their_other_books(): void
    {
        [$owner, $company] = $this->ownedBook();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'accountant']);
        $this->actingAs($owner)->postJson(route('team.setup-link', ['companyId' => $company->id, 'memberId' => $user->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('member');
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    public function test_server_enforces_tab_access_and_keeps_catalog_choices_available_to_permitted_sheets(): void
    {
        [$owner, $company] = $this->ownedBook();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $this->configure($company);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        $this->get(route('sheets.sales'))->assertOk();
        $this->get(route('masters.accounts.catalog'))->assertOk();
        $this->get(route('books.show', ['book' => 'general-journal']))->assertForbidden();
        $this->get(route('masters.accounts'))->assertForbidden();
        $this->postJson(route('reports.sawt.dat'), [])->assertForbidden();
        $this->get(route('team.index', $company->id))->assertNotFound();
    }

    public function test_suspension_and_removal_preserve_other_books_user_credentials_and_history(): void
    {
        [$owner, $company] = $this->ownedBook();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $other = Company::factory()->create();
        $company->users()->attach($user, ['role' => 'staff']);
        $other->users()->attach($user, ['role' => 'accountant']);
        $password = $user->password;
        $this->actingAs($owner)->deleteJson(route('team.remove', ['companyId' => $company->id, 'memberId' => $user->id]), [])->assertUnprocessable();
        $this->delete(route('team.remove', ['companyId' => $company->id, 'memberId' => $user->id]), ['confirmation' => 1])->assertRedirect();
        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('company_user', ['company_id' => $other->id, 'user_id' => $user->id, 'role' => 'accountant']);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseHas('team_events', ['company_id' => $company->id, 'subject_id' => $user->id, 'action' => 'Member removed']);
    }

    public function test_changing_portal_enforces_unique_reserved_slugs_and_revokes_outstanding_tokens(): void
    {
        [$owner, $company] = $this->ownedBook();
        $created = app(TeamAccessService::class)->add($owner, $company->id, $this->newMemberData());
        $this->actingAs($owner)->putJson(route('team.portal', $company->id), ['slug' => 'sheets'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->put(route('team.portal', $company->id), ['slug' => '  MY-FIRM  '])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('my-firm', $company->fresh()->slug);
        $this->post(route('logout'))->assertRedirect();
        $this->get($created['url'])->assertNotFound();
        $this->get('/my-firm')->assertOk()->assertSee($company->name);
        $this->get('/signup')->assertOk()->assertViewIs('account-auth');
        $this->get('/not-a-registered-portal')->assertNotFound();
    }

    public function test_portal_login_only_authenticates_members_of_that_company(): void
    {
        [$owner, $company] = $this->ownedBook();
        $company->update(['slug' => 'assigned-company']);
        $user = User::factory()->accountant()->create(['must_change_password' => false, 'password' => 'ExistingPassword123!']);
        $this->postJson(route('portal.login', ['firmSlug' => $company->slug]), ['email' => $user->email, 'password' => 'ExistingPassword123!'])->assertUnprocessable();
        $this->assertGuest();
        $company->users()->attach($user, ['role' => 'staff']);
        $this->post(route('portal.login', ['firmSlug' => $company->slug]), ['email' => $user->email, 'password' => 'ExistingPassword123!'])
            ->assertRedirect(route('dashboard'))->assertSessionHas('active_company_id', $company->id);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_member_creation_and_membership_roll_back_if_history_cannot_be_recorded(): void
    {
        [$owner, $company] = $this->ownedBook();
        $event = 'eloquent.creating: '.TeamEvent::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Simulated audit write failure.');
        });
        try {
            $this->actingAs($owner)->post(route('team.store', $company->id), $this->newMemberData())->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('company_user', 1);
        $this->assertDatabaseCount('team_events', 0);
        $this->assertDatabaseCount('team_login_tokens', 0);
    }

    public function test_team_actions_require_csrf_tokens(): void
    {
        [$owner, $company] = $this->ownedBook();
        $this->app->instance('env', 'production');
        $this->actingAs($owner)->post(route('team.store', $company->id), $this->newMemberData())->assertStatus(419);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_owner_cannot_edit_a_person_who_only_belongs_to_another_book(): void
    {
        [$owner, $company] = $this->ownedBook();
        $foreignCompany = Company::factory()->create();
        $foreignMember = User::factory()->accountant()->create(['must_change_password' => false]);
        $foreignCompany->users()->attach($foreignMember, ['role' => 'accountant']);
        $this->actingAs($owner)->putJson(route('team.update', ['companyId' => $company->id, 'memberId' => $foreignMember->id]), ['role' => 'staff', 'tabs' => ['dashboard']])->assertNotFound();
        $this->deleteJson(route('team.remove', ['companyId' => $company->id, 'memberId' => $foreignMember->id]), ['confirmation' => 1])->assertNotFound();
        $this->assertDatabaseHas('company_user', ['company_id' => $foreignCompany->id, 'user_id' => $foreignMember->id, 'role' => 'accountant']);
        $this->assertDatabaseCount('team_events', 0);
    }

    public function test_suspension_blocks_an_existing_session_but_preserves_access_to_other_assigned_books(): void
    {
        [$owner, $company] = $this->ownedBook();
        $other = Company::factory()->create();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        foreach ([$company, $other] as $book) {
            $book->users()->attach($user, ['role' => 'accountant']);
            $this->configure($book);
        }
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->get(route('sheets.payroll'))->assertOk();
        app(TeamAccessService::class)->update($owner, $company->id, $user->id, ['role' => 'accountant', 'tabs' => ['dashboard', 'sheets'], 'is_suspended' => true]);
        $this->getJson(route('sheets.payroll'))->assertForbidden()->assertSessionMissing('active_company_id');
        $this->withSession(['active_company_id' => $other->id])->get(route('sheets.payroll'))->assertOk();
        $this->getJson(route('account.companies.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id);
    }

    public function test_password_setup_proof_cannot_be_forged_in_the_request_payload(): void
    {
        $user = User::factory()->accountant()->create();
        $this->actingAs($user)->putJson(route('account.password.update'), ['onboarding_user_id' => $user->id, 'password' => 'MyNewStrongPassword123!', 'password_confirmation' => 'MyNewStrongPassword123!'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue($user->fresh()->must_change_password);
    }

    /** @return array{User, Company} */
    private function ownedBook(): array
    {
        $owner = User::factory()->firm()->create();
        $company = Company::factory()->create(['name' => 'Example Company Book']);
        $company->users()->attach($owner, ['role' => 'owner']);

        return [$owner, $company];
    }

    /** @return array{mode: string, name: string, email: string, role: string, tabs: list<string>} */
    private function newMemberData(): array
    {
        return ['mode' => 'new', 'name' => 'New Accountant', 'email' => 'new-accountant@example.test', 'role' => 'accountant', 'tabs' => ['dashboard', 'sheets', 'masters', 'books']];
    }

    private function configure(Company $company): void
    {
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example Owner', 'tin' => '000000000', 'branch_code' => '00000', 'vat_type' => 'non_vat', 'entity_type' => 'individual', 'payroll' => 'no', 'supplier_tax' => 'none', 'customer_tax' => 'no', 'ppe' => 'no', 'accounting_period' => 'calendar']]);
    }
}
