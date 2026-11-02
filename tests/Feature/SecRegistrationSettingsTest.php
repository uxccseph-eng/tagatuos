<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecRegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function company(): array
    {
        return ['company_name' => 'SEC Test Company', 'full_name' => 'Test User', 'registered_name' => 'SEC Test Company', 'tin' => '000000000', 'branch_code' => '00000', 'entity_type' => 'corporation', 'vat_type' => 'vat', 'accounting_period' => 'calendar', 'sheet_settings' => ['purchase_default_terms' => 'Cash']];
    }

    /** @return array<string, string> */
    private function registration(): array
    {
        return ['registration_date' => '2023-10-20', 'sec_registration_number' => '2023100122011-00', 'authorized_shares' => '3000000', 'par_value_per_share' => '100.0000', 'issued_outstanding_shares' => '30000', 'going_concern_statement' => 'Management assessment for the reporting period.', 'deficiency_tax_assessments' => 'No assessments as of the reporting date.', 'tax_cases' => 'No pending cases as of the reporting date.'];
    }

    public function test_sec_page_requires_a_workspace_and_renders_blank_disclosures(): void
    {
        $this->get('/settings/sec')->assertRedirect('/signin');
        $this->put('/settings/sec', $this->registration())->assertForbidden();
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()])
            ->get('/settings/sec')->assertOk()->assertSee('SEC Registration')->assertSee('Going concern statement')->assertSee('Authorized shares')->assertSee('<textarea', false);
    }

    public function test_registration_and_disclosures_persist_without_changing_other_settings(): void
    {
        $company = $this->company();
        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->put('/settings/sec', [...$this->registration(), 'company_name' => 'Injected name'])
            ->assertSessionHasNoErrors()->assertRedirect('/settings/sec')
            ->assertSessionHas('company_setup.registration_date', '2023-10-20')
            ->assertSessionHas('company_setup.company_name', 'SEC Test Company');
        $saved = CompanySetting::query()->where('workspace_key', ChartAccount::workspaceKey($company))->sole();
        foreach ($this->registration() as $key => $value) {
            $this->assertSame($value, $saved->profile[$key]);
        }
        $this->assertSame('Cash', $saved->preferences['purchase_default_terms']);
        $this->get('/settings/sec')->assertOk()->assertSee('2023100122011-00')->assertSee('Management assessment for the reporting period.');
        $this->get('/settings/bir')->assertOk()->assertSee('value="2023-10-20"', false);
        $this->withSession(['preview_access' => true, 'company_setup' => [...$company, 'company_name' => 'Other Client']]);
        $this->get('/settings/sec')->assertOk()->assertDontSee('value="2023100122011-00"', false);
    }

    public function test_invalid_dates_shares_par_values_and_oversized_disclosures_are_rejected(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->company()]);
        $this->putJson('/settings/sec', ['registration_date' => '2023-02-30', 'sec_registration_number' => str_repeat('X', 256), 'authorized_shares' => '-1', 'par_value_per_share' => '-100', 'issued_outstanding_shares' => '1.5', 'tax_cases' => str_repeat('X', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['registration_date', 'sec_registration_number', 'authorized_shares', 'par_value_per_share', 'issued_outstanding_shares', 'tax_cases']);
        $this->putJson('/settings/sec', ['authorized_shares' => '100', 'issued_outstanding_shares' => '101', 'par_value_per_share' => '1.00001'])
            ->assertUnprocessable()->assertJsonValidationErrors(['issued_outstanding_shares', 'par_value_per_share']);
        $this->assertDatabaseCount('company_settings', 0);
    }

    public function test_no_par_value_and_blank_optional_details_can_be_saved_and_text_is_escaped(): void
    {
        $company = $this->company();
        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->put('/settings/sec', ['par_value_per_share' => '', 'tax_cases' => '<script>alert(1)</script>'])
            ->assertSessionHasNoErrors();
        $this->assertNull(CompanySetting::query()->sole()->profile['par_value_per_share']);
        $this->get('/settings/sec')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_individuals_save_disclosures_without_overwriting_birth_date_or_corporate_details(): void
    {
        $company = [...$this->company(), 'entity_type' => 'individual', 'registration_date' => '1990-01-01'];
        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->put('/settings/sec', $this->registration())->assertSessionHasNoErrors()
            ->assertSessionHas('company_setup.registration_date', '1990-01-01');
        $saved = CompanySetting::query()->sole()->profile;
        $this->assertArrayNotHasKey('sec_registration_number', $saved);
        $this->assertSame('No pending cases as of the reporting date.', $saved['tax_cases']);
        $this->get('/settings/sec')->assertOk()->assertSee('do not apply to this individual taxpayer')->assertSee('disabled', false);
    }
}
