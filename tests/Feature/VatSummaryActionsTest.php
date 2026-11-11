<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use App\Services\Tax\VatSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VatSummaryActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $book;

    private User $accountant;

    private array $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
        $this->accountant = User::factory()->firm()->create(['must_change_password' => false]);
        $this->book = Company::factory()->create();
        $this->book->users()->attach($this->accountant, ['role' => 'owner']);
        $this->profile = ['company_name' => 'VAT collaboration test', 'full_name' => 'Accountant', 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'vat_type' => 'vat', 'entity_type' => 'corporation'];
        CompanySetting::factory()->create(['workspace_key' => $this->book->workspaceKey(), 'lookup_key' => $this->book->workspaceKey(), 'profile' => $this->profile]);
        ChartAccount::seedWorkspace($this->book->workspaceKey());
        $this->actingAs($this->accountant)->withSession(['active_company_id' => $this->book->id]);
    }

    private function input(string $action, array $extra = []): array
    {
        return ['action' => $action, 'year' => 2026, 'request_id' => (string) Str::uuid(), ...$extra];
    }

    private function preview(array $input): array
    {
        return $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $input)->assertOk()->json();
    }

    private function record(array $input): array
    {
        $preview = $this->preview($input);
        $this->postJson(route('tax-summary.store', ['company_id' => $this->book->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertOk();

        return $preview;
    }

    private function summary(): array
    {
        return app(VatSummaryService::class)->build($this->book, $this->profile, 2026);
    }

    private function transactions(): void
    {
        app(CompanyLedgerService::class)->save($this->book, $this->accountant, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [
            ['sheet' => 'sales', 'id' => 'sale', 'expected_version' => 0, 'record' => ['id' => 'sale', 'number' => '', 'date' => '2026-01-10', 'status' => 'posted', 'gross' => 112000, 'tax' => 'VAT (12%)', 'account' => '4000', 'terms' => 'On Account']],
            ['sheet' => 'purchases', 'id' => 'purchase', 'expected_version' => 0, 'record' => ['id' => 'purchase', 'number' => '', 'date' => '2026-01-10', 'status' => 'posted', 'gross' => 56000, 'tax' => 'VAT (12%)', 'account' => '7110', 'terms' => 'On Account']],
        ]]);
    }

    public function test_opening_credit_and_partial_reversal_are_append_only_and_do_not_post_journals(): void
    {
        $this->record($this->input('credit', ['amount' => '100.00', 'remarks' => 'Prior year balance']));
        $credit = DB::table('vat_summary_events')->sole();
        $this->record($this->input('reverse', ['credit_id' => $credit->id, 'amount' => '40.00']));
        $this->assertSame(6000, $this->summary()['workspace']['opening']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('company_ledger_changes', 0);
        $this->assertDatabaseCount('vat_summary_events', 2);
        $this->assertSame(10000, DB::table('vat_summary_events')->where('id', $credit->id)->value('amount'));
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('reverse', ['credit_id' => $credit->id, 'amount' => '61']))->assertUnprocessable();
    }

    public function test_lost_save_acknowledgement_retries_once_and_stale_previews_are_rejected(): void
    {
        $input = $this->input('credit', ['amount' => '0.00']);
        $preview = $this->record($input);
        $payload = [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true];
        $url = route('tax-summary.store', ['company_id' => $this->book->id]);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, [...$payload, 'amount' => '10'])->assertConflict();
        $next = $this->input('credit', ['amount' => '2']);
        $stale = $this->preview($next);
        $this->record($this->input('credit', ['amount' => '3']));
        $this->postJson($url, [...$next, 'preview_hash' => $stale['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('vat_summary_events', 2);
    }

    public function test_payment_tags_do_not_post_and_penalties_never_become_tax_credits(): void
    {
        $this->transactions();
        $this->record($this->input('credit', ['amount' => '0']));
        $this->record($this->input('payment', ['quarter' => 1, 'date' => '2026-04-20', 'amount' => '30', 'penalty' => '5', 'reference' => 'BANK-1', 'post_now' => false]));
        $q = $this->summary()['workspace']['quarters'][1];
        $this->assertSame(6000, $q['due']);
        $this->assertSame(3000, $q['paid']);
        $this->assertSame(3500, $q['open']);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('payment', ['quarter' => 1, 'date' => '2026-04-20', 'amount' => '30', 'reference' => 'BANK-1']))->assertConflict();
    }

    public function test_close_posts_balanced_actual_movements_once_and_cannot_be_edited_from_sheets(): void
    {
        $this->transactions();
        $input = $this->input('close', ['quarter' => 1, 'date' => '2026-04-01']);
        $preview = $this->record($input);
        $this->assertSame(12000, array_sum(array_column($preview['lines'], 'debit')));
        $this->assertSame(12000, array_sum(array_column($preview['lines'], 'credit')));
        $this->assertSame(['2210', '2110', '2205'], array_column($preview['lines'], 'account'));
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('company_ledger_changes', 2);
        $this->assertSame(12000, $this->summary()['annual']['output']);
        $this->assertSame(6000, $this->summary()['annual']['input']);
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('close', ['quarter' => 1, 'date' => '2026-04-01']))->assertConflict();
        $ledger = app(CompanyLedgerService::class)->read($this->book);
        $row = $ledger['state']['journalEntries'][0];
        $row['status'] = 'void';
        $row['voidReason'] = 'Trying to bypass VAT history';
        $response = $this->withHeader('X-Company-ID', (string) $this->book->id)->postJson(route('sheets.database.save', ['company_id' => $this->book->id]), ['revision' => $ledger['revision'], 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => $row['id'], 'expected_version' => $ledger['record_versions']['journalEntries'][$row['id']], 'record' => $row]]]);
        $this->assertSame(422, $response->status(), $response->getContent());
    }

    public function test_post_payment_creates_one_balanced_journal_and_requires_cash_account(): void
    {
        $input = $this->input('payment', ['quarter' => 1, 'date' => '2026-04-20', 'amount' => '110', 'penalty' => '10', 'reference' => 'BANK-2', 'post_now' => true, 'bank' => '1020']);
        $preview = $this->record($input);
        $this->assertSame([10000, 1000, 0], array_column($preview['lines'], 'debit'));
        $this->assertSame([0, 0, 11000], array_column($preview['lines'], 'credit'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('chart_accounts', ['company_id' => $this->book->id, 'code' => '2205']);
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), [...$input, 'request_id' => (string) Str::uuid(), 'bank' => '1105'])->assertUnprocessable();
    }

    public function test_carryover_requires_year_end_is_single_and_preserves_credit_history(): void
    {
        $this->record($this->input('credit', ['amount' => '100']));
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('carryover'))->assertUnprocessable();
        $this->travelTo(now()->setDate(2027, 1, 15));
        $this->record($this->input('carryover'));
        $next = app(VatSummaryService::class)->build($this->book, $this->profile, 2027);
        $this->assertSame(10000, $next['workspace']['opening']);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('carryover'))->assertUnprocessable();
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('credit', ['year' => 2027, 'amount' => '100']))->assertUnprocessable();
    }

    public function test_scope_roles_future_empty_quarters_confirmation_and_preview_are_enforced(): void
    {
        $other = Company::factory()->create();
        $this->postJson(route('tax-summary.preview', ['company_id' => $other->id]), $this->input('credit', ['amount' => '1']))->assertForbidden();
        $this->postJson(route('tax-summary.store', ['company_id' => $this->book->id]), $this->input('credit', ['amount' => '1']))->assertUnprocessable();
        foreach ([['quarter' => 4, 'date' => '2026-11-11'], ['quarter' => 2, 'date' => '2026-07-01']] as $extra) {
            $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('close', $extra))->assertUnprocessable();
        }
        $this->book->users()->updateExistingPivot($this->accountant->id, ['role' => 'staff', 'allowed_tabs' => json_encode(['tax-reports'])]);
        $this->preview($this->input('credit', ['amount' => '1']));
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('payment', ['quarter' => 1, 'date' => '2026-04-01', 'amount' => '1', 'post_now' => true, 'bank' => '1020']))->assertForbidden();
        $this->book->users()->updateExistingPivot($this->accountant->id, ['allowed_tabs' => json_encode(['sheets'])]);
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('credit', ['amount' => '1']))->assertForbidden();
    }

    public function test_linked_payment_must_match_amount_and_cannot_be_tagged_twice(): void
    {
        $this->record($this->input('payment', ['quarter' => 1, 'date' => '2026-04-20', 'amount' => '100', 'post_now' => true, 'bank' => '1020']));
        $source = DB::table('journal_entries')->sole()->source_key;
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $this->input('payment', ['quarter' => 1, 'date' => '2026-04-20', 'amount' => '100', 'linked_source' => $source, 'reference' => 'A different reference']))->assertConflict();
        app(CompanyLedgerService::class)->save($this->book, $this->accountant, ['request_id' => (string) Str::uuid(), 'revision' => 0, 'operations' => [['sheet' => 'journalEntries', 'id' => 'manual-payment', 'expected_version' => 0, 'record' => ['id' => 'manual-payment', 'number' => '', 'status' => 'posted', 'date' => '2026-04-21', 'lines' => [['account' => '2205', 'debit' => 9000, 'credit' => 0], ['account' => '1020', 'debit' => 0, 'credit' => 9000]]]]]]);
        $input = $this->input('payment', ['quarter' => 1, 'date' => '2026-04-21', 'amount' => '100', 'linked_source' => 'journalEntries:manual-payment']);
        $this->postJson(route('tax-summary.preview', ['company_id' => $this->book->id]), $input)->assertUnprocessable();
        $this->record([...$input, 'amount' => '90']);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('vat_summary_events', 2);
    }

    public function test_later_vat_adjustment_flags_close_for_review_and_preserves_recorded_journal(): void
    {
        $this->transactions();
        $this->record($this->input('close', ['quarter' => 1, 'date' => '2026-03-31']));
        $this->assertFalse($this->summary()['workspace']['quarters'][1]['close_changed']);
        app(CompanyLedgerService::class)->save($this->book, $this->accountant, ['request_id' => (string) Str::uuid(), 'revision' => 0, 'operations' => [['sheet' => 'journalEntries', 'id' => 'vat-adjustment', 'expected_version' => 0, 'record' => ['id' => 'vat-adjustment', 'number' => '', 'status' => 'posted', 'date' => '2026-02-01', 'lines' => [['account' => '2110', 'debit' => 1000, 'credit' => 0], ['account' => '1020', 'debit' => 0, 'credit' => 1000]]]]]]);
        $this->assertTrue($this->summary()['workspace']['quarters'][1]['close_changed']);
        $this->assertDatabaseCount('vat_summary_events', 1);
        $this->get(route('tax-summary.index', ['company_id' => $this->book->id, 'year' => 2026]))->assertOk()->assertSee('source balances changed');
    }

    public function test_two_accountants_cannot_confirm_the_same_stale_vat_state(): void
    {
        $helper = User::factory()->accountant()->create(['must_change_password' => false]);
        $this->book->users()->attach($helper, ['role' => 'accountant']);
        $input = $this->input('credit', ['amount' => '20']);
        $this->actingAs($helper);
        $preview = $this->preview($input);
        $this->actingAs($this->accountant);
        $this->record($this->input('credit', ['amount' => '10']));
        $this->actingAs($helper);
        $this->postJson(route('tax-summary.store', ['company_id' => $this->book->id]), [...$input, 'preview_hash' => $preview['preview_hash'], 'confirmed' => true])->assertConflict();
        $this->record($input);
        $this->assertSame(3000, $this->summary()['workspace']['opening']);
        $this->assertDatabaseHas('vat_summary_events', ['company_id' => $this->book->id, 'request_id' => $input['request_id'], 'actor_id' => $helper->id]);
    }

    public function test_input_credit_movement_reconciles_without_summing_carryovers(): void
    {
        $this->transactions();
        $this->record($this->input('credit', ['amount' => '100']));
        $movement = $this->summary()['workspace']['movement'];
        $this->assertSame(['beginning' => 10000, 'addition' => 6000, 'applied' => 12000, 'adjustment' => 0, 'ending' => 4000], $movement);
        $this->assertSame($movement['ending'], $movement['beginning'] + $movement['addition'] + $movement['adjustment'] - $movement['applied']);
    }

    public function test_source_preview_filters_posted_period_and_tenant_and_reconciles_contribution(): void
    {
        $this->transactions();
        $this->getJson(route('tax-summary.sources', ['company_id' => $this->book->id, 'year' => 2026, 'quarter' => 1, 'key' => 'input']))->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('total', 6000)->assertJsonPath('rows.0.gross', 56000);
        $this->getJson(route('tax-summary.sources', ['company_id' => $this->book->id, 'year' => 2026, 'quarter' => 2, 'key' => 'input']))->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson(route('tax-summary.sources', ['company_id' => $this->book->id, 'year' => 2026, 'quarter' => 1, 'key' => 'unsafe']))->assertUnprocessable();
    }
}
