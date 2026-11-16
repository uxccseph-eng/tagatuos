<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\CompensationSummaryService;
use App\Services\Tax\TaxReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompensationSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private CompanyLedger $ledger;

    /** @var array<string, mixed> */
    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->startOfDay());
        $this->owner = User::factory()->firm()->create(['must_change_password' => false]);
        $this->company = Company::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->profile = ['company_name' => 'Compensation Test Book', 'full_name' => 'Accountant', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'vat_type' => 'vat', 'entity_type' => 'corporation'];
        CompanySetting::factory()->create(['workspace_key' => $this->company->workspaceKey(), 'lookup_key' => $this->company->workspaceKey(), 'profile' => $this->profile]);
        ChartAccount::seedWorkspace($this->company->workspaceKey());
        $this->ledger = CompanyLedger::factory()->create(['company_id' => $this->company->id, 'revision' => 1, 'state' => CompanyLedgerService::emptyState()]);
        $this->actingAs($this->owner)->withSession(['active_company_id' => $this->company->id]);
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function input(array $extra = []): array
    {
        return ['action' => 'payment', 'form' => '1601-C', 'year' => 2026, 'period' => 1, 'date' => '2026-04-15', 'amount' => '100.00', 'penalty' => '0.00', 'request_id' => (string) Str::uuid(), ...$extra];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function record(array $input): array
    {
        $preview = $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $input)->assertOk()->json();
        $payload = [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true];
        $this->postJson(route('tax-summary.compensation.store', ['company_id' => $this->company->id]), $payload)->assertOk();

        return $payload;
    }

    /** @return array<string, mixed> */
    private function summary(int $year = 2026): array
    {
        return app(CompensationSummaryService::class)->build($this->company, $this->profile, $year);
    }

    public function test_idempotent_retries_duplicate_payments_and_payload_reuse_are_guarded(): void
    {
        $payload = $this->record($this->input());
        $url = route('tax-summary.compensation.store', ['company_id' => $this->company->id]);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, [...$payload, 'amount' => '101'])->assertConflict();
        $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), [...$payload, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('compensation_summary_events', 1);
    }

    public function test_stale_preview_does_not_write_payment_or_journal(): void
    {
        $input = $this->input(['post_now' => true, 'bank' => '1020']);
        $preview = $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $input)->assertOk()->json();
        $this->ledger->update(['revision' => 2]);
        $this->postJson(route('tax-summary.compensation.store', ['company_id' => $this->company->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('compensation_summary_events', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_posted_payment_balances_and_its_journal_is_protected(): void
    {
        $this->record($this->input(['amount' => '110.00', 'penalty' => '10.00', 'post_now' => true, 'bank' => '1020']));
        $this->assertDatabaseCount('journal_entries', 1);
        $lines = DB::table('journal_entry_lines')->get();
        $this->assertEquals(110, $lines->sum('debit'));
        $this->assertEquals(110, $lines->sum('credit'));
        $this->assertSame(10000, $this->summary()['annual']['paid']);
        $journal = $this->ledger->fresh()->state['journalEntries'][0];
        $journal['status'] = 'void';
        $journal['voidReason'] = 'Test tax-payment protection';
        $ledger = app(CompanyLedgerService::class)->read($this->company);
        $this->withHeader('X-Company-ID', (string) $this->company->id)->postJson(route('sheets.database.save', ['company_id' => $this->company->id]), ['revision' => $this->ledger->fresh()->revision, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => $journal['id'], 'expected_version' => $ledger['record_versions']['journalEntries'][$journal['id']], 'record' => $journal]]])->assertUnprocessable()->assertSee('linked to a recorded tax payment');
    }

    public function test_company_and_current_role_boundaries_are_enforced(): void
    {
        $other = Company::factory()->create();
        $this->get(route('tax-summary.index', ['company_id' => $other->id, 'tax' => 'compensation']))->assertForbidden();
        $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $other->id]), $this->input())->assertForbidden();
        $this->company->users()->updateExistingPivot($this->owner->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['tax-reports'])]);
        $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $this->input(['post_now' => true, 'bank' => '1020']))->assertForbidden();
        $this->company->users()->updateExistingPivot($this->owner->id, ['allowed_tabs' => json_encode(['sheets'])]);
        $this->get(route('tax-summary.index', ['company_id' => $this->company->id, 'tax' => 'compensation']))->assertForbidden();
    }

    public function test_two_accountants_cannot_confirm_a_stale_payment_preview(): void
    {
        $input = $this->input();
        $preview = $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $input)->assertOk()->json();
        $second = User::factory()->firm()->create(['must_change_password' => false]);
        $this->company->users()->attach($second, ['role' => 'accountant']);
        $this->actingAs($second);
        $this->record($this->input(['amount' => '50']));
        $this->actingAs($this->owner);
        $this->postJson(route('tax-summary.compensation.store', ['company_id' => $this->company->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('compensation_summary_events', 1);
    }

    public function test_uninitialized_books_remain_unknown(): void
    {
        $this->ledger->delete();
        $summary = $this->summary();
        $this->assertNull($summary['annual']['tax']);
        $this->assertNull($summary['annual']['open']);
        $this->assertNotEmpty($summary['months'][1]['issues']);
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function employee(array $extra = []): array
    {
        return ['payType' => 'Taxable', 'basic' => '40000.00', 'taxableAllowance' => '0.00', 'deMinimis' => '1000.00', 'benefits' => '10000.00', 'taxableBenefits' => '2000.00', 'sssEe' => '2000.00', 'phicEe' => '900.00', 'hdmfEe' => '100.00', 'withholding' => '3008.40', 'kpiIncentive' => '1000.00', 'commission' => '500.00', ...$extra];
    }

    private function payroll(): void
    {
        $run = ['id' => 'nov', 'number' => 'PY-1', 'status' => 'posted', 'date' => '2026-11-30', 'employees' => [$this->employee()]];
        $this->ledger->update(['payroll' => [$run, [...$run, 'id' => 'draft', 'status' => 'draft'], [...$run, 'id' => 'void', 'status' => 'void'], [...$run, 'id' => 'dec', 'date' => '2026-12-15'], [...$run, 'id' => 'next', 'date' => '2027-01-01']]]);
    }

    public function test_twelve_calendar_months_preserve_actual_payroll_and_annual_footing(): void
    {
        $this->payroll();
        CompanySetting::query()->where('workspace_key', $this->company->workspaceKey())->update(['profile' => [...$this->profile, 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6]]);
        $response = $this->get(route('tax-summary.index', ['company_id' => $this->company->id, 'tax' => 'compensation', 'year' => 2026]))->assertOk()->assertSee('Compensation (1601-C)')->assertSee('Monthly computation')->assertSee('data-company-workspace="'.$this->company->id.'"', false)->assertSee('Prepare 1601-C')->assertSee('No liability recorded');
        $summary = $response->viewData('summary');
        $this->assertCount(12, $summary['months']);
        $this->assertSame('2026-01-01', $summary['months'][1]['period']['from']);
        $this->assertSame(5250000, $summary['months'][11]['amounts']['gross']);
        $this->assertSame(1200000, $summary['months'][11]['amounts']['exempt']);
        $this->assertSame(4050000, $summary['months'][11]['amounts']['net_taxable']);
        $this->assertSame(300840, $summary['months'][11]['amounts']['tax']);
        $this->assertSame(601680, $summary['annual']['tax']);
        $this->assertSame(10500000, $summary['annual']['gross']);
        $this->assertSame('No liability recorded', $summary['months'][1]['payment_status']);
        $this->assertSame('Unpaid', $summary['months'][11]['payment_status']);
        $this->assertDatabaseCount('tax_return_preparations', 0);
    }

    public function test_payments_never_inflate_credits_with_penalties_or_move_to_other_months(): void
    {
        $this->record($this->input(['amount' => '110.00', 'penalty' => '10.00']));
        $summary = $this->summary();
        $this->assertSame(10000, $summary['months'][1]['amounts']['paid']);
        $this->assertSame(1000, $summary['months'][1]['amounts']['paid_penalties']);
        $this->assertSame(10000, $summary['months'][1]['amounts']['excess']);
        $this->assertSame(0, $summary['months'][2]['amounts']['paid']);
        $this->assertSame('Over-remitted', $summary['months'][1]['payment_status']);
        $this->assertSame(0, $this->summary(2027)['annual']['paid']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_malformed_payroll_remains_unknown_and_zero_tax_does_not_infer_threshold(): void
    {
        $this->ledger->update(['payroll' => [['id' => 'one', 'status' => 'posted', 'date' => '2026-01-15', 'employees' => [$this->employee(['withholding' => '0.00'])]]]]);
        $summary = $this->summary();
        $this->assertSame(0, $summary['months'][1]['amounts']['not_subject']);
        $this->assertSame(4050000, $summary['months'][1]['amounts']['net_taxable']);
        $this->assertNotEmpty($summary['months'][1]['warnings']);
        $this->ledger->update(['payroll' => [['id' => 'bad', 'status' => 'posted', 'date' => '2026-01-15', 'employees' => [['basic' => 'garbage', 'withholding' => '0.00']]]]]);
        $summary = $this->summary();
        $this->assertNull($summary['months'][1]['amounts']['gross']);
        $this->assertNull($summary['annual']['tax']);
        $this->assertNotEmpty($summary['months'][1]['issues']);
    }

    public function test_filed_snapshot_neither_implies_payment_nor_changes_with_payroll(): void
    {
        $this->payroll();
        $current = app(TaxReturnService::class)->preview($this->company, ['form' => '1601-C', 'year' => 2026, 'period' => 11]);
        DB::table('tax_return_preparations')->insert(['company_id' => $this->company->id, 'request_id' => (string) Str::uuid(), 'prepared_by' => $this->owner->id, 'form' => '1601-C', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'ledger_revision' => 1, 'source_hash' => $current['source_hash'], 'payload_hash' => str_repeat('b', 64), 'original_slot' => 1, 'amended' => false, 'revision' => 1, 'status' => 'filed', 'snapshot' => json_encode(['calculation' => ['tax' => 300840, 'credits' => 10000, 'surcharge' => 1000, 'payable' => 291840], 'xml_payroll_review' => ['values' => ['no_withholding_compensation' => 100000]], 'bir_xml' => ['fields' => ['frm1601c:txtTax26' => '-10.00']]]), 'created_at' => now(), 'updated_at' => now()]);
        $summary = $this->summary();
        $this->assertTrue($summary['months'][11]['filed']);
        $this->assertSame('Matches saved sources', $summary['months'][11]['reconciliation']);
        $this->assertSame(3950000, $summary['months'][11]['amounts']['net_taxable']);
        $this->assertSame(299840, $summary['months'][11]['amounts']['due']);
        $this->assertSame(0, $summary['months'][11]['amounts']['paid']);
        $payroll = $this->ledger->payroll;
        $payroll[0]['employees'][0]['basic'] = '41000.00';
        $this->ledger->update(['payroll' => $payroll, 'revision' => 2]);
        $summary = $this->summary();
        $this->assertSame('Sources changed — review return', $summary['months'][11]['reconciliation']);
        $this->assertSame(0, $summary['months'][11]['amounts']['not_subject']);
        $this->assertSame(291840, $summary['months'][11]['saved_payable']);
        $this->assertDatabaseCount('tax_return_preparations', 1);
    }

    public function test_input_period_amount_date_and_confirmation_rules_are_enforced(): void
    {
        foreach ([['period' => 13], ['period' => 0], ['form' => '0619-E'], ['amount' => '0'], ['amount' => '1.234'], ['penalty' => '101'], ['date' => '2026-12-31'], ['post_now' => true, 'bank' => '1020', 'method' => 'Tax Debit Memo']] as $extra) {
            $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $this->input($extra))->assertUnprocessable();
        }
        $this->postJson(route('tax-summary.compensation.store', ['company_id' => $this->company->id]), $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('compensation_summary_events', 0);
    }

    public function test_linking_an_existing_payment_checks_amount_and_blocks_cross_module_duplicates(): void
    {
        app(CompanyLedgerService::class)->save($this->company, $this->owner, ['revision' => 1, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'manual-comp', 'expected_version' => 0, 'record' => ['id' => 'manual-comp', 'number' => '', 'status' => 'posted', 'date' => '2026-04-15', 'lines' => [['account' => '2350', 'debit' => 10000, 'credit' => 0], ['account' => '1020', 'debit' => 0, 'credit' => 10000]]]]]]);
        $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $this->input(['linked_source' => 'journalEntries:manual-comp', 'amount' => '99']))->assertUnprocessable();
        $this->record($this->input(['linked_source' => 'journalEntries:manual-comp']));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->postJson(route('tax-summary.compensation.preview', ['company_id' => $this->company->id]), $this->input(['linked_source' => 'journalEntries:manual-comp']))->assertConflict();
        $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), [...$this->input(['linked_source' => 'journalEntries:manual-comp']), 'form' => '1601-EQ'])->assertConflict();
    }

    public function test_invalid_employee_structure_is_reported_instead_of_crashing_the_summary(): void
    {
        $this->ledger->update(['payroll' => [['id' => 'broken', 'status' => 'posted', 'date' => '2026-01-01', 'employees' => ['invalid row']]]]);
        $response = $this->get(route('tax-summary.index', ['company_id' => $this->company->id, 'tax' => 'compensation', 'year' => 2026]))->assertOk();
        $this->assertNull($response->viewData('summary')['months'][1]['amounts']['tax']);
        $this->assertNotEmpty($response->viewData('summary')['months'][1]['issues']);
    }
}
