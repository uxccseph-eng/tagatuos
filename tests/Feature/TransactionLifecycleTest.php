<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_void_journal_compacts_numbers_without_destroying_history(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal(1), $this->journal(2), $this->journal(3)];
        $saved = $this->save($company, $owner, $state);
        $header = DB::table('journal_entries')->where('source_key', 'journalEntries:2')->sole();
        DB::table('client_follow_ups')->insert(['company_id' => $company->id, 'request_id' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64), 'category' => 'other', 'request_text' => 'Clarify source', 'created_by' => $owner->id, 'linked_sheet' => 'journalEntries', 'linked_record_id' => '2', 'transaction_number' => 'GJ-000002']);
        $state = $saved['state'];
        $state['journalEntries'][0]['status'] = 'void';
        $state['journalEntries'][0]['voidReason'] = 'Duplicate';
        $this->save($company, $owner, $state, 1);
        $state['journalEntries'][0]['status'] = 'archived';
        $result = $this->save($company, $owner, $state, 2);
        $this->assertSame(['GJ-000001', 'GJ-000001', 'GJ-000002'], array_column($result['state']['journalEntries'], 'number'));
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:1', 'number' => 'GJ-000001', 'status' => 'archived', 'reserved_number' => null]);
        $this->assertDatabaseHas('journal_entries', ['id' => $header->id, 'source_key' => 'journalEntries:2', 'number' => 'GJ-000001', 'reserved_number' => 'GJ-000001', 'posted_by' => $owner->id]);
        $changes = json_decode(DB::table('company_ledger_changes')->where('revision', 3)->value('changes'), true);
        $renumber = collect($changes)->firstWhere('id', '2');
        $this->assertSame('GJ-000002', $renumber['before']['number']);
        $this->assertSame('GJ-000001', $renumber['after']['number']);
        $next = $result['state'];
        $next['journalEntries'][] = $this->journal(4, '');
        $last = $this->save($company, $owner, $next, 3);
        $this->assertSame('GJ-000003', $last['state']['journalEntries'][3]['number']);
        $this->assertDatabaseHas('client_follow_ups', ['company_id' => $company->id, 'linked_record_id' => '2', 'transaction_number' => 'GJ-000001']);
        $this->assertDatabaseCount('journal_entries', 4);
        $this->assertDatabaseCount('journal_entry_lines', 8);
    }

    public function test_sales_renumbering_updates_receipts_and_cwt_without_reassigning_source_ids(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        foreach ([1, 2, 3] as $id) {
            $state['sales'][] = ['id' => $id, 'number' => 'SI-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Customer', 'gross' => 10000, 'tax' => 'Non-VAT', 'account' => '4020', 'terms' => 'On Account', 'cwt' => 'WC160 2%', 'vatCwt' => 'None'];
        }
        $state['collections'] = [['id' => 1, 'number' => 'CR-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Customer', 'invoice' => 'SI-000003', 'amount' => 9800, 'bank' => '1010', 'type' => 'Settlement']];
        $state['cwtCertificates'] = [['id' => 1, 'number' => '2307R-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Customer', 'invoice' => 'SI-000003', 'taxBase' => 10000, 'atc' => 'WC160', 'amount' => 200, 'offset' => '1150']];
        $saved = $this->save($company, $owner, $state);
        $state = $saved['state'];
        $state['sales'][0]['status'] = 'void';
        $state['sales'][0]['voidReason'] = 'Wrong source';
        $this->save($company, $owner, $state, 1);
        $state['sales'][0]['status'] = 'archived';
        $saved = $this->save($company, $owner, $state, 2);
        $this->assertSame('SI-000002', $saved['state']['sales'][2]['number']);
        foreach (['collections', 'cwtCertificates'] as $sheet) {
            $this->assertSame('SI-000002', $saved['state'][$sheet][0]['invoice']);
            $this->assertSame(3, $saved['state'][$sheet][0]['saleId']);
        }
        $this->assertCount(4, $saved['journals']);
    }

    public function test_client_compacted_references_are_not_shifted_twice_by_the_server(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        foreach ([1, 2, 3] as $id) {
            $state['purchases'][] = [...$this->bill($id), 'terms' => 'On Account', 'ewt' => 0, 'ewtCode' => ''];
        }
        $state['payments'] = [['id' => 1, 'number' => 'PV-000001', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Supplier', 'billId' => 3, 'bill' => 'BILL-000003', 'amount' => 1000, 'bank' => '1010', 'type' => 'AP Settlement']];
        $saved = $this->save($company, $owner, $state);
        $state = $saved['state'];
        $state['purchases'][0]['status'] = 'void';
        $state['purchases'][0]['voidReason'] = 'Duplicate';
        $this->save($company, $owner, $state, 1);
        $state['purchases'][0]['status'] = 'archived';
        $state['purchases'][1]['number'] = 'BILL-000001';
        $state['purchases'][2]['number'] = 'BILL-000002';
        $state['payments'][0]['bill'] = 'BILL-000002';
        $saved = $this->save($company, $owner, $state, 2);
        $this->assertSame(3, $saved['state']['payments'][0]['billId']);
        $this->assertSame('BILL-000002', $saved['state']['payments'][0]['bill']);
    }

    public function test_cash_purchase_posts_one_linked_wtp_without_a_second_withholding_journal(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [$this->bill(1)];
        $saved = $this->save($company, $owner, $state);
        $this->assertCount(1, $saved['state']['wtpCertificates']);
        $certificate = $saved['state']['wtpCertificates'][0];
        $this->assertSame('posted', $certificate['status']);
        $this->assertSame('2307I-000001', $certificate['number']);
        $this->assertSame($certificate['number'], $certificate['certificate']);
        $this->assertSame(100000, $certificate['taxBase']);
        $this->assertSame(1, $certificate['billId']);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame(20.0, (float) DB::table('journal_entry_lines')->where('account_name', 'like', '2140%')->sum('credit'));
        $this->save($company, $owner, $saved['state'], 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $next = $saved['state'];
        $next['purchases'][0]['gross'] = 224000;
        $updated = $this->save($company, $owner, $next, 2);
        $this->assertCount(1, $updated['state']['wtpCertificates']);
        $this->assertSame(200000, $updated['state']['wtpCertificates'][0]['taxBase']);
        $this->assertSame('2307I-000001', $updated['state']['wtpCertificates'][0]['number']);
    }

    public function test_draft_credit_and_zero_ewt_purchases_do_not_create_wtp_entries(): void
    {
        foreach ([['status' => 'draft'], ['terms' => 'On Account'], ['ewt' => 0, 'ewtCode' => '']] as $changes) {
            [$company, $owner] = $this->book();
            $state = CompanyLedgerService::emptyState();
            $state['purchases'] = [[...$this->bill(1), ...$changes]];
            $saved = $this->save($company, $owner, $state, 0);
            $this->assertSame([], $saved['state']['wtpCertificates']);
        }
    }

    public function test_invalid_cash_posting_rolls_back_generated_wtp_and_does_not_consume_a_number(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [[...$this->bill(1), 'ewtCode' => 'INVALID']];
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
        $this->postJson(route('sheets.database.save'), ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $state['purchases'][0]['ewtCode'] = 'WC160';
        $saved = $this->save($company, $owner, $state);
        $this->assertSame('2307I-000001', $saved['state']['wtpCertificates'][0]['number']);
    }

    public function test_existing_manual_wtp_is_not_duplicated_by_cash_purchase_posting(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [$this->bill(1)];
        $state['wtpCertificates'] = [['id' => 'manual', 'number' => '2307I-000001', 'certificate' => 'Supplier-2307', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Supplier', 'billId' => 1, 'bill' => 'BILL-000001', 'taxBase' => 100000, 'ewt' => 2, 'ewtCode' => 'WC160', 'wvat' => 0]];
        $saved = $this->save($company, $owner, $state);
        $this->assertCount(1, $saved['state']['wtpCertificates']);
        $this->assertSame('manual', $saved['state']['wtpCertificates'][0]['id']);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_deleted_numbers_cannot_be_restored_over_a_renumbered_posting(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal(1), $this->journal(2)];
        $this->save($company, $owner, $state);
        $state['journalEntries'][0]['status'] = 'void';
        $state['journalEntries'][0]['voidReason'] = 'Duplicate';
        $this->save($company, $owner, $state, 1);
        $state['journalEntries'][0]['status'] = 'archived';
        $saved = $this->save($company, $owner, $state, 2);
        $state = $saved['state'];
        $state['journalEntries'][0]['status'] = 'void';
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
        $this->postJson(route('sheets.database.save'), ['state' => $state, 'payroll' => [], 'revision' => 3, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:1', 'status' => 'archived', 'reserved_number' => null]);
        $this->assertDatabaseHas('journal_entries', ['source_key' => 'journalEntries:2', 'status' => 'posted', 'reserved_number' => 'GJ-000001']);
    }

    public function test_all_sheet_and_payroll_drafts_remain_unnumbered_when_other_entries_are_deleted(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        foreach (CompanyLedgerService::SHEETS as $sheet) {
            $state[$sheet] = [['id' => 'draft-'.$sheet, 'date' => '2026-10-01', 'status' => 'draft', 'number' => 'UNSAVED-000099']];
        }
        $payroll = [['id' => 'draft-payroll', 'date' => '2026-10-01', 'status' => 'draft', 'number' => 'PYRJ-000099']];
        $saved = app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => $payroll, 'revision' => 0, 'request_id' => (string) Str::uuid()]);
        foreach (CompanyLedgerService::SHEETS as $sheet) {
            $this->assertSame('', $saved['state'][$sheet][0]['number']);
        }
        $this->assertSame('', $saved['payroll'][0]['number']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    private function bill(int $id): array
    {
        return ['id' => $id, 'number' => 'BILL-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Supplier', 'tin' => '12345678900000', 'gross' => 112000, 'tax' => 'VAT (12%)', 'ewt' => 2, 'ewtCode' => 'WC160', 'wvat' => 0, 'account' => '7110', 'terms' => 'Cash', 'bank' => '1010'];
    }

    private function journal(int $id, ?string $number = null): array
    {
        return ['id' => $id, 'number' => $number ?? 'GJ-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'date' => '2026-10-01', 'status' => 'posted', 'type' => 'General', 'lines' => [['account' => '1010', 'debit' => 1000, 'credit' => 0], ['account' => '3010', 'debit' => 0, 'credit' => 1000]]];
    }

    private function save(Company $company, User $owner, array $state, int $revision = 0): array
    {
        return app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
    }

    /** @return array{Company, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->firm()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey()]);
        ChartAccount::seedWorkspace($company->workspaceKey());
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Supplier', 'classification' => 'Supplier']);
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Customer', 'classification' => 'Customer']);

        return [$company, $owner];
    }
}
