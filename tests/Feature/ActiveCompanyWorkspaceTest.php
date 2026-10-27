<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveCompanyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_book_checks_membership_before_setting_active_company(): void
    {
        $user = User::factory()->firm()->create();
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $own->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->post(route('account.companies.open', $foreign->id))->assertNotFound()->assertSessionMissing('active_company_id');
        $this->post(route('account.companies.open', $own->id))->assertRedirect(route('dashboard'))->assertSessionHas('active_company_id', $own->id);
        $this->get(route('dashboard'))->assertRedirect(route('company.setup'));
        $this->get(route('company.setup'))->assertOk()->assertSee($own->name)->assertSee('Company registration');
    }

    public function test_real_company_setup_is_persisted_and_restored_after_session_context_is_cleared(): void
    {
        $user = User::factory()->create();
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])
            ->post(route('company.store'), $this->companyData())->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('company_settings', ['workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey()]);
        $this->assertSame('Example Company', $book->fresh()->name);
        $this->get(route('dashboard'))->assertOk()->assertSee('Example Company')->assertSee('Company books')
            ->assertSessionHas('company_setup.company_id', $book->id)->assertSessionHas('company_setup.workspace_key', $book->workspaceKey());
        $browserKey = session('company_setup.browser_key');
        $this->assertMatchesRegularExpression('/^company-[a-f0-9]{64}$/', $browserKey);
        $this->withSession(['company_setup' => null])->get(route('dashboard'))->assertOk()
            ->assertSessionHas('company_setup.browser_key', $browserKey);
    }

    public function test_same_company_names_and_tins_do_not_merge_different_company_books(): void
    {
        $firm = User::factory()->firm()->create();
        $first = Company::factory()->create();
        $second = Company::factory()->create();
        foreach ([$first, $second] as $book) {
            $book->users()->attach($firm, ['role' => 'owner']);
            $this->actingAs($firm)->post(route('account.companies.open', $book->id))->assertRedirect(route('dashboard'));
            $this->post(route('company.store'), $this->companyData())->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
            $this->get(route('dashboard'))->assertOk();
        }
        $this->assertDatabaseCount('company_settings', 2);
        $this->assertNotSame($first->workspaceKey(), $second->workspaceKey());
    }

    public function test_financial_routes_recheck_revoked_memberships_instead_of_trusting_preview_flags(): void
    {
        $user = User::factory()->firm()->create();
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'owner']);
        $this->configure($book);
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->get(route('dashboard'))->assertOk();
        $book->users()->detach($user);
        $this->getJson(route('masters.registry', ['registry' => 'parties']))->assertForbidden()
            ->assertSessionMissing('company_setup')->assertSessionMissing('preview_access')->assertSessionMissing('active_company_id');
        $this->get(route('sheets.sales'))->assertRedirect(route('account.dashboard'));
    }

    public function test_forged_session_profile_cannot_change_the_active_workspace(): void
    {
        $user = User::factory()->firm()->create();
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $own->users()->attach($user, ['role' => 'owner']);
        $this->configure($own);
        $this->configure($foreign);
        Party::factory()->create(['workspace_key' => $own->workspaceKey(), 'name' => 'Assigned Customer']);
        Party::factory()->create(['workspace_key' => $foreign->workspaceKey(), 'name' => 'Foreign Secret Customer']);
        $this->actingAs($user)->withSession([
            'active_company_id' => $own->id, 'preview_access' => true,
            'company_setup' => ['workspace_key' => $foreign->workspaceKey(), 'company_name' => 'Forged Company'],
        ])->get(route('masters.registry', ['registry' => 'parties']))->assertOk()
            ->assertSee('Assigned Customer')->assertDontSee('Foreign Secret Customer')
            ->assertSessionHas('company_setup.workspace_key', $own->workspaceKey());
    }

    public function test_staff_can_open_assigned_books_but_cannot_modify_company_setup_or_settings(): void
    {
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'staff']);
        $this->configure($book);
        $this->actingAs($user)->post(route('account.companies.open', $book->id))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('company.setup'))->assertForbidden();
        $this->post(route('company.store'), $this->companyData())->assertForbidden();
        $this->put(route('settings.update', ['section' => 'address']), ['city' => 'Not Allowed'])->assertForbidden();
        $this->get(route('sheets.payroll'))->assertOk();
    }

    public function test_account_without_an_active_company_cannot_reuse_a_demo_workspace(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['preview_access' => true, 'company_setup' => $this->companyData()])
            ->get(route('dashboard'))->assertRedirect(route('account.dashboard'))->assertSessionMissing('company_setup');
        $this->post(route('preview.start'), ['method' => 'google'])->assertRedirect(route('account.dashboard'));
    }

    public function test_owned_settings_updates_stay_bound_to_the_company_id_workspace(): void
    {
        $user = User::factory()->create();
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'owner']);
        $this->configure($book);
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])
            ->put(route('settings.update', ['section' => 'address']), ['city' => 'Quezon City'])
            ->assertRedirect(route('settings.section', ['section' => 'address']))->assertSessionHasNoErrors();
        $settings = CompanySetting::query()->where('workspace_key', $book->workspaceKey())->sole();
        $this->assertSame($book->workspaceKey(), $settings->lookup_key);
        $this->assertSame('Quezon City', $settings->profile['city']);
        $this->get(route('settings.section', ['section' => 'address']))->assertOk()->assertSee('Quezon City');
    }

    public function test_accountant_with_settings_access_can_edit_only_the_active_assigned_book(): void
    {
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $foreign = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'accountant', 'allowed_tabs' => json_encode(['dashboard', 'settings'])]);
        $this->configure($book);
        $this->configure($foreign);
        $foreignProfile = CompanySetting::query()->where('workspace_key', $foreign->workspaceKey())->sole()->profile;

        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->get(route('company.setup'))->assertOk();
        $this->post(route('company.store'), [...$this->companyData(), 'company_name' => 'Updated Assigned Book'])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $this->put(route('settings.update', ['section' => 'address']), ['city' => 'Quezon City'])->assertSessionHasNoErrors();
        $this->assertSame('Updated Assigned Book', $book->fresh()->name);
        $this->assertSame('Quezon City', CompanySetting::query()->where('workspace_key', $book->workspaceKey())->sole()->profile['city']);
        $this->assertSame($foreignProfile, CompanySetting::query()->where('workspace_key', $foreign->workspaceKey())->sole()->profile);
        $this->assertDatabaseHas('company_user', ['company_id' => $book->id, 'user_id' => $user->id, 'role' => 'accountant']);
        $this->get(route('team.index', $book->id))->assertNotFound();
        $this->withHeader('X-Company-ID', (string) $foreign->id)->putJson(route('settings.update', ['section' => 'address']), ['city' => 'Forged'])->assertConflict();
        $this->flushHeaders();
        $this->withSession(['active_company_id' => $foreign->id])->putJson(route('settings.update', ['section' => 'address']), ['city' => 'Forbidden'])->assertForbidden();
        $this->assertSame($foreignProfile, CompanySetting::query()->where('workspace_key', $foreign->workspaceKey())->sole()->profile);
    }

    public function test_accountant_without_settings_access_cannot_bypass_it_through_company_setup(): void
    {
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'accountant', 'allowed_tabs' => json_encode(['dashboard', 'sheets'])]);
        $this->configure($book);
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->get(route('company.setup'))->assertForbidden();
        $this->post(route('company.store'), $this->companyData())->assertForbidden();
        $this->get(route('settings'))->assertForbidden();
        $this->put(route('settings.update', ['section' => 'address']), ['city' => 'Forbidden'])->assertForbidden();
    }

    public function test_authorized_manager_can_complete_initial_book_registration(): void
    {
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'manager', 'allowed_tabs' => json_encode(['dashboard', 'settings'])]);
        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->get(route('dashboard'))->assertRedirect(route('company.setup'));
        $this->get(route('company.setup'))->assertOk();
        $this->post(route('company.store'), $this->companyData())->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('company_settings', ['workspace_key' => $book->workspaceKey()]);
        $this->assertDatabaseHas('company_user', ['company_id' => $book->id, 'user_id' => $user->id, 'role' => 'manager']);
    }

    public function test_dashboard_profile_identifies_the_logged_in_accountant_instead_of_the_company_contact(): void
    {
        $user = User::factory()->accountant()->create(['name' => 'Assigned Accountant', 'email' => 'assigned@example.test', 'must_change_password' => false]);
        $book = Company::factory()->create();
        $book->users()->attach($user, ['role' => 'accountant']);
        $this->configure($book);

        $this->actingAs($user)->withSession(['active_company_id' => $book->id])->get(route('dashboard'))
            ->assertOk()->assertSee('aria-label="Profile for Assigned Accountant"', false)
            ->assertSee('assigned@example.test')->assertSee('Role in this book')->assertSee('Accountant')
            ->assertSee(route('account.password.edit'))->assertSee('data-dialog-profile', false);
        $this->assertSame('Example Owner', CompanySetting::query()->where('workspace_key', $book->workspaceKey())->sole()->profile['full_name']);
    }

    private function configure(Company $book): void
    {
        CompanySetting::factory()->create([
            'workspace_key' => $book->workspaceKey(), 'lookup_key' => $book->workspaceKey(),
            'profile' => [...$this->companyData(), 'company_name' => $book->name],
        ]);
    }

    /** @return array<string, string> */
    private function companyData(): array
    {
        return [
            'full_name' => 'Example Owner', 'preview_ack' => '1', 'company_name' => 'Example Company',
            'vat_type' => 'non_vat', 'entity_type' => 'individual', 'tin' => '000-000-000', 'branch_code' => '00000',
            'contact_number' => '0917 123 4567', 'accounting_period' => 'calendar',
            'payroll' => 'no', 'supplier_tax' => 'none', 'customer_tax' => 'no', 'ppe' => 'no',
        ];
    }
}
