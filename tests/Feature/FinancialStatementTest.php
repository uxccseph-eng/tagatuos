<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\FinancialStatement;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\FinancialStatementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_income_and_balance_sheet_are_reproduced_without_balancing_plugs(): void
    {
        $rows = [];
        foreach (['Direct Charges - Advertising' => 4941583.30, 'Direct Charges - Handling Fees' => 5372383.15, 'Direct Charges - Marketing' => 5447275.03, 'Direct Charges - Outsource (Influencers)' => 3849157.40, 'Direct Charges - Outsource (Photographers)' => 3139932.13, 'Direct Charges - Outsource (Videographers)' => 3635024.01, 'Representation and Entertainment' => 2572830.16, 'Salaries and Wages' => 7531785.04, 'Rent Expense' => 616500.00, 'Input VAT' => 3548962.20, 'CWT Receivable' => 418723.74] as $name => $debit) {
            $rows[] = compact('name', 'debit');
        }
        $rows[] = ['name' => 'Revenue', 'credit' => 20936188.43];
        $rows[] = ['name' => 'Trade and Other Payables', 'credit' => 1579832.46];
        $rows[] = ['name' => 'Cash in Bank', 'credit' => 18558135.27];
        $data = app(FinancialStatementCalculator::class)->calculate($rows);
        $income = array_column($data['income_statement']['rows'], 'current', 'label');
        $this->assertEquals(-28958185.18, $income['Less: Cost of Sales']);
        $this->assertEquals(-8148285.04, $income['Less: Operating Expenses']);
        $this->assertEquals(-16170281.79, $income['Net Income']);
        $this->assertEquals(0, $data['balance_sheet']['difference']);
        $this->assertEquals(0, $data['cash_flows']['difference']);
        $this->assertEquals(-14590449.33, array_column($data['balance_sheet']['rows'], 'current', 'label')['Total Assets']);
    }

    public function test_unbalanced_trial_balance_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(FinancialStatementCalculator::class)->calculate([['name' => 'Cash in Bank', 'debit' => 10]]);
    }

    public function test_unmapped_accounts_are_not_silently_excluded(): void
    {
        $this->expectException(ValidationException::class);
        app(FinancialStatementCalculator::class)->calculate([['name' => 'Mystery account', 'debit' => 0, 'credit' => 0]]);
    }

    public function test_comparatives_and_indirect_flows_use_opening_balances_and_actual_cash_activities(): void
    {
        $opening = [['name' => 'Cash in Bank', 'debit' => 100], ['name' => 'Share Capital', 'credit' => 100]];
        $current = [['name' => 'Cash in Bank', 'debit' => 170], ['name' => 'Share Capital', 'credit' => 100], ['name' => 'Revenue', 'credit' => 100], ['name' => 'Rent Expense', 'debit' => 30]];
        $data = app(FinancialStatementCalculator::class)->calculate($current, $opening, [], ['current' => ['investing' => 0, 'financing' => 0, 'warnings' => []], 'previous' => ['investing' => 0, 'financing' => 10000, 'warnings' => []]]);
        $this->assertEquals(70, $data['equity']['current'][2]['total']);
        $this->assertEquals(170, array_column($data['cash_flows']['rows'], 'current', 'label')['Cash, end of period']);
        $this->assertEquals(0, $data['cash_flows']['difference']);
        $this->assertEquals(0, $data['cash_flows']['previous_difference']);
    }

    public function test_generation_is_scoped_repeatable_and_exports_a_pdf(): void
    {
        [$company, $owner, $ledger] = $this->book();
        ChartAccount::factory()->create(['workspace_key' => $company->workspaceKey(), 'code' => '2400', 'name' => 'Short-term Bank Loan', 'type' => 'Liability', 'statement_section' => 'Current Liabilities', 'cash_flow_section' => 'Financing']);
        $this->postJournal($company, $owner, 'posted', 2026, ['1020' => [200, 0], '2400' => [0, 200]]);
        $this->postJournal($company, $owner, 'posted', 2026, ['1020' => [1000, 0], '3010' => [0, 1000]]);
        $this->postJournal($company, $owner, 'posted', 2026, ['1020' => [200, 0], '4020' => [0, 200]]);
        $this->postJournal($company, $owner, 'posted', 2026, ['7110' => [50, 0], '1020' => [0, 50]]);
        $this->postJournal($company, $owner, 'posted', 2026, ['1500' => [100, 0], '1020' => [0, 100]]);
        $this->postJournal($company, $owner, 'posted', 2026, ['1500' => [50, 0], '2010' => [0, 50]]);
        $this->postJournal($company, $owner, 'draft', 2026, ['1020' => [999, 0], '4020' => [0, 999]]);
        $this->postJournal($company, $owner, 'void', 2026, ['1020' => [999, 0], '4020' => [0, 999]]);
        $this->postJournal($company, $owner, 'posted', 2027, ['1020' => [999, 0], '4020' => [0, 999]]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeaders(['X-Company-ID' => (string) $company->id, 'X-Ledger-Revision' => '3']);
        $this->get('/reports/financial-statement?year=2026')->assertOk()->assertSee('Generate and save');
        $url = route('financial-statements.generate', ['auditPackId' => $ledger->id]);
        $response = $this->postJson($url, ['reporting_year' => 2026])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.balance_sheet.difference', 0);
        $this->assertEquals(150, array_column($response->json('data.income_statement.rows'), 'current', 'label')['Net Income']);
        $this->assertEquals(0, $response->json('data.cash_flows.difference'));
        $cashRows = array_column($response->json('data.cash_flows.rows'), 'current', 'label');
        $this->assertEquals(-100, $cashRows['Net cash from investing activities']);
        $this->assertEquals(1200, $cashRows['Net cash from financing activities']);
        $this->assertEquals(-50, $cashRows['Non-cash investing / financing movements in working capital']);
        $this->postJson($url, ['reporting_year' => 2026])->assertOk();
        $this->assertDatabaseCount('financial_statements', 1);
        $this->get('/reports/financial-statement/pdf?year=2026')->assertOk()->assertHeader('content-type', 'application/pdf');
        DB::table('company_ledgers')->where('id', $ledger->id)->update(['revision' => 4]);
        $this->getJson('/reports/financial-statement/pdf?year=2026')->assertConflict();
        DB::table('company_ledgers')->where('id', $ledger->id)->update(['revision' => 3]);
        $other = Company::factory()->create();
        $otherLedger = CompanyLedger::factory()->create(['company_id' => $other->id]);
        $this->postJson(route('financial-statements.generate', ['auditPackId' => $otherLedger->id]), ['reporting_year' => 2026])->assertNotFound();
        $this->withHeader('X-Ledger-Revision', '2')->postJson($url, ['reporting_year' => 2026])->assertConflict();
        $this->assertDatabaseCount('financial_statements', 1);
    }

    public function test_failed_calculation_preserves_previous_saved_statement(): void
    {
        [$company, $owner, $ledger] = $this->book();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeaders(['X-Company-ID' => (string) $company->id, 'X-Ledger-Revision' => '3']);
        $url = route('financial-statements.generate', ['auditPackId' => $ledger->id]);
        $this->postJson($url, ['reporting_year' => 2026])->assertOk();
        $saved = FinancialStatement::query()->withoutGlobalScopes()->sole()->toArray();
        $this->postJournal($company, $owner, 'posted', 2026, ['1020' => [10, 0]]);
        $this->postJson($url, ['reporting_year' => 2026])->assertUnprocessable()->assertJsonValidationErrors('trial_balance');
        $this->assertEquals($saved, FinancialStatement::query()->withoutGlobalScopes()->sole()->toArray());
    }

    public function test_reports_permission_and_explicit_company_context_are_required(): void
    {
        [$company, $owner, $ledger] = $this->book();
        $this->getJson(route('financial-statements.generate', ['auditPackId' => $ledger->id]))->assertMethodNotAllowed();
        $user = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($user, ['role' => 'staff', 'allowed_tabs' => json_encode(['sheets'])]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        $this->getJson('/reports/financial-statement')->assertForbidden();
    }

    /** @return array{Company, User, CompanyLedger} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'tin' => '000000000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        $ledger = CompanyLedger::factory()->create(['company_id' => $company->id, 'revision' => 3, 'state' => CompanyLedgerService::emptyState()]);

        return [$company, $owner, $ledger];
    }

    /** @param array<string, array{int, int}> $lines */
    private function postJournal(Company $company, User $owner, string $status, int $year, array $lines): void
    {
        $number = (string) (DB::table('journal_entries')->count() + 1);
        $id = DB::table('journal_entries')->insertGetId(['company_id' => $company->id, 'source_key' => $number, 'sheet' => 'journalEntries', 'number' => $number, 'transaction_date' => $year.'-06-01', 'status' => $status, 'posted_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $code => [$debit, $credit]) {
            $account = ChartAccount::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
            DB::table('journal_entry_lines')->insert(['company_id' => $company->id, 'journal_entry_id' => $id, 'chart_account_id' => $account->id, 'account_name' => $account->name, 'debit' => $debit, 'credit' => $credit]);
        }
    }
}
