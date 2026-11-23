<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LedgerCollaborationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_accountants_can_post_different_years_from_the_same_snapshot(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($a, $company);
        $later = $this->send([$this->operation('later', $this->journal('later', 'posted'))])->assertOk()->json();
        $headerId = DB::table('journal_entries')->where('source_key', 'journalEntries:later')->value('id');
        $this->member($b, $company);
        $earlier = [...$this->journal('earlier', 'posted'), 'date' => '2025-12-31'];
        $requestId = (string) Str::uuid();
        $result = $this->send([$this->operation('earlier', $earlier)], 0, $requestId)->assertOk();
        $result->assertJsonPath('state.journalEntries.0.number', 'GJ-000002')
            ->assertJsonPath('state.journalEntries.1.number', 'GJ-000001');
        $this->assertSame(1, $later['record_versions']['journalEntries']['later']);
        $this->assertDatabaseHas('journal_entries', ['id' => $headerId, 'source_key' => 'journalEntries:later', 'number' => 'GJ-000002']);
        $this->send([$this->operation('earlier', $earlier)], 0, $requestId)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_two_accountants_add_from_the_same_revision_and_receive_unique_server_numbers(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($a, $company);
        $first = $this->send([$this->operation('a', $this->journal('a', 'posted'))])->assertOk();
        $this->member($b, $company);
        $second = $this->send([$this->operation('b', $this->journal('b', 'posted'))])->assertOk();
        $second->assertJsonCount(2, 'state.journalEntries')->assertJsonPath('revision', 2);
        $this->assertSame(['GJ-000001', 'GJ-000002'], array_column($second->json('state.journalEntries'), 'number'));
        $this->assertSame(1, $first->json('record_versions.journalEntries.a'));
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('journal_entry_lines', 4);
    }

    public function test_different_entry_edits_merge_but_same_entry_edits_return_a_specific_conflict(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($a, $company);
        $this->send([$this->operation('a', $this->journal('a', 'posted')), $this->operation('b', $this->journal('b', 'posted'))])->assertOk();
        $snapshot = $this->getJson(route('sheets.database.show'))->json();
        $first = $snapshot['state']['journalEntries'][0];
        $second = $snapshot['state']['journalEntries'][1];
        $first['reference'] = 'Accountant A';
        $this->send([$this->operation('a', $first, 1)], 1)->assertOk();
        $this->member($b, $company);
        $second['reference'] = 'Accountant B';
        $this->send([$this->operation('b', $second, 1)], 1)->assertOk()->assertJsonPath('revision', 3);
        $first['reference'] = 'Stale overwrite';
        $this->send([$this->operation('a', $first, 1)], 1)->assertConflict()->assertJsonPath('record_conflict', true)->assertJsonPath('conflicts.0.id', 'a')->assertJsonPath('conflicts.0.current_version', 2);
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:a', 'reference' => 'Accountant A']);
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:b', 'reference' => 'Accountant B']);
    }

    public function test_each_accountant_has_a_private_draft_and_cannot_edit_another_accountants_draft(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($a, $company);
        $this->send([$this->operation('a', $this->journal('a'))])->assertOk()->assertJsonPath('state.journalEntries.0.draft_owner_id', $a->id);
        $this->member($b, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonCount(0, 'state.journalEntries');
        $this->send([$this->operation('b', $this->journal('b'))])->assertOk()->assertJsonCount(1, 'state.journalEntries');
        $this->send([$this->operation('a', $this->journal('a'), 1)], 1)->assertForbidden();
        $this->member($a, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('state.journalEntries.0.id', 'a');
        $post = $this->journal('a', 'posted');
        $this->send([$this->operation('a', $post, 1)], 1)->assertOk();
        $this->member($b, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonCount(2, 'state.journalEntries');
        $this->assertCount(2, CompanyLedger::withoutGlobalScopes()->sole()->state['journalEntries']);
    }

    public function test_retry_after_another_save_does_not_duplicate_or_repeat_the_operation(): void
    {
        [$company, $a, $b] = $this->book();
        $requestId = (string) Str::uuid();
        $operations = [$this->operation('a', $this->journal('a', 'posted'))];
        $this->member($a, $company);
        $this->send($operations, 0, $requestId)->assertOk();
        $this->member($b, $company);
        $this->send([$this->operation('b', $this->journal('b', 'posted'))])->assertOk();
        $this->member($a, $company);
        $this->send($operations, 0, $requestId)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('replayed', true)->assertJsonPath('applied_versions.journalEntries.a', 1);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('company_ledger_changes', 2);
    }

    public function test_deleted_draft_cannot_be_recreated_by_an_outdated_tab(): void
    {
        [$company, $a] = $this->book();
        $this->member($a, $company);
        $this->send([$this->operation('a', $this->journal('a'))])->assertOk();
        $this->send([$this->operation('a', null, 1)], 1)->assertOk();
        $this->send([$this->operation('a', $this->journal('a'))])->assertConflict();
        $this->assertDatabaseHas('company_transaction_versions', ['record_id' => 'a', 'version' => 2]);
    }

    public function test_batch_is_atomic_and_latest_settlement_limits_still_apply(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($a, $company);
        $this->send([$this->operation('a', $this->journal('a', 'posted'))])->assertOk();
        $this->member($b, $company);
        $this->send([$this->operation('b', $this->journal('b', 'posted')), $this->operation('a', $this->journal('a', 'posted'), 0)])->assertConflict();
        $this->assertDatabaseCount('journal_entries', 1);
        $invalid = $this->journal('invalid', 'posted');
        $invalid['lines'][1]['credit'] = 999;
        $this->send([$this->operation('invalid', $invalid)])->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('company_transaction_versions', 1);
    }

    public function test_cross_company_requests_cannot_modify_a_record_by_reusing_its_identity(): void
    {
        [$company, $a] = $this->book();
        [$other] = $this->book();
        $this->member($a, $company);
        $this->postJson(route('sheets.database.save', ['company_id' => $other->id]), ['revision' => 0, 'request_id' => (string) Str::uuid(), 'operations' => [$this->operation('a', $this->journal('a'))]])->assertConflict();
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_two_receipts_cannot_overcollect_the_same_invoice(): void
    {
        [$company, $a, $b] = $this->book();
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Concurrent Customer', 'classification' => 'Customer']);
        $sale = ['id' => 'invoice', 'number' => '', 'status' => 'posted', 'date' => '2026-11-01', 'customer' => 'Concurrent Customer', 'gross' => 10000, 'tax' => 'Non-VAT', 'account' => '4020', 'terms' => 'On Account', 'cwt' => 'None', 'vatCwt' => 'None'];
        $this->member($a, $company);
        $this->send([['sheet' => 'sales', 'id' => 'invoice', 'expected_version' => 0, 'record' => $sale]])->assertOk();
        $receipt = ['id' => 'receipt-a', 'number' => '', 'status' => 'posted', 'date' => '2026-11-01', 'customer' => 'Concurrent Customer', 'invoice' => 'SI-000001', 'saleId' => 'invoice', 'amount' => 8000, 'bank' => '1010', 'type' => 'Settlement'];
        $this->send([['sheet' => 'collections', 'id' => 'receipt-a', 'expected_version' => 0, 'record' => $receipt]], 1)->assertOk();
        $this->member($b, $company);
        $receipt['id'] = 'receipt-b';
        $this->send([['sheet' => 'collections', 'id' => 'receipt-b', 'expected_version' => 0, 'record' => $receipt]], 1)->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('company_ledger_changes', 2);
    }

    public function test_deleting_a_void_entry_compacts_saved_journals_and_the_next_post_uses_the_available_number(): void
    {
        [$company, $a, $b] = $this->book();
        $this->member($a, $company);
        $saved = $this->send(array_map(fn (string $id): array => $this->operation($id, $this->journal($id, 'posted')), ['a', 'b', 'c']))->assertOk()->json();
        $void = $saved['state']['journalEntries'][1];
        $void['status'] = 'void';
        $void['voidReason'] = 'Duplicate';
        $this->send([$this->operation('b', $void, 1)], 1)->assertOk();
        $void['status'] = 'archived';
        $this->send([$this->operation('b', $void, 2)], 2)->assertOk()->assertJsonPath('state.journalEntries.2.number', 'GJ-000002');
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:c', 'number' => 'GJ-000002', 'reserved_number' => 'GJ-000002']);
        $this->member($b, $company);
        $this->send([$this->operation('d', $this->journal('d', 'posted'))], 1)->assertOk()->assertJsonPath('state.journalEntries.3.number', 'GJ-000003');
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('state.journalEntries.2.number', 'GJ-000002');
    }

    public function test_a_save_repairs_a_previously_archived_gap_in_the_database_and_audits_the_renumber(): void
    {
        [$company, $a] = $this->book();
        $this->member($a, $company);
        $saved = $this->send(array_map(fn (string $id): array => $this->operation($id, $this->journal($id, 'posted')), ['a', 'b', 'c']))->assertOk()->json();
        $state = $saved['state'];
        $state['journalEntries'][1]['status'] = 'archived';
        $state['journalEntries'][1]['voidReason'] = 'Legacy deleted duplicate';
        CompanyLedger::query()->sole()->update(['state' => $state]);
        DB::table('journal_entries')->where('source_key', 'journalEntries:b')->update(['status' => 'archived', 'reserved_number' => null]);
        $journalId = DB::table('journal_entries')->where('source_key', 'journalEntries:c')->value('id');
        $this->send([$this->operation('draft', $this->journal('draft'))], 1)->assertOk()->assertJsonPath('state.journalEntries.2.number', 'GJ-000002')->assertJsonPath('state.journalEntries.3.number', '');
        $this->assertDatabaseHas('journal_entries', ['id' => $journalId, 'source_key' => 'journalEntries:c', 'number' => 'GJ-000002', 'reserved_number' => 'GJ-000002']);
        $this->assertDatabaseHas('company_transaction_versions', ['record_id' => 'c', 'version' => 2]);
        $changes = json_decode(DB::table('company_ledger_changes')->where('revision', 2)->value('changes'), true);
        $change = collect($changes)->firstWhere('id', 'c');
        $this->assertSame('GJ-000003', $change['before']['number']);
        $this->assertSame('GJ-000002', $change['after']['number']);
        $this->send([$this->operation('next', $this->journal('next', 'posted'))], 2)->assertOk()->assertJsonPath('state.journalEntries.4.number', 'GJ-000003');
    }

    private function operation(string $id, ?array $record, int $version = 0): array
    {
        return ['sheet' => 'journalEntries', 'id' => $id, 'expected_version' => $version, 'record' => $record];
    }

    private function journal(string $id, string $status = 'draft'): array
    {
        return ['id' => $id, 'number' => 'GJ-000001', 'status' => $status, 'date' => '2026-11-01', 'type' => 'General', 'lines' => [['account' => '1010', 'debit' => 1000, 'credit' => 0], ['account' => '3010', 'debit' => 0, 'credit' => 1000]]];
    }

    private function send(array $operations, int $revision = 0, ?string $requestId = null): TestResponse
    {
        return $this->postJson(route('sheets.database.save'), ['revision' => $revision, 'request_id' => $requestId ?? (string) Str::uuid(), 'operations' => $operations]);
    }

    private function member(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
    }

    /** @return array{Company, User, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $a = User::factory()->firm()->create();
        $b = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($a, ['role' => 'owner']);
        $company->users()->attach($b, ['role' => 'accountant']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'tin' => '000000000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());

        return [$company, $a, $b];
    }
}
