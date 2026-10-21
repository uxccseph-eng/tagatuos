<?php

namespace Tests\Feature;

use Tests\TestCase;

class PayrollPageTest extends TestCase
{
    public function test_payroll_requires_access_and_a_company(): void
    {
        $this->get('/sheets/payroll')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/sheets/payroll')->assertRedirect('/company/setup');
    }

    public function test_payroll_renders_employee_grid_and_escapes_company_details(): void
    {
        $this->withSession([
            'preview_access' => true,
            'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Sample Owner'],
        ])->get('/sheets/payroll')->assertOk()->assertViewIs('payroll')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Payroll runs')->assertSee('Add run')->assertSee('Employer Cost')
            ->assertSee('payroll-register.js')->assertSee('payroll.js')
            ->assertSee('payroll-details.js')->assertSee('id="payroll-entry"', false)
            ->assertSee('Close payroll entry')->assertSee('Payroll journal preview')
            ->assertSee('Attach payroll document')->assertSee('Payroll document link')
            ->assertSee('Edit payroll entry')
            ->assertSee('id="payroll-remittance"', false)->assertSee('Payroll runs')->assertSee('Liabilities to remit')->assertSee('Remit &amp; post', false)
            ->assertSee('payroll-remittance-dialog.js')
            ->assertSee('Working period From')->assertSee('Working period To')
            ->assertDontSee('payments.js')->assertSee('workspace-ledger.js')->assertSee('payroll-remittance.js')->assertSee('Remit liabilities')->assertSee('Remitted by');
    }

    public function test_existing_sheets_link_to_payroll_without_replacing_their_views(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Sample Owner']]);

        foreach (['sales', 'collections', 'cwt', 'purchases', 'payments', 'wtp', 'journal'] as $sheet) {
            $this->get('/sheets/'.$sheet)->assertOk()->assertViewIs($sheet)
                ->assertSee('href="'.route('sheets.payroll').'"', false);
        }
    }
}
