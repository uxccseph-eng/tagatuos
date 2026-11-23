<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxReversionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Company, User} */
    private function book(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'Asia/Manila'));
        $owner = User::factory()->firm()->create(['must_change_password' => false]);
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => 'Archive Demo', 'full_name' => $owner->name, 'tin' => '123456789', 'branch_code' => '00000', 'rdo' => '033', 'entity_type' => 'corporation']]);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Ledger-Revision', '0');

        return [$company, $owner];
    }

    /** @return array<string, mixed> */
    private function input(Company $company): array
    {
        $selection = ['form' => '0619-E', 'year' => 2026, 'period' => 10];
        $preview = $this->getJson(route('tax-returns.preview', ['company_id' => $company->id, ...$selection]))->assertOk()->json();

        return [...$selection, 'request_id' => (string) Str::uuid(), 'ledger_revision' => 0, 'source_hash' => $preview['source_hash'], 'original_id' => $preview['original_id'], 'original_revision' => $preview['original_revision'], 'additional_tax' => '0.00', 'credits' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00', 'review_note' => 'Reviewed nil month and source records.', 'reviewed' => true];
    }

    public function test_filing_revert_clears_period_badge_and_preserves_previous_filing_details(): void
    {
        [$company] = $this->book();
        $id = $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $this->input($company))->assertCreated()->json('id');
        $this->postJson(route('tax-returns.filed', ['id' => $id, 'company_id' => $company->id]), ['reference' => 'BIR-TEST', 'filed_on' => '2026-11-01', 'confirmed' => true, 'revision' => 1])->assertOk();
        $this->get(route('tax-returns.index', ['company_id' => $company->id, 'year' => 2026]))->assertOk()->assertSee('Revert to prepared');
        $url = route('tax-returns.revert-filed', ['id' => $id, 'company_id' => $company->id]);
        $this->post($url, ['reason' => 'Marked filed by mistake', 'confirmed' => true, 'revision' => 1])->assertConflict();
        $this->post($url, ['reason' => 'Marked filed by mistake', 'confirmed' => true, 'revision' => 2])->assertRedirect();
        $this->assertDatabaseHas('tax_return_preparations', ['id' => $id, 'status' => 'prepared', 'filed_on' => null, 'revision' => 3]);
        $this->assertDatabaseHas('tax_return_filing_records', ['company_id' => $company->id, 'filed_on' => null, 'reference' => null]);
        $snapshot = json_decode(DB::table('tax_return_preparations')->find($id)->snapshot, true);
        $this->assertSame('BIR-TEST', $snapshot['filing_reversions'][0]['reference']);
        $this->postJson(route('tax-returns.prepare', ['company_id' => $company->id]), $this->input($company))->assertOk();
        $saved = json_decode(DB::table('tax_return_preparations')->find($id)->snapshot, true);
        $this->assertSame($snapshot['filing_reversions'], $saved['filing_reversions']);
    }

    public function test_tagged_payment_revert_retains_history_and_is_not_repeated(): void
    {
        [$company, $owner] = $this->book();
        foreach (['vat' => 'vat_summary_events', 'income' => 'income_tax_summary_events', 'ewt' => 'ewt_summary_events', 'compensation' => 'compensation_summary_events'] as $tax => $table) {
            $id = DB::table($table)->insertGetId(['company_id' => $company->id, 'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'action' => 'payment', 'year' => 2026, $tax === 'compensation' ? 'month' : 'quarter' => 1, 'amount' => 1000, 'actor_id' => $owner->id, 'slot' => 'test', 'data' => json_encode(['date' => '2026-01-31']), 'created_at' => now()]);
            $url = route('tax-summary.revert', ['tax' => $tax, 'id' => $id, 'company_id' => $company->id]);
            $this->post($url, ['reason' => 'Duplicate payment tag', 'confirmed' => true])->assertRedirect();
            $this->assertDatabaseHas($table, ['id' => $id, 'action' => 'payment-reverted', 'amount' => 1000, 'slot' => null]);
            $this->post($url, ['reason' => 'Duplicate payment tag', 'confirmed' => true])->assertConflict();
        }
    }

    public function test_reopening_latest_vat_close_voids_generated_journal_and_preserves_its_identity(): void
    {
        [$company, $owner] = $this->book();
        ChartAccount::seedWorkspace($company->workspaceKey());
        $journal = ['id' => 'vat-test', 'number' => '', 'status' => 'posted', 'date' => '2026-03-31', 'type' => 'General', 'origin' => 'vat-close', 'lines' => [['account' => '1010', 'debit' => 1000, 'credit' => 0], ['account' => '3010', 'debit' => 0, 'credit' => 1000]]];
        app(CompanyLedgerService::class)->save($company, $owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'vat-test', 'expected_version' => 0, 'record' => $journal]]]);
        $id = DB::table('vat_summary_events')->insertGetId(['company_id' => $company->id, 'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'action' => 'close', 'year' => 2026, 'quarter' => 1, 'amount' => 0, 'actor_id' => $owner->id, 'slot' => 'close:2026:1', 'data' => json_encode(['linked_source' => 'journalEntries:vat-test']), 'created_at' => now()]);
        $later = DB::table('vat_summary_events')->insertGetId(['company_id' => $company->id, 'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64), 'action' => 'close', 'year' => 2026, 'quarter' => 2, 'amount' => 0, 'actor_id' => $owner->id, 'slot' => 'close:2026:2', 'data' => '{}', 'created_at' => now()]);
        $this->get(route('tax-summary.index', ['company_id' => $company->id, 'year' => 2026]))->assertOk()->assertSee('Reopen quarter');
        $url = route('tax-summary.revert', ['tax' => 'vat', 'id' => $id, 'company_id' => $company->id]);
        $this->post($url, ['reason' => 'Need to correct VAT closing', 'confirmed' => true])->assertUnprocessable();
        $this->post(route('tax-summary.revert', ['tax' => 'vat', 'id' => $later, 'company_id' => $company->id]), ['reason' => 'Reopen dependent closing', 'confirmed' => true])->assertRedirect();
        $this->post($url, ['reason' => 'Need to correct VAT closing', 'confirmed' => true])->assertRedirect();
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:vat-test', 'status' => 'void']);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('vat_summary_events', ['id' => $id, 'action' => 'close-reverted', 'slot' => null]);
    }

    public function test_tagged_payment_does_not_block_backdated_journals_and_manual_edit_requires_untagging(): void
    {
        [$company, $owner] = $this->book();
        ChartAccount::seedWorkspace($company->workspaceKey());
        $ledger = app(CompanyLedgerService::class);
        $journal = ['id' => 'manual', 'number' => '', 'status' => 'posted', 'date' => '2026-03-31', 'type' => 'General', 'lines' => [['account' => '1010', 'debit' => 1000, 'credit' => 0], ['account' => '3010', 'debit' => 0, 'credit' => 1000]]];
        $ledger->save($company, $owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'manual', 'expected_version' => 0, 'record' => $journal]]]);
        $id = DB::table('vat_summary_events')->insertGetId(['company_id' => $company->id, 'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'action' => 'payment', 'year' => 2026, 'quarter' => 1, 'amount' => 1000, 'actor_id' => $owner->id, 'slot' => 'manual-tag', 'data' => json_encode(['linked_source' => 'journalEntries:manual']), 'created_at' => now()]);
        $normalizedManual = $ledger->read($company)['state']['journalEntries'][0];
        $normalizedManual['notes'] = '';
        $normalizedManual['lines'][0]['party'] = '';
        $normalizedManual['lines'][0]['remarks'] = '';
        $earlier = [...$journal, 'id' => 'earlier', 'date' => '2025-12-31'];
        $saved = $ledger->save($company, $owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'earlier', 'expected_version' => 0, 'record' => $earlier], ['sheet' => 'journalEntries', 'id' => 'manual', 'expected_version' => 1, 'record' => $normalizedManual]]]);
        $manual = collect($saved['state']['journalEntries'])->firstWhere('id', 'manual');
        $this->assertSame('GJ-000002', $manual['number']);
        $this->post(route('tax-summary.revert', ['tax' => 'vat', 'id' => $id, 'company_id' => $company->id]), ['reason' => 'Correct manually linked payment', 'confirmed' => true])->assertRedirect();
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:manual', 'status' => 'posted']);
        $manual['lines'][0]['debit'] = 2000;
        $manual['lines'][1]['credit'] = 2000;
        $versions = $ledger->read($company)['record_versions'];
        $result = $ledger->save($company, $owner, ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [['sheet' => 'journalEntries', 'id' => 'manual', 'expected_version' => $versions['journalEntries']['manual'], 'record' => $manual]]]);
        $this->assertSame(2000, collect($result['state']['journalEntries'])->firstWhere('id', 'manual')['lines'][0]['debit']);
    }
}
