<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_defaults_and_visibility_are_saved_and_exposed_only_for_the_current_workspace(): void
    {
        $company = $this->company();
        $workspace = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($workspace);
        $settings = ['payroll' => '0', 'cwt' => '1', 'wtp' => '1', 'ppe' => '1', 'sales_hide_cwt' => '1', 'sales_hide_vat_cwt' => '1', 'sales_default_terms' => 'On Account', 'sales_default_classification' => 'Government', 'sales_default_bank_code' => '1020', 'sales_default_cwt_code' => 'WC160', 'sales_default_vat_cwt_code' => 'WB030'];
        $this->withSession(['preview_access' => true, 'company_setup' => $company])->put('/settings/sheets', $settings)
            ->assertSessionHasNoErrors()->assertSessionHas('company_setup.sheet_settings.sales_hide_cwt', true);
        $saved = CompanySetting::query()->where('workspace_key', $workspace)->sole();
        $this->assertSame('1020', $saved->preferences['sales_default_bank_code']);
        $this->assertSame('On Account', $saved->preferences['sales_default_terms']);
        $this->get('/settings/sheets')->assertOk()->assertSee('Sales columns and defaults')->assertSee('Hide VAT withholding column');
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('hideCwt', false)->assertSee('Government', false)->assertSee('WC160', false);
        $this->withSession(['preview_access' => true, 'company_setup' => [...$company, 'company_name' => 'Another Demo Client']]);
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertDontSee('"hideCwt":true', false)->assertDontSee('"terms":"On Account"', false);
        $this->assertDatabaseCount('company_settings', 1);
    }

    public function test_sales_defaults_reject_invalid_accounts_categories_and_choices(): void
    {
        $company = $this->company();
        ChartAccount::seedWorkspace(ChartAccount::workspaceKey($company));
        $this->withSession(['preview_access' => true, 'company_setup' => $company]);
        $this->putJson('/settings/sheets', ['payroll' => 1, 'cwt' => 1, 'wtp' => 1, 'ppe' => 1, 'sales_default_terms' => 'Invalid', 'sales_default_classification' => 'Invalid', 'sales_default_bank_code' => '4020', 'sales_default_cwt_code' => 'WB030', 'sales_default_vat_cwt_code' => 'WC160'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sales_default_terms', 'sales_default_classification', 'sales_default_bank_code', 'sales_default_cwt_code', 'sales_default_vat_cwt_code']);
        $this->assertDatabaseCount('company_settings', 0);
    }

    public function test_older_sheet_settings_requests_preserve_saved_sales_defaults(): void
    {
        $company = $this->company();
        $company['sheet_settings'] = ['sales_hide_cwt' => true, 'sales_default_terms' => 'On Account', 'sales_default_bank_code' => '1020'];
        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->put('/settings/sheets', ['payroll' => 1, 'cwt' => 1, 'wtp' => 1, 'ppe' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('company_setup.sheet_settings.sales_default_terms', 'On Account')->assertSessionHas('company_setup.sheet_settings.sales_hide_cwt', true);
    }

    /** @return array<string, mixed> */
    private function company(): array
    {
        return ['company_name' => 'Settings Test Company', 'full_name' => 'Test User', 'registered_name' => 'Settings Test Company', 'tin' => '000000000', 'branch_code' => '00000', 'entity_type' => 'corporation', 'vat_type' => 'non_vat', 'accounting_period' => 'calendar'];
    }

    public function test_settings_require_a_workspace_and_only_known_sections_are_available(): void
    {
        $this->get('/settings')->assertRedirect('/signin');
        $this->put('/settings/company', [])->assertForbidden();
        $this->withSession(['preview_access' => true])->get('/settings')->assertRedirect('/company/setup');
        $this->get('/settings/unknown')->assertNotFound();
    }

    public function test_all_four_settings_designs_render_with_current_company_details(): void
    {
        foreach (['company', 'address', 'bir', 'sheets'] as $section) {
            $this->withSession(['preview_access' => true, 'company_setup' => $this->company()])->get('/settings/'.$section)
                ->assertOk()->assertViewIs('settings')->assertSee('Settings Test Company')->assertSee('Save changes')->assertSee('settings-form')->assertDontSee('BIR connected');
        }
    }

    public function test_company_identity_changes_keep_masters_and_browser_transactions_linked(): void
    {
        $company = $this->company();
        $workspace = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($workspace);
        $this->withSession(['preview_access' => true, 'company_setup' => [...$company, 'browser_key' => 'original-browser-key']])
            ->put('/settings/company', [...$company, 'company_name' => 'Renamed Company', 'tin' => '111-222-333-00000'])
            ->assertRedirect('/settings/company')->assertSessionHasNoErrors()
            ->assertSessionHas('company_setup.workspace_key', $workspace)
            ->assertSessionHas('company_setup.browser_key', 'original-browser-key');
        $this->assertSame($workspace, ChartAccount::workspaceKey(session('company_setup')));
        $this->assertDatabaseHas('company_settings', ['workspace_key' => $workspace]);
        $this->get('/masters/chart-of-accounts')->assertOk()->assertSee('Cash on Hand');
        $this->get('/sheets/sales')->assertOk()->assertSee('data-key="original-browser-key"', false);
    }

    public function test_address_save_preserves_other_sections_and_rejects_invalid_values(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()])
            ->put('/settings/address', ['city' => 'Sample City', 'zip_code' => '1000', 'company_name' => 'Injected'])
            ->assertSessionHasNoErrors()->assertSessionHas('company_setup.city', 'Sample City')->assertSessionHas('company_setup.company_name', 'Settings Test Company');
        $this->put('/settings/address', ['zip_code' => 'bad'])->assertSessionHasErrors('zip_code')->assertSessionHas('company_setup.zip_code', '1000');
    }

    public function test_bir_fiscal_rules_branch_length_and_script_content_are_validated(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()])
            ->put('/settings/company', [...$this->company(), 'tin' => '000-000-000-0000'])->assertSessionHasErrors('tin');
        $this->put('/settings/bir', ['accounting_period' => 'fiscal', 'agent_category' => 'private', 'taxpayer_size' => 'small', 'top_withholding_agent' => '0'])->assertSessionHasErrors('fiscal_end_month');
        $this->put('/settings/bir', ['accounting_period' => 'fiscal', 'fiscal_end_month' => 6, 'rdo' => '53B', 'agent_category' => 'private', 'taxpayer_size' => 'small', 'top_withholding_agent' => '0', 'signatory_name' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $this->get('/settings/bir')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->put('/settings/bir', ['signatory_tin' => '123-456-789-00000', 'accounting_period' => 'calendar', 'rdo' => '113A', 'agent_category' => 'private', 'taxpayer_size' => 'small', 'top_withholding_agent' => '0'])->assertSessionHasNoErrors()->assertSessionHas('company_setup.rdo', '113A')->assertSessionHas('company_setup.signatory_tin', '123-456-789-00000');
    }

    public function test_optional_sheet_preferences_and_cash_account_are_workspace_scoped(): void
    {
        $company = $this->company();
        $key = ChartAccount::workspaceKey($company);
        ChartAccount::seedWorkspace($key);
        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->put('/settings/sheets', ['payroll' => '0', 'cwt' => '0', 'wtp' => '1', 'ppe' => '0', 'default_bank_code' => '1020'])
            ->assertSessionHasNoErrors()->assertSessionHas('company_setup.sheet_settings.payroll', false);
        $this->get('/sheets/sales')->assertOk()->assertDontSee('href="http://localhost/sheets/payroll"', false)->assertDontSee('href="http://localhost/sheets/cwt"', false)->assertSee('href="http://localhost/sheets/wtp"', false);
        $this->get('/masters/chart-of-accounts/catalog.js')->assertOk()->assertSee('defaultBank', false)->assertSee('Cash in Bank', false);
        $this->put('/settings/sheets', ['payroll' => '1', 'cwt' => '1', 'wtp' => '1', 'ppe' => '1', 'default_bank_code' => '4000'])->assertSessionHasErrors('default_bank_code');
    }

    public function test_renaming_cannot_join_an_existing_workspace(): void
    {
        $other = ['company_name' => 'Other Company', 'tin' => '000000000'];
        ChartAccount::seedWorkspace(ChartAccount::workspaceKey($other));
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()])
            ->put('/settings/company', [...$this->company(), ...$other, 'tin' => '000-000-000-00000'])->assertSessionHasErrors('company_name')->assertSessionHas('company_setup.company_name', 'Settings Test Company');
        $this->assertDatabaseCount('company_settings', 0);
    }

    public function test_payroll_automation_and_all_frequencies_are_saved_for_the_current_workspace(): void
    {
        $company = $this->company();
        $workspace = ChartAccount::workspaceKey($company);
        $this->withSession(['preview_access' => true, 'company_setup' => $company]);
        foreach (['monthly', 'semi_monthly', 'weekly'] as $frequency) {
            $this->put('/settings/sheets', ['payroll' => '1', 'cwt' => '1', 'wtp' => '1', 'ppe' => '1', 'payroll_automation' => '1', 'payroll_frequency' => $frequency])
                ->assertSessionHasNoErrors()->assertRedirect('/settings/sheets')
                ->assertSessionHas('company_setup.sheet_settings.payroll_automation', true)
                ->assertSessionHas('company_setup.sheet_settings.payroll_frequency', $frequency);
            $preferences = CompanySetting::query()->where('workspace_key', $workspace)->sole()->preferences;
            $this->assertTrue($preferences['payroll_automation']);
            $this->assertSame($frequency, $preferences['payroll_frequency']);
        }
        $this->get('/settings/sheets')->assertOk()->assertSee('Enable payroll automation')->assertSee('Semi-monthly')->assertSee('Weekly');
        $this->get('/sheets/payroll')->assertOk()->assertSee('payroll-automation.js')->assertSee('payroll-settings')
            ->assertSee('"payroll_automation":true', false)->assertSee('"payroll_frequency":"weekly"', false);
        $this->put('/settings/sheets', ['payroll' => '1', 'cwt' => '1', 'wtp' => '1', 'ppe' => '1', 'payroll_automation' => '0', 'payroll_frequency' => 'monthly'])
            ->assertSessionHasNoErrors()->assertSessionHas('company_setup.sheet_settings.payroll_automation', false);
    }

    public function test_invalid_payroll_automation_settings_do_not_change_saved_preferences(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()])
            ->put('/settings/sheets', ['payroll' => '1', 'cwt' => '1', 'wtp' => '1', 'ppe' => '1', 'payroll_automation' => 'yes', 'payroll_frequency' => 'daily'])
            ->assertSessionHasErrors(['payroll_automation', 'payroll_frequency']);
        $this->assertDatabaseCount('company_settings', 0);
    }

    public function test_persisted_settings_resume_with_the_original_database_identity(): void
    {
        $company = $this->company();
        $workspace = ChartAccount::workspaceKey($company);
        $renamed = [...$company, 'company_name' => 'Resume Company'];
        CompanySetting::factory()->create(['workspace_key' => $workspace, 'lookup_key' => ChartAccount::workspaceKey($renamed), 'profile' => $renamed, 'preferences' => ['payroll' => false]]);
        $this->withSession(['preview_access' => true])->post('/company/setup', [...$renamed, 'tin' => '000-000-000-00000', 'preview_ack' => '1', 'contact_number' => '09170000000', 'payroll' => 'no', 'supplier_tax' => 'none', 'customer_tax' => 'no', 'ppe' => 'no'])
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors()->assertSessionHas('company_setup.workspace_key', $workspace)->assertSessionHas('company_setup.sheet_settings.payroll', false);
    }
}
