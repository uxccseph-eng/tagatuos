<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySetupIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_setup_shows_the_signed_in_identity_instead_of_demo_content(): void
    {
        $company = Company::factory()->create(['name' => 'Acme client']);
        $accountant = User::factory()->accountant()->create(['name' => 'Maria Santos', 'must_change_password' => false]);
        $company->users()->attach($accountant, ['role' => 'accountant', 'allowed_tabs' => json_encode(['settings'])]);

        $this->actingAs($accountant)
            ->get(route('company.setup', ['company_id' => $company->id]))
            ->assertOk()
            ->assertSee('Account menu for Maria Santos')
            ->assertSee('Acme client')
            ->assertSee('Enter your name for this company book.')
            ->assertDontSee('Enter a name for this demo.')
            ->assertDontSee('This is not a signed-in account.')
            ->assertDontSee('id="preview-info"', false);
    }

    public function test_guest_setup_retains_the_explicit_preview_disclosure(): void
    {
        $this->withSession(['preview_access' => true])
            ->get(route('company.setup'))
            ->assertOk()
            ->assertSee('Enter a name for this demo.')
            ->assertSee('This is not a signed-in account.')
            ->assertDontSee('Account menu for');
    }

    public function test_first_company_setup_does_not_boot_the_transaction_ledger(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('company.setup', ['company_id' => $company->id]))
            ->assertOk()->assertSee('tenant-company', false)
            ->assertSee('company-setup.js', false)
            ->assertDontSee('company-ledger-sync.js', false)
            ->assertDontSee('company-draft-store.js', false)
            ->assertSee('Registration details are saved to the selected company book.')
            ->assertDontSee('Settings are saved only in your local session.');
        $this->assertDatabaseCount('company_settings', 0);
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_setup_can_save_an_explicit_client_without_changing_the_other_tab_company(): void
    {
        $first = Company::factory()->create(['name' => 'First client']);
        $second = Company::factory()->create(['name' => 'Second client']);
        $owner = User::factory()->firm()->create();
        foreach ([$first, $second] as $company) {
            $company->users()->attach($owner, ['role' => 'owner']);
        }
        $this->actingAs($owner)->withSession(['active_company_id' => $first->id])
            ->post(route('company.store', ['company_id' => $second->id]), [
                'full_name' => 'Accountant', 'preview_ack' => '1', 'vat_type' => 'non_vat',
                'entity_type' => 'individual', 'company_name' => 'Second client registered',
                'tin' => '123-456-789', 'branch_code' => '00000', 'contact_number' => '09171234567',
                'accounting_period' => 'calendar', 'payroll' => 'no', 'supplier_tax' => 'none',
                'customer_tax' => 'no', 'ppe' => 'no',
            ])->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', ['company_id' => $second->id]))
            ->assertSessionHas('active_company_id', $first->id);
        $this->assertSame('First client', $first->fresh()->name);
        $this->assertSame('Second client registered', $second->fresh()->name);
        $settings = CompanySetting::query()->where('workspace_key', $second->workspaceKey())->sole();
        $this->assertSame('Second client registered', $settings->profile['company_name']);
        $this->assertSame($second->id, $settings->profile['company_id']);
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_setup_still_rejects_an_unassigned_company(): void
    {
        $owner = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $this->actingAs($owner)->get(route('company.setup', ['company_id' => $company->id]))->assertForbidden();
    }
}
