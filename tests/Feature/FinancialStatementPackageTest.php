<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\FinancialStatement;
use App\Models\User;
use App\SecRegistrationRules;
use App\Services\FinancialStatementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialStatementPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_package_uses_profile_and_disclosures_and_preserves_annual_inputs(): void
    {
        [$company, $ledger] = $this->book();
        $inputs = ['business_activity' => 'Marketing services', 'reporting_framework' => 'Management-selected reporting framework', 'president_name' => 'Example President', 'management_confirmed' => true, 'approval_date' => '2027-03-01', 'approval_body' => 'Board of Directors', 'subsequent_events' => 'Management reviewed events through the approval date.', 'going_concern_statement' => ''];
        $statement = app(FinancialStatementCalculator::class)->generate($company, $ledger->id, 2026, 1, $inputs);
        $this->assertSame('SEC-EXAMPLE', $statement->package['profile']['sec_registration_number']);
        $this->assertSame(SecRegistrationRules::DISCLOSURE_DEFAULTS['going_concern_statement'], $statement->package['inputs']['going_concern_statement']);
        $this->assertSame('Example President', $statement->package['inputs']['president_name']);
        $this->assertTrue($statement->package['inputs']['management_confirmed']);
        $html = view('financial-statement-document', compact('statement'))->render();
        foreach (['Financial Statements', 'Management’s Responsibility', '1. Reporting Entity', '2. Basis of Preparation', '3. Summary of Significant Accounting Policies', '12. Shareholders', 'SEC-EXAMPLE', '18.1 Output VAT', '18.2 Input VAT', '18.3 Documentary Stamp Tax', '18.4 All Other Local', '18.5 Withholding Taxes', '18.6 Deficiency', '19. Events', '20. Approval', 'Package SHA-256'] as $section) {
            $this->assertStringContainsString($section, $html);
        }
        $regenerated = app(FinancialStatementCalculator::class)->generate($company, $ledger->id, 2026, 1);
        $this->assertSame('Example President', $regenerated->package['inputs']['president_name']);
        $otherYear = app(FinancialStatementCalculator::class)->generate($company, $ledger->id, 2025, 1);
        $this->assertFalse($otherYear->package['inputs']['management_confirmed']);
        $this->assertNotSame('Example President', $otherYear->package['inputs']['president_name']);
        $snapshot = $statement->package['profile'];
        CompanySetting::query()->where('company_id', $company->id)->update(['profile' => ['company_name' => 'Changed company settings']]);
        $this->assertSame($snapshot, FinancialStatement::query()->whereKey($statement->id)->firstOrFail()->package['profile']);
    }

    public function test_annual_tax_schedules_distinguish_movements_from_balances_and_exclude_other_clients_and_drafts(): void
    {
        [$company, $ledger, $owner] = $this->book();
        $expense = ChartAccount::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Direct Charges - Advertising', 'type' => 'Expense', 'statement_section' => 'Cost of Goods Sold', 'relief_category' => 'services']);
        $accounts = ChartAccount::query()->where('company_id', $company->id)->get()->keyBy('code');
        $this->journal($company, $owner, '2025-01-01', [[$accounts['2110']->id, 10, 0], [$accounts['3010']->id, 0, 10]]);
        $this->journal($company, $owner, '2026-01-01', [[$expense->id, 100, 0], [$accounts['2110']->id, 12, 0], [$accounts['2010']->id, 0, 112]]);
        $this->journal($company, $owner, '2026-01-02', [[$accounts['1020']->id, 224, 0], [$accounts['4020']->id, 0, 200], [$accounts['2210']->id, 0, 24]]);
        $this->journal($company, $owner, '2026-01-03', [[$accounts['2210']->id, 3, 0], [$accounts['2110']->id, 0, 3]]);
        $this->journal($company, $owner, '2026-01-04', [[$accounts['2010']->id, 10, 0], [$accounts['2140']->id, 0, 10]]);
        $this->journal($company, $owner, '2026-01-05', [[$accounts['2140']->id, 5, 0], [$accounts['1020']->id, 0, 5]]);
        $this->journal($company, $owner, '2026-01-06', [[$accounts['1020']->id, 999, 0], [$accounts['2210']->id, 0, 999]], 'draft');
        [$other, $otherLedger, $otherOwner] = $this->book();
        $otherAccounts = ChartAccount::query()->where('company_id', $other->id)->get()->keyBy('code');
        $this->journal($other, $otherOwner, '2026-02-01', [[$otherAccounts['1020']->id, 777, 0], [$otherAccounts['2210']->id, 0, 777]]);
        $statement = app(FinancialStatementCalculator::class)->generate($company, $ledger->id, 2026, 1);
        $tax = $statement->package['tax']['current'];
        $this->assertEquals(200, $tax['output_base']);
        $this->assertEquals(24, $tax['output_vat']);
        $this->assertEquals(10, $tax['input_opening']);
        $this->assertEquals(12, $tax['services_cost']);
        $this->assertEquals(-3, $tax['input_adjustments']);
        $this->assertEquals(19, $tax['input_closing']);
        $this->assertEquals(0, $tax['input_difference']);
        $this->assertEquals(10, $tax['ewt']);
        $this->assertEquals(0, $statement->package['tax']['previous']['output_vat']);
    }

    public function test_unknown_input_vat_classifications_remain_visible_and_manual_zero_is_respected(): void
    {
        [$company, $ledger, $owner] = $this->book();
        $accounts = ChartAccount::query()->where('company_id', $company->id)->get()->keyBy('code');
        $this->journal($company, $owner, '2026-01-01', [[$accounts['7110']->id, 100, 0], [$accounts['2110']->id, 12, 0], [$accounts['2010']->id, 0, 112]]);
        $service = app(FinancialStatementCalculator::class);
        $statement = $service->generate($company, $ledger->id, 2026, 1);
        $this->assertEquals(12, $statement->package['tax']['current']['unclassified']);
        $this->assertStringContainsString('classify input VAT', implode(' ', $statement->package['warnings']));
        $statement = $service->generate($company, $ledger->id, 2026, 1, ['tax_overrides' => ['current' => ['unclassified' => 0, 'services_other' => 12, 'output_vat' => null]]]);
        $this->assertEquals(0, $statement->package['tax']['current']['unclassified']);
        $this->assertEquals(12, $statement->package['tax']['current']['services_other']);
        $this->assertEquals(0, $statement->package['tax']['current']['input_difference']);
        $this->assertContains('unclassified', $statement->package['tax']['current']['overridden']);
        $this->assertNotContains('output_vat', $statement->package['tax']['current']['overridden']);
    }

    public function test_package_request_validates_inputs_and_escapes_management_text(): void
    {
        [$company, $ledger, $owner] = $this->book();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeaders(['X-Company-ID' => (string) $company->id, 'X-Ledger-Revision' => '1']);
        $url = route('financial-statements.generate', ['auditPackId' => $ledger->id]);
        $this->postJson($url, ['reporting_year' => 2026, 'package_inputs' => ['tax_overrides' => ['current' => ['dst' => 'not a number']]]])->assertUnprocessable();
        $this->postJson($url, ['reporting_year' => 2026, 'package_inputs' => ['company_id' => 999]])->assertUnprocessable();
        $response = $this->postJson($url, ['reporting_year' => 2026, 'package_inputs' => ['business_activity' => '<script>bad()</script>', 'president_name' => 'Approved Name']])->assertOk();
        $this->assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $response->json('html'));
        $this->assertStringNotContainsString('<script>bad()', $response->json('html'));
        $this->get('/reports/financial-statement?year=2026')->assertOk()->assertSee('Approved Name')->assertSee('Package details, disclosures and tax schedules');
    }

    public function test_management_review_is_reset_when_source_ledger_revision_changes(): void
    {
        [$company, $ledger] = $this->book();
        $calculator = app(FinancialStatementCalculator::class);
        $calculator->generate($company, $ledger->id, 2026, 1, ['management_confirmed' => true]);
        $ledger->update(['revision' => 2]);
        $statement = $calculator->generate($company, $ledger->id, 2026, 2);
        $this->assertFalse($statement->package['inputs']['management_confirmed']);
    }

    public function test_income_tax_note_uses_the_account_statement_section(): void
    {
        $data = app(FinancialStatementCalculator::class)->calculate([
            ['name' => 'Cash in Bank', 'type' => 'Asset', 'debit' => 85],
            ['name' => 'Sales', 'type' => 'Income', 'credit' => 100],
            ['name' => 'Current corporate tax provision', 'type' => 'Expense', 'statement_section' => 'Income Tax Expense', 'debit' => 15],
        ]);
        $rows = array_column($data['income_statement']['rows'], 'current', 'label');
        $this->assertEquals(-15, $rows['Income Tax Expense']);
        $this->assertEquals(85, $rows['Net Income']);
    }

    /** @return array{Company, CompanyLedger, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'tin' => '000000000', 'entity_type' => 'corporation', 'sec_registration_number' => 'SEC-EXAMPLE', 'authorized_shares' => 1000, 'par_value_per_share' => '100', 'issued_outstanding_shares' => 100]]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 1]);

        return [$company, $ledger, $owner];
    }

    /** @param list<array{int, int, int}> $lines */
    private function journal(Company $company, User $owner, string $date, array $lines, string $status = 'posted'): void
    {
        $number = (string) (DB::table('journal_entries')->count() + 1);
        $id = DB::table('journal_entries')->insertGetId(['company_id' => $company->id, 'source_key' => $number, 'sheet' => 'journalEntries', 'number' => $number, 'transaction_date' => $date, 'status' => $status, 'posted_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$accountId, $debit, $credit]) {
            DB::table('journal_entry_lines')->insert(['company_id' => $company->id, 'journal_entry_id' => $id, 'chart_account_id' => $accountId, 'account_name' => 'Stored account', 'debit' => $debit, 'credit' => $credit]);
        }
    }
}
