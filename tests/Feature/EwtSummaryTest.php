<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\EwtSummaryService;
use App\Services\Tax\TaxReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EwtSummaryTest extends TestCase
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
        $this->profile = ['company_name' => 'EWT Test Book', 'full_name' => 'Accountant', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'vat_type' => 'vat', 'entity_type' => 'corporation'];
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
        return ['action' => 'payment', 'form' => '1601-EQ', 'year' => 2026, 'period' => 1, 'date' => '2026-04-15', 'amount' => '100.00', 'penalty' => '0.00', 'request_id' => (string) Str::uuid(), ...$extra];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function record(array $input): array
    {
        $preview = $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), $input)->assertOk()->json();
        $payload = [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true];
        $this->postJson(route('tax-summary.ewt.store', ['company_id' => $this->company->id]), $payload)->assertOk();

        return $payload;
    }

    /** @return array<string, mixed> */
    private function summary(int $year = 2026): array
    {
        return app(EwtSummaryService::class)->build($this->company, $this->profile, $year);
    }

    public function test_posted_only_calendar_quarters_and_company_shell(): void
    {
        $state = $this->ledger->state;
        $row = ['id' => 'one', 'number' => 'WTP-1', 'date' => '2026-01-15', 'status' => 'posted', 'ewtCode' => 'WC100', 'taxBase' => 100000, 'ewt' => '5.00'];
        $state['wtpCertificates'] = [$row, [...$row, 'id' => 'draft', 'status' => 'draft'], [...$row, 'id' => 'void', 'status' => 'void'], [...$row, 'id' => 'next', 'date' => '2027-01-01'], [...$row, 'id' => 'second', 'date' => '2026-04-01']];
        $this->ledger->update(['state' => $state]);
        CompanySetting::query()->where('workspace_key', $this->company->workspaceKey())->update(['profile' => [...$this->profile, 'accounting_period' => 'fiscal', 'fiscal_end_month' => 6]]);
        $response = $this->get(route('tax-summary.index', ['company_id' => $this->company->id, 'year' => 2026, 'tax' => 'ewt']))->assertOk()->assertSee('EWT (1601-EQ)')->assertSee('data-company-workspace="'.$this->company->id.'"', false)->assertSee('Tag payment')->assertDontSee('Search client books');
        $summary = $response->viewData('summary');
        $this->assertSame('2026-01-01', $summary['quarters'][1]['period']['from']);
        $this->assertSame(5000, $summary['quarters'][1]['amounts']['tax']);
        $this->assertSame(10000, $summary['annual']['tax']);
        $this->assertSame(0, $summary['annual']['paid']);
        $this->assertSame(10000, $summary['annual']['open']);
        $this->assertDatabaseCount('ewt_summary_events', 0);
    }

    public function test_monthly_remittances_penalties_and_same_year_excess_are_not_double_counted(): void
    {
        $this->record($this->input(['form' => '0619-E', 'period' => 1, 'amount' => '110.00', 'penalty' => '10.00', 'date' => '2026-02-10']));
        $summary = $this->summary();
        $this->assertSame(10000, $summary['quarters'][1]['amounts']['month1']);
        $this->assertSame(1000, $summary['quarters'][1]['amounts']['paid_penalties']);
        $this->assertSame(10000, $summary['quarters'][2]['amounts']['prior']);
        $this->assertSame(10000, $summary['quarters'][4]['amounts']['excess']);
        $this->assertSame(-10000, $summary['annual']['net']);
        $this->assertSame(0, $this->summary(2027)['quarters'][1]['amounts']['prior']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('tax_return_preparations', 0);
    }

    public function test_idempotent_retries_duplicate_payments_and_payload_reuse_are_guarded(): void
    {
        $payload = $this->record($this->input());
        $url = route('tax-summary.ewt.store', ['company_id' => $this->company->id]);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, [...$payload, 'amount' => '101'])->assertConflict();
        $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), [...$payload, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('ewt_summary_events', 1);
    }

    public function test_invalid_period_amount_future_date_and_unconfirmed_saves_are_rejected(): void
    {
        $url = route('tax-summary.ewt.preview', ['company_id' => $this->company->id]);
        foreach ([['form' => '0619-E', 'period' => 3], ['period' => 5], ['amount' => '0'], ['amount' => '1.234'], ['amount' => '100.00', 'penalty' => '101'], ['date' => '2026-12-31'], ['method' => 'Tax Debit Memo', 'post_now' => true, 'bank' => '1020']] as $extra) {
            $this->postJson($url, $this->input($extra))->assertUnprocessable();
        }
        $this->postJson(route('tax-summary.ewt.store', ['company_id' => $this->company->id]), $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('ewt_summary_events', 0);
    }

    public function test_stale_preview_does_not_write_payment_or_journal(): void
    {
        $input = $this->input(['post_now' => true, 'bank' => '1020']);
        $preview = $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), $input)->assertOk()->json();
        $this->ledger->update(['revision' => 2]);
        $this->postJson(route('tax-summary.ewt.store', ['company_id' => $this->company->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('ewt_summary_events', 0);
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
        $this->get(route('tax-summary.index', ['company_id' => $other->id, 'tax' => 'ewt']))->assertForbidden();
        $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $other->id]), $this->input())->assertForbidden();
        $this->company->users()->updateExistingPivot($this->owner->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['tax-reports'])]);
        $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), $this->input(['post_now' => true, 'bank' => '1020']))->assertForbidden();
        $this->company->users()->updateExistingPivot($this->owner->id, ['allowed_tabs' => json_encode(['sheets'])]);
        $this->get(route('tax-summary.index', ['company_id' => $this->company->id, 'tax' => 'ewt']))->assertForbidden();
    }

    public function test_bad_source_stays_unknown_instead_of_silently_zero(): void
    {
        $state = $this->ledger->state;
        $state['wtpCertificates'] = [['id' => 'bad', 'date' => '2026-01-10', 'status' => 'posted', 'ewtCode' => 'WC999', 'taxBase' => 100000, 'ewt' => 2]];
        $this->ledger->update(['state' => $state]);
        $summary = $this->summary();
        $this->assertNull($summary['quarters'][1]['amounts']['tax']);
        $this->assertNotEmpty($summary['quarters'][1]['issues']);
        $this->assertNull($summary['annual']['tax']);
        $this->assertNull($summary['annual']['open']);
    }

    public function test_filing_or_preparing_a_return_never_invents_a_payment(): void
    {
        $current = app(TaxReturnService::class)->preview($this->company, ['form' => '1601-EQ', 'year' => 2026, 'period' => 1]);
        DB::table('tax_return_preparations')->insert(['company_id' => $this->company->id, 'request_id' => (string) Str::uuid(), 'prepared_by' => $this->owner->id, 'form' => '1601-EQ', 'period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'ledger_revision' => 1, 'source_hash' => $current['source_hash'], 'payload_hash' => str_repeat('b', 64), 'original_slot' => 1, 'amended' => false, 'revision' => 1, 'status' => 'filed', 'snapshot' => json_encode(['calculation' => ['tax' => 0, 'credits' => 10000, 'surcharge' => 500, 'payable' => 0]]), 'created_at' => now(), 'updated_at' => now()]);
        $summary = $this->summary();
        $this->assertTrue($summary['quarters'][1]['filed']);
        $this->assertSame('Matches saved sources', $summary['quarters'][1]['reconciliation']);
        $this->assertSame(0, $summary['annual']['paid']);
        $this->assertSame(500, $summary['quarters'][1]['amounts']['open']);
        $this->assertNotEmpty($summary['quarters'][1]['issues']);
    }

    public function test_linked_journals_require_matching_amount_and_are_not_double_tagged(): void
    {
        app(CompanyLedgerService::class)->save($this->company, $this->owner, ['revision' => 1, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'manual-ewt', 'expected_version' => 0, 'record' => ['id' => 'manual-ewt', 'number' => '', 'status' => 'posted', 'date' => '2026-04-15', 'lines' => [['account' => '2140', 'debit' => 10000, 'credit' => 0], ['account' => '1020', 'debit' => 0, 'credit' => 10000]]]]]]);
        $input = $this->input(['linked_source' => 'journalEntries:manual-ewt']);
        $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), [...$input, 'amount' => '99'])->assertUnprocessable();
        $this->record($input);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), $this->input(['linked_source' => 'journalEntries:manual-ewt']))->assertConflict();
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->company->id]), $this->input(['quarter' => 1, 'net_account' => '2140', 'linked_source' => 'journalEntries:manual-ewt']))->assertConflict();
    }

    public function test_two_accountants_cannot_confirm_a_stale_payment_preview(): void
    {
        $input = $this->input();
        $preview = $this->postJson(route('tax-summary.ewt.preview', ['company_id' => $this->company->id]), $input)->assertOk()->json();
        $second = User::factory()->firm()->create(['must_change_password' => false]);
        $this->company->users()->attach($second, ['role' => 'accountant']);
        $this->actingAs($second);
        $this->record($this->input(['amount' => '50']));
        $this->actingAs($this->owner);
        $this->postJson(route('tax-summary.ewt.store', ['company_id' => $this->company->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('ewt_summary_events', 1);
    }

    public function test_uninitialized_books_remain_unknown(): void
    {
        $this->ledger->delete();
        $summary = $this->summary();
        $this->assertNull($summary['annual']['tax']);
        $this->assertNull($summary['annual']['open']);
        $this->assertNotEmpty($summary['quarters'][1]['issues']);
    }

    public function test_refund_election_does_not_also_feed_next_quarter_carryover(): void
    {
        $this->record($this->input(['form' => '0619-E', 'period' => 1]));
        DB::table('tax_return_preparations')->insert(['company_id' => $this->company->id, 'request_id' => (string) Str::uuid(), 'prepared_by' => $this->owner->id, 'form' => '1601-EQ', 'period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'ledger_revision' => 1, 'source_hash' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64), 'original_slot' => 1, 'amended' => false, 'revision' => 1, 'status' => 'prepared', 'snapshot' => json_encode(['calculation' => ['tax' => 0, 'credits' => 10000, 'payable' => 0], 'bir_xml' => ['fields' => ['frm1601EQ:ifRefund' => 'true']]]), 'created_at' => now(), 'updated_at' => now()]);
        $summary = $this->summary();
        $this->assertSame(10000, $summary['quarters'][1]['amounts']['excess']);
        $this->assertTrue($summary['quarters'][1]['reserved']);
        $this->assertSame(0, $summary['quarters'][2]['amounts']['prior']);
    }
}
