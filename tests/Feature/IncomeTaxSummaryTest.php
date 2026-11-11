<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\CorporateIncomeWorksheet;
use App\Services\Tax\IncomeTaxSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncomeTaxSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $book;

    private User $owner;

    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 11));
        $this->owner = User::factory()->firm()->create(['must_change_password' => false]);
        $this->book = Company::factory()->create();
        $this->book->users()->attach($this->owner, ['role' => 'owner']);
        $this->profile = ['company_name' => 'Income tax demo', 'entity_type' => 'corporation', 'vat_type' => 'vat', 'full_name' => 'Owner', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033'];
        CompanySetting::factory()->create(['workspace_key' => $this->book->workspaceKey(), 'lookup_key' => $this->book->workspaceKey(), 'profile' => $this->profile]);
        ChartAccount::seedWorkspace($this->book->workspaceKey());
        $this->actingAs($this->owner)->withSession(['active_company_id' => $this->book->id]);
    }

    private function input(string $action, array $extra = []): array
    {
        return ['action' => $action, 'year' => 2026, 'request_id' => (string) Str::uuid(), ...$extra];
    }

    private function preview(array $input): array
    {
        return $this->postJson(route('tax-summary.income.preview', ['company_id' => $this->book->id]), $input)->assertOk()->json();
    }

    private function record(array $input): array
    {
        $preview = $this->preview($input);
        $this->postJson(route('tax-summary.income.store', ['company_id' => $this->book->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertOk();

        return $preview;
    }

    private function summary(): array
    {
        return app(IncomeTaxSummaryService::class)->build($this->book, $this->profile, 2026);
    }

    private function settings(array $extra = []): void
    {
        $this->record($this->input('settings', ['rate' => '25', 'mcit' => 'not_applicable', ...$extra]));
    }

    private function books(): void
    {
        $operations = [];
        foreach ([['q1', '2026-01-10', 1000000, 300000, 100000], ['q2', '2026-04-10', 2000000, 500000, 200000]] as [$id,$date,$sales,$cost,$expense]) {
            $operations[] = ['sheet' => 'journalEntries', 'id' => $id, 'expected_version' => 0, 'record' => ['id' => $id, 'date' => $date, 'number' => '', 'status' => 'posted', 'lines' => [['account' => '1010', 'debit' => $sales, 'credit' => 0], ['account' => '4000', 'debit' => 0, 'credit' => $sales], ['account' => '5000', 'debit' => $cost, 'credit' => 0], ['account' => '7110', 'debit' => $expense, 'credit' => 0], ['account' => '1010', 'debit' => 0, 'credit' => $cost + $expense]]]];
        }
        app(CompanyLedgerService::class)->save($this->book, $this->owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => $operations]);
    }

    public function test_page_shows_book_sources_without_assuming_unreviewed_tax_settings(): void
    {
        $this->books();
        $page = $this->get(route('tax-summary.index', ['company_id' => $this->book->id, 'tax' => 'income', 'year' => 2026]))->assertOk()->assertSee('NOLCO register')->assertSee('Review this year');
        $s = $page->viewData('summary');
        $this->assertSame(1000000, $s['columns'][1]['amounts']['sales']);
        $this->assertSame(1900000, $s['columns'][2]['amounts']['taxable']);
        $this->assertNull($s['columns'][1]['amounts']['tax']);
        $this->assertDatabaseCount('income_tax_summary_events', 0);
        $this->getJson(route('tax-summary.income.sources', ['company_id' => $this->book->id, 'year' => 2026, 'quarter' => 1, 'key' => 'sales']))->assertOk()->assertJsonPath('total', 1000000)->assertJsonCount(1, 'rows');
    }

    public function test_cumulative_corporate_computation_and_prior_tax_payments_exclude_penalties(): void
    {
        $this->books();
        $this->settings();
        $this->record($this->input('payment', ['quarter' => 1, 'date' => '2026-05-01', 'amount' => '1510', 'penalty' => '10', 'post_now' => false]));
        $s = $this->summary();
        $this->assertSame(150000, $s['columns'][1]['amounts']['tax']);
        $this->assertSame(475000, $s['columns'][2]['amounts']['tax']);
        $this->assertSame(150000, $s['columns'][2]['amounts']['credits']);
        $this->assertSame(325000, $s['columns'][2]['amounts']['net']);
        $this->assertSame(475000, $s['columns'][4]['amounts']['tax']);
        $this->assertDatabaseCount('journal_entries', 2);
        $suggestion = app(IncomeTaxSummaryService::class)->quarterlySuggestion($s, 2);
        $this->assertSame('6000', $suggestion['previous_taxable']);
        $this->assertSame('1500', $suggestion['credit_regular_previous']);
    }

    public function test_nolco_register_allocation_expiry_and_partial_reversal_are_preserved(): void
    {
        $this->books();
        $this->settings();
        $this->record($this->input('nolco', ['origin_year' => 2025, 'amount' => '5000']));
        $id = DB::table('income_tax_summary_events')->where('action', 'nolco')->value('id');
        $this->record($this->input('apply_nolco', ['credit_id' => $id, 'quarter' => 1, 'amount' => '2000']));
        $s = $this->summary();
        $this->assertSame(400000, $s['columns'][1]['amounts']['taxable']);
        $this->assertSame(1700000, $s['columns'][2]['amounts']['taxable']);
        $this->assertSame(300000, collect($s['register'])->firstWhere('id', $id)['remaining']);
        $use = DB::table('income_tax_summary_events')->where('action', 'apply_nolco')->value('id');
        $this->record($this->input('reverse', ['credit_id' => $use, 'amount' => '1000']));
        $this->assertSame(400000, collect($this->summary()['register'])->firstWhere('id', $id)['remaining']);
        $this->record($this->input('nolco', ['origin_year' => 2020, 'amount' => '100']));
        $expired = DB::table('income_tax_summary_events')->where('action', 'nolco')->orderByDesc('id')->first();
        $this->assertSame(2025, json_decode($expired->data, true)['expires']);
        $this->postJson(route('tax-summary.income.preview', ['company_id' => $this->book->id]), $this->input('apply_nolco', ['credit_id' => $expired->id, 'quarter' => 2, 'amount' => '1']))->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_payments_post_to_prepaid_when_not_accrued_and_are_idempotent(): void
    {
        $input = $this->input('payment', ['quarter' => 1, 'date' => '2026-05-01', 'amount' => '110', 'penalty' => '10', 'post_now' => true, 'bank' => '1020']);
        $p = $this->record($input);
        $this->assertSame(['1160', '7290', '1020'], array_column($p['lines'], 'account'));
        $this->assertSame(11000, array_sum(array_column($p['lines'], 'debit')));
        $this->postJson(route('tax-summary.income.store', ['company_id' => $this->book->id]), [...$input, 'preview_hash' => $p['preview_hash'], 'confirmed' => true])->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('income_tax_summary_events', 1);
        $this->assertSame(0, $this->summary()['columns'][1]['amounts']['deductions']);
    }

    public function test_twenty_percent_requires_eligibility_and_mcit_uses_cumulative_gross(): void
    {
        $this->books();
        $this->postJson(route('tax-summary.income.preview', ['company_id' => $this->book->id]), $this->input('settings', ['rate' => '20', 'mcit' => 'applicable']))->assertUnprocessable();
        $this->settings(['rate' => '20', 'small_eligible' => true, 'mcit' => 'applicable']);
        $this->assertSame(120000, $this->summary()['columns'][1]['amounts']['tax']);
        $this->assertSame(44000, $this->summary()['columns'][2]['amounts']['mcit']);
    }

    public function test_scope_permissions_stale_previews_and_required_confirmation(): void
    {
        $input = $this->input('credit', ['origin_year' => 2025, 'credit_type' => 'prior_excess', 'amount' => '100']);
        $preview = $this->preview($input);
        $helper = User::factory()->accountant()->create(['must_change_password' => false]);
        $this->book->users()->attach($helper, ['role' => 'accountant']);
        $this->actingAs($helper);
        $this->record($this->input('credit', ['origin_year' => 2025, 'credit_type' => 'excess_cwt', 'amount' => '10']));
        $this->actingAs($this->owner);
        $this->postJson(route('tax-summary.income.store', ['company_id' => $this->book->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->postJson(route('tax-summary.income.store', ['company_id' => $this->book->id]), $input)->assertUnprocessable();
        $other = Company::factory()->create();
        $this->postJson(route('tax-summary.income.preview', ['company_id' => $other->id]), $input)->assertForbidden();
        $this->book->users()->updateExistingPivot($this->owner->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['tax-reports'])]);
        $this->postJson(route('tax-summary.income.preview', ['company_id' => $this->book->id]), $this->input('settings', ['rate' => '25', 'mcit' => 'not_applicable']))->assertForbidden();
    }

    public function test_fiscal_year_and_individual_profiles_preserve_reviewed_computation_boundary(): void
    {
        $this->profile = [...$this->profile, 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6];
        CompanySetting::query()->where('company_id', $this->book->id)->first()->update(['profile' => $this->profile]);
        $this->assertSame('2025-07-01', $this->summary()['columns'][1]['period']['from']);
        $this->profile = [...$this->profile, 'entity_type' => 'individual'];
        CompanySetting::query()->where('company_id', $this->book->id)->first()->update(['profile' => $this->profile]);
        $s = $this->summary();
        $this->assertSame('1701Q', $s['quarter_form']);
        $this->assertFalse($s['supported']);
        $this->assertNull($s['columns'][4]['amounts']['tax']);
    }

    public function test_whole_peso_rounding_matches_the_corporate_credit_fields(): void
    {
        $this->books();
        $this->settings();
        $ledger = CompanyLedger::query()->where('company_id', $this->book->id)->first();
        $state = $ledger->state;
        $state['cwtCertificates'] = [
            ['id' => 'cwt1', 'status' => 'posted', 'date' => '2026-01-30', 'atc' => 'WC160', 'amount' => 40],
            ['id' => 'cwt2', 'status' => 'posted', 'date' => '2026-04-30', 'atc' => 'WC160', 'amount' => 40],
            ['id' => 'vat', 'status' => 'posted', 'date' => '2026-04-30', 'atc' => 'WV010', 'amount' => 10000],
        ];
        $ledger->update(['state' => $state]);
        foreach (['regular', 'mcit'] as $type) {
            $this->record($this->input('payment', ['quarter' => 1, 'date' => '2026-05-01', 'amount' => '0.40', 'reference' => $type, 'subtype' => $type]));
        }
        $s = $this->summary();
        $suggestion = app(IncomeTaxSummaryService::class)->quarterlySuggestion($s, 2);
        $calculation = app(CorporateIncomeWorksheet::class)->calculate('1702Q', $suggestion, 2, 'itemized');
        $this->assertSame(0, $s['columns'][2]['amounts']['credits']);
        $this->assertSame($calculation['credits'], $s['columns'][2]['amounts']['credits']);
        $this->assertSame(100, $s['columns'][4]['amounts']['credits']);
        $this->postJson(route('tax-summary.income.preview', ['company_id' => $this->book->id]), $this->input('settings', ['rate' => 20, 'mcit' => 'not_applicable']))->assertUnprocessable();
    }

    public function test_payment_clears_accrual_then_prepays_the_rest_and_custom_penalties_are_not_deductions(): void
    {
        ChartAccount::factory()->create(['workspace_key' => $this->book->workspaceKey(), 'code' => '2160', 'name' => 'Income Tax Payable', 'type' => 'Liability']);
        app(CompanyLedgerService::class)->save($this->book, $this->owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'accrual', 'expected_version' => 0, 'record' => ['id' => 'accrual', 'date' => '2026-01-01', 'number' => '', 'status' => 'posted', 'lines' => [['account' => '1010', 'debit' => 4000, 'credit' => 0], ['account' => '2160', 'debit' => 0, 'credit' => 4000]]]]]]);
        $p = $this->record($this->input('payment', ['quarter' => 1, 'date' => '2026-05-01', 'amount' => '110', 'penalty' => '10', 'post_now' => true, 'bank' => '1020', 'penalty_account' => '7140']));
        $this->assertSame(['2160', '1160', '7140', '1020'], array_column($p['lines'], 'account'));
        $this->assertSame([4000, 6000, 1000, 0], array_column($p['lines'], 'debit'));
        $this->assertSame(0, $this->summary()['columns'][2]['amounts']['deductions']);
    }

    public function test_reviewed_adjustments_can_affect_both_taxable_and_mcit_income(): void
    {
        $this->books();
        $this->settings(['mcit' => 'applicable']);
        $this->record($this->input('adjustment', ['quarter' => 1, 'amount' => '-1000', 'adjustment_target' => 'both', 'remarks' => 'Exclude separately taxed income after review']));
        $s = $this->summary();
        $this->assertSame(500000, $s['columns'][1]['amounts']['taxable']);
        $this->assertSame(12000, $s['columns'][1]['amounts']['mcit']);
        $this->assertSame(-100000, $s['columns'][2]['amounts']['mcit_adjustment']);
        $this->assertSame(1800000, $s['columns'][2]['amounts']['taxable']);
    }
}
