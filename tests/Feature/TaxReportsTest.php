<?php

namespace Tests\Feature;

use App\Http\Controllers\TaxReportsController;
use Tests\TestCase;

class TaxReportsTest extends TestCase
{
    public function test_tax_reports_require_a_workspace(): void
    {
        $this->get('/tax-reports')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/tax-reports')->assertRedirect('/company/setup');
    }

    public function test_all_schedule_tabs_render_current_company_and_review_controls(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Tax QA Company', 'full_name' => 'Test User', 'accounting_period' => 'calendar', 'vat_type' => 'vat', 'tin' => '000000000', 'rdo' => '000']]);
        $this->get('/tax-reports')->assertOk()->assertViewHas('schedule', 'relief-sales');
        foreach (array_keys(TaxReportsController::SCHEDULES) as $schedule) {
            $this->get('/tax-reports/'.$schedule)->assertOk()->assertViewIs('tax-reports')->assertViewHas('schedule', $schedule)->assertSee('Tax Reports navigation')->assertSee('Tax QA Company')->assertSee('tax-quarter')->assertSee('tax-excel')->assertSee('tax-issues')->assertSee('Not BIR validated')->assertSeeInOrder(['tax-report-data.js', 'tax-reports.js'])->assertDontSee('Verified clean by BIR')->assertDontSee('TRND MARKETING');
        }
        $this->get('/tax-reports/unknown')->assertNotFound();
    }

    public function test_sawt_includes_reference_controls_and_source_navigation(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'SAWT test', 'full_name' => 'Test User', 'entity_type' => 'corporation']])
            ->get('/tax-reports/sawt?from=2026-10-01&to=2026-12-31&year=2026')
            ->assertOk()->assertSee('tax-reference-layout')->assertSee('SAWT tax type')->assertSee('Income Tax')->assertSee('sawt-form')->assertSee('1702Q')->assertSee('1701Q')->assertSee('1702 · Annual (Non-individual)')->assertSee('1701 · Annual (Individual)')->assertSee('2550Q')->assertSee('Open CWT sheet')->assertSee('sawt-monitor-link')->assertSee('sawt-dat-dialog')->assertSee('reports/generate-sawt-dat')->assertSee('7-field control row with return period and totals');
    }

    public function test_qap_page_has_schedule_switches_totals_and_reusable_certificate_preview(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'QAP Company', 'full_name' => 'Test User']])
            ->get('/tax-reports/qap-ewt')->assertOk()->assertSee('QAP schedule')->assertSee('Sched 1 · Withheld')->assertSee('Sched 2 · No withholding')->assertSee('form2307-dialog')->assertSee('reports/generate-qap-dat')->assertSee('1604E DAT')->assertSee('reports/generate-1604e-dat')->assertSee('Open WTP sheet')->assertDontSee('Checksum Verified');
    }

    public function test_sidebar_routes_to_the_new_tax_reports_page(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Tax QA Company', 'full_name' => 'Test User', 'accounting_period' => 'calendar', 'vat_type' => 'vat', 'tin' => '000000000', 'rdo' => '000']]);
        $this->get('/reports/trial-balance')->assertOk()->assertSee('href="http://localhost/tax-reports"', false);
        $this->get('/dashboard')->assertOk()->assertSee('href="http://localhost/tax-reports"', false);
    }
}
