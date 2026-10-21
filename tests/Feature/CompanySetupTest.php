<?php

namespace Tests\Feature;

use Tests\TestCase;

class CompanySetupTest extends TestCase
{
    public function test_company_setup_requires_a_preview_session(): void
    {
        $this->get('/company/setup')->assertRedirect('/signin');
        $this->post('/company/setup', $this->companyData())
            ->assertRedirect('/signin')
            ->assertSessionMissing('company_setup');
    }

    public function test_access_options_redirect_to_setup_without_authenticating_a_user(): void
    {
        foreach ([
            ['method' => 'google'],
            ['method' => 'workspace', 'workspace' => 'sample-team'],
            ['method' => 'email', 'identifier' => 'demo@example.com'],
        ] as $data) {
            $this->post('/preview/session', $data)
                ->assertRedirect('/company/setup')
                ->assertSessionHas('preview_access', true);
            $this->assertGuest();
        }

        $this->get('/company/setup')->assertOk()->assertViewIs('company-setup');
    }

    public function test_invalid_access_input_does_not_start_a_preview_session(): void
    {
        $this->post('/preview/session', ['method' => 'workspace', 'workspace' => '../private'])
            ->assertSessionHasErrors('workspace')
            ->assertSessionMissing('preview_access');

        $this->post('/preview/session', ['method' => 'email', 'identifier' => 'invalid identifier'])
            ->assertSessionHasErrors('identifier')
            ->assertSessionMissing('preview_access');
    }

    public function test_company_setup_is_saved_in_the_session_and_restored(): void
    {
        $data = $this->companyData();
        $this->withSession(['preview_access' => true])
            ->post('/company/setup', $data)
            ->assertRedirect('/dashboard')
            ->assertSessionHas('company_setup.company_name', 'Sample Studio')
            ->assertSessionHas('company_setup.vat_type', 'non_vat')
            ->assertSessionHas('company_setup.branch_code', '00000')
            ->assertSessionHas('status');

        $this->get('/company/setup')->assertOk()->assertSee('value="Sample Studio"', false);
    }

    public function test_required_and_invalid_company_fields_are_rejected_without_overwriting_saved_settings(): void
    {
        $saved = $this->companyData();
        $this->withSession(['preview_access' => true, 'company_setup' => $saved])
            ->from('/company/setup')
            ->post('/company/setup', [
                ...$saved,
                'full_name' => '',
                'company_name' => '',
                'preview_ack' => '0',
                'vat_type' => 'invalid',
                'tin' => 'bad',
                'contact_number' => 'invalid',
            ])
            ->assertRedirect('/company/setup')
            ->assertSessionHasErrors(['full_name', 'company_name', 'preview_ack', 'vat_type', 'tin', 'contact_number'])
            ->assertSessionHas('company_setup', $saved);
    }

    public function test_fiscal_year_requires_an_ending_month(): void
    {
        $this->withSession(['preview_access' => true])
            ->post('/company/setup', [...$this->companyData(), 'accounting_period' => 'fiscal'])
            ->assertSessionHasErrors('fiscal_end_month');

        $this->post('/company/setup', [...$this->companyData(), 'accounting_period' => 'fiscal', 'fiscal_end_month' => '6'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('company_setup.fiscal_end_month', '6');
    }

    public function test_exiting_demo_clears_company_and_access_data(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => $this->companyData()])
            ->post('/preview/exit')
            ->assertRedirect('/signin')
            ->assertSessionMissing('preview_access')
            ->assertSessionMissing('company_setup');

        $this->get('/company/setup')->assertRedirect('/signin');
    }

    public function test_preview_entry_and_company_submission_require_csrf_tokens(): void
    {
        $this->app->instance('env', 'production');
        $this->post('/preview/session', ['method' => 'google'])->assertStatus(419);
        $this->withSession(['preview_access' => true])
            ->post('/company/setup', $this->companyData())->assertStatus(419);
    }

    public function test_dashboard_requires_access_and_completed_company_setup(): void
    {
        $this->get('/dashboard')->assertRedirect('/signin');

        $this->withSession(['preview_access' => true])
            ->get('/dashboard')->assertRedirect('/company/setup');
    }

    public function test_dashboard_uses_saved_company_details_and_escapes_user_input(): void
    {
        $company = [...$this->companyData(), 'company_name' => '<script>alert(1)</script>'];

        $this->withSession(['preview_access' => true, 'company_setup' => $company])
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Non-VAT')
            ->assertSee('1 of 5 complete')
            ->assertSee('data-dashboard-overview', false)
            ->assertSee('dashboard-report.js')
            ->assertSee('Books Flow Pipeline')
            ->assertDontSee('TRIAL BALANCE')
            ->assertDontSee('BIR SCHEDULE STATUS')
            ->assertDontSee('TRANSACTION QUOTA')
            ->assertDontSee('100% Free Forever Core — Unlimited Access');
    }

    public function test_returning_preview_with_saved_company_redirects_to_dashboard(): void
    {
        $this->withSession(['company_setup' => $this->companyData()])
            ->post('/preview/session', ['method' => 'google'])
            ->assertRedirect('/dashboard');

        $this->post('/preview/exit')->assertRedirect('/signin');
        $this->get('/dashboard')->assertRedirect('/signin');
    }

    /** @return array<string, string> */
    private function companyData(): array
    {
        return [
            'full_name' => 'Demo Owner',
            'preview_ack' => '1',
            'vat_type' => 'non_vat',
            'entity_type' => 'individual',
            'company_name' => 'Sample Studio',
            'tin' => '123-456-789-00000',
            'branch_code' => '000',
            'contact_number' => '0917 123 4567',
            'accounting_period' => 'calendar',
            'payroll' => 'no',
            'supplier_tax' => 'none',
            'customer_tax' => 'no',
            'ppe' => 'no',
        ];
    }
}
