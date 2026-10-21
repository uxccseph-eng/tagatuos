<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinancialReportsTest extends TestCase
{
    public function test_reports_require_preview_and_company_setup(): void
    {
        $this->get('/reports/trial-balance')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/reports/balance-sheet')->assertRedirect('/company/setup');
    }

    public function test_five_reports_share_books_style_navigation_and_exports(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Report QA', 'full_name' => 'Preview']]);
        foreach (['trial-balance', 'balance-sheet', 'income-statement', 'cash-flow', 'changes-in-equity'] as $report) {
            $this->get('/reports/'.$report)->assertOk()->assertViewIs('financial-reports')->assertSee('Financial Reports navigation')->assertSee('books-tabs-wrap')->assertSee('financial-table')->assertSee('financial-report-data.js')->assertSeeInOrder(['financial-report-template.js', 'financial-reports.js'])->assertSeeInOrder(['financial-account-breakdown.js', 'financial-reports.js'])->assertSee('data-transactions-url', false)->assertDontSee('id="financial-breakdown"', false)->assertSee('financial-company-name')->assertSee('financial-document')->assertSee('financial-generated')->assertSee('working-period.js')->assertSee('data-period-from', false)->assertSee('data-period-to', false)->assertSee('financial-quarter')->assertSeeInOrder(['financial-periods.js', 'financial-report-template.js'])->assertSee('financial-excel')->assertSee('financial-pdf')->assertSee('financial-csv')->assertViewHas('report', $report);
        }
        $this->get('/reports/unknown')->assertNotFound();
        $this->get('/reports')->assertRedirect('/reports/trial-balance');
    }

    public function test_transaction_report_requires_workspace_and_valid_report_selection(): void
    {
        $this->get('/reports/income-statement/transactions')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/reports/income-statement/transactions')->assertRedirect('/company/setup');
        $this->withSession(['company_setup' => ['company_name' => 'Report QA', 'full_name' => 'Preview']]);
        $this->getJson('/reports/income-statement/transactions')->assertUnprocessable()->assertJsonValidationErrors(['account', 'row', 'from', 'to']);
        $this->get('/reports/unknown/transactions')->assertNotFound();
    }

    public function test_transaction_page_has_full_detail_columns_excel_export_and_return_context(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Report QA', 'full_name' => 'Preview']]);
        $query = http_build_query(['account' => '4020', 'row' => 'income:4020', 'from' => '2025-10-01', 'to' => '2025-10-31', 'return_from' => '2026-10-01', 'return_to' => '2026-10-31', 'mode' => 'comparative']);
        $this->get('/reports/income-statement/transactions?'.$query)->assertOk()->assertViewIs('financial-transactions')->assertSee('Transaction Report')->assertSee('Transaction date')->assertSee('Split account')->assertSee('Balance')->assertSee('Export Excel')->assertSee('2026-10-01')->assertSee('comparative')->assertSeeInOrder(['financial-account-breakdown.js', 'financial-transactions.js'])->assertDontSee('id="financial-breakdown"', false);
    }
}
