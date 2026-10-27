<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\Party;
use App\Models\User;
use App\Services\CompanyLedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CompanyLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_accountants_read_the_same_company_records_and_stale_save_cannot_overwrite(): void
    {
        [$company, $first, $second] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal()];
        $this->asMember($first, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('revision', 0);
        $this->save($state)->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('journals.0.number', 'GJ-000001');
        $this->asMember($second, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('state.journalEntries.0.number', 'GJ-000001');
        $other = CompanyLedgerService::emptyState();
        $other['journalEntries'] = [$this->journal('second', 'GJ-000002')];
        $this->save($other, 0)->assertConflict();
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('company_ledger_changes', 1);
        $this->assertSame('first', CompanyLedger::query()->sole()->state['journalEntries'][0]['id']);
    }

    public function test_posting_is_atomic_and_server_rejects_unbalanced_or_fractional_centavo_lines(): void
    {
        [$company, $first] = $this->book();
        $this->asMember($first, $company);
        foreach ([999, 1000.5] as $credit) {
            $journal = $this->journal();
            $journal['lines'][1]['credit'] = $credit;
            $state = CompanyLedgerService::emptyState();
            $state['journalEntries'] = [$journal];
            $this->save($state)->assertUnprocessable();
        }
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_entry_lines', 0);
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->assertDatabaseCount('company_ledger_changes', 0);
    }

    public function test_retry_is_idempotent_and_request_id_cannot_be_reused_for_changed_data(): void
    {
        [$company, $first] = $this->book();
        $this->asMember($first, $company);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal()];
        $id = (string) Str::uuid();
        $this->save($state, 0, [], $id)->assertOk()->assertJsonPath('revision', 1);
        $this->save($state, 0, [], $id)->assertOk()->assertJsonPath('revision', 1);
        $state['journalEntries'][0]['notes'] = 'Changed';
        $this->save($state, 0, [], $id)->assertConflict();
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('company_ledger_changes', 1);
    }

    public function test_posted_numbers_amounts_and_history_are_preserved_when_voided_or_archived(): void
    {
        [$company, $first] = $this->book();
        $this->asMember($first, $company);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal()];
        $this->save($state)->assertOk();
        $renumbered = $state;
        $renumbered['journalEntries'][0]['number'] = 'GJ-000099';
        $this->save($renumbered, 1)->assertUnprocessable();
        $this->save(CompanyLedgerService::emptyState(), 1)->assertUnprocessable();
        $state['journalEntries'][0]['status'] = 'void';
        $state['journalEntries'][0]['voidReason'] = 'Correction required';
        $this->save($state, 1)->assertOk()->assertJsonCount(0, 'journals');
        $this->assertDatabaseHas('journal_entries', ['number' => 'GJ-000001', 'status' => 'void']);
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->assertDatabaseCount('company_ledger_changes', 2);
    }

    public function test_server_calculates_sales_vat_and_rejects_collection_overpayments(): void
    {
        [$company, $first] = $this->book();
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Example Customer', 'classification' => 'Customer']);
        $this->asMember($first, $company);
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 1, 'number' => 'SI-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Example Customer', 'gross' => 50000, 'tax' => '12% VAT', 'account' => '4020 · Sales - Services', 'terms' => 'On Account', 'cwt' => 'None', 'vatCwt' => 'None']];
        $this->save($state)->assertOk();
        $this->assertDatabaseHas('journal_entry_lines', ['credit' => '446.4300']);
        $this->assertDatabaseHas('journal_entry_lines', ['credit' => '53.5700']);
        $state['collections'] = [['id' => 1, 'number' => 'CR-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Example Customer', 'invoice' => 'SI-000001', 'amount' => 50001, 'bank' => '1010 · Cash on Hand', 'type' => 'Settlement']];
        $this->save($state, 1)->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_payroll_is_shared_and_posts_a_balanced_database_journal(): void
    {
        [$company, $first, $second] = $this->book();
        Employee::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Example Employee']);
        $run = ['id' => 'payroll-one', 'number' => 'PYRJ-000001', 'date' => '2026-10-31', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31', 'status' => 'posted', 'employees' => [['name' => 'Example Employee', 'basic' => '1000.00', 'payType' => 'Taxable']]];
        $this->asMember($first, $company);
        $this->save(CompanyLedgerService::emptyState(), 0, [$run])->assertOk()->assertJsonPath('journals.0.sheet', 'payroll');
        $this->assertDatabaseHas('journal_entry_lines', ['debit' => '1000.0000']);
        $this->assertDatabaseHas('journal_entry_lines', ['credit' => '1000.0000']);
        $this->asMember($second, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('payroll.0.employees.0.name', 'Example Employee');
    }

    public function test_duplicate_numbers_two_drafts_and_invalid_posted_dates_are_rejected(): void
    {
        [$company, $first] = $this->book();
        $this->asMember($first, $company);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal(), $this->journal('second')];
        $this->save($state)->assertUnprocessable();
        $state['journalEntries'] = [$this->journal('first', 'GJ-000001', 'draft'), $this->journal('second', 'GJ-000002', 'draft')];
        $this->save($state)->assertUnprocessable();
        $state['journalEntries'] = [$this->journal()];
        $state['journalEntries'][0]['date'] = '2026-02-30';
        $this->save($state)->assertUnprocessable();
        $this->assertDatabaseCount('company_ledgers', 0);
    }

    public function test_company_access_and_module_permissions_apply_to_reads_and_writes(): void
    {
        [$company, $first] = $this->book();
        $outsider = User::factory()->create();
        $this->asMember($outsider, $company);
        $this->getJson(route('sheets.database.show'))->assertForbidden();
        $this->save(CompanyLedgerService::emptyState())->assertForbidden();
        $reporter = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($reporter, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'reports'])]);
        $this->asMember($reporter, $company);
        $this->getJson(route('sheets.database.show'))->assertOk();
        $this->save(CompanyLedgerService::emptyState())->assertForbidden();
        $company->users()->updateExistingPivot($reporter->id, ['is_suspended' => true]);
        $this->getJson(route('sheets.database.show'))->assertForbidden();
    }

    public function test_stale_company_context_and_foreign_accounts_cannot_be_saved(): void
    {
        [$company, $first] = $this->book();
        $foreign = Company::factory()->create();
        $this->asMember($first, $company);
        $this->withHeader('X-Company-ID', (string) $foreign->id)->save(CompanyLedgerService::emptyState())->assertConflict();
        $this->withHeader('X-Company-ID', (string) $company->id);
        ChartAccount::factory()->create(['workspace_key' => $foreign->workspaceKey(), 'code' => '9999']);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal()];
        $state['journalEntries'][0]['lines'][0]['account'] = '9999 · Foreign Account';
        $this->save($state)->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_books_remain_separate_and_catalog_bootstraps_database_records(): void
    {
        [$company, $first] = $this->book();
        $this->asMember($first, $company);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal()];
        $this->save($state)->assertOk();
        $this->get(route('masters.accounts.catalog'))->assertOk()->assertSee('window.CompanyLedgerDatabase')->assertSee('GJ-000001');
        $second = Company::factory()->create();
        $second->users()->attach($first, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $second->workspaceKey(), 'lookup_key' => $second->workspaceKey(), 'profile' => ['company_name' => 'Second', 'full_name' => 'Example']]);
        $this->asMember($first, $second);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('revision', 0)->assertJsonCount(0, 'state.journalEntries');
    }

    public function test_purchase_withholding_payment_and_linked_certificate_do_not_double_post(): void
    {
        [$company, $first] = $this->book();
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Example Supplier', 'classification' => 'Supplier']);
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [['id' => 1, 'number' => 'BILL-000001', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Example Supplier', 'gross' => 112000, 'tax' => 'VAT (12%)', 'ewt' => 2, 'ewtCode' => 'WC160', 'wvat' => 0, 'account' => '7110 · Rent Expense', 'terms' => 'On Account']];
        $state['payments'] = [['id' => 1, 'number' => 'PV-000001', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Example Supplier', 'billId' => 1, 'amount' => 110000, 'bank' => '1010 · Cash on Hand', 'type' => 'AP Settlement']];
        $state['wtpCertificates'] = [['id' => 'issued', 'number' => '2307I-000001', 'date' => '2026-10-01', 'status' => 'posted', 'supplier' => 'Example Supplier', 'billId' => 1, 'taxBase' => 100000, 'ewt' => 2, 'ewtCode' => 'WC160', 'wvat' => 0]];
        $this->asMember($first, $company);
        $this->save($state)->assertOk();
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseHas('journal_entry_lines', ['credit' => '20.0000']);
        $state['payments'][] = [...$state['payments'][0], 'id' => 2, 'number' => 'PV-000002', 'amount' => 1];
        $this->save($state, 1)->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_cwt_and_receipts_reconcile_to_the_source_invoice_and_duplicate_certificates_fail(): void
    {
        [$company, $first] = $this->book();
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Example Customer', 'classification' => 'Customer']);
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 1, 'number' => 'SI-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Example Customer', 'gross' => 100000, 'tax' => 'Non-VAT', 'account' => '4020 · Sales - Services', 'terms' => 'On Account', 'cwt' => 'WC160 · 2%', 'vatCwt' => 'None']];
        $state['collections'] = [['id' => 1, 'number' => 'CR-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Example Customer', 'invoice' => 'SI-000001', 'amount' => 98000, 'bank' => '1010 · Cash on Hand', 'type' => 'Settlement']];
        $state['cwtCertificates'] = [['id' => 'received', 'number' => '2307R-000001', 'date' => '2026-10-01', 'status' => 'posted', 'customer' => 'Example Customer', 'invoice' => 'SI-000001', 'taxBase' => 100000, 'atc' => 'WC160 · 2%', 'amount' => 2000, 'offset' => '1150 · Prepaid CWT']];
        $this->asMember($first, $company);
        $this->save($state)->assertOk();
        $this->assertDatabaseCount('journal_entries', 3);
        $state['cwtCertificates'][] = [...$state['cwtCertificates'][0], 'id' => 'duplicate', 'number' => '2307R-000002'];
        $this->save($state, 1)->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 3);
    }

    public function test_ten_accountants_share_the_book_without_silent_overwrites(): void
    {
        [$company, $first, $second] = $this->book();
        $users = collect([$first, $second]);
        foreach (range(3, 10) as $index) {
            $user = User::factory()->accountant()->create(['must_change_password' => false]);
            $company->users()->attach($user, ['role' => 'accountant']);
            $users->push($user);
        }
        $state = CompanyLedgerService::emptyState();
        foreach ($users as $index => $user) {
            $this->asMember($user, $company);
            if ($index > 0) {
                $this->save(CompanyLedgerService::emptyState(), 0)->assertConflict();
            }
            $snapshot = $this->getJson(route('sheets.database.show'))->assertOk()->json();
            $state = $snapshot['state'];
            $state['journalEntries'][] = $this->journal('entry-'.$index, 'GJ-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT));
            $this->save($state, $snapshot['revision'])->assertOk()->assertJsonPath('revision', $index + 1);
        }
        $this->assertDatabaseCount('journal_entries', 10);
        $this->assertDatabaseCount('journal_entry_lines', 20);
        $this->assertDatabaseCount('company_ledger_changes', 10);
    }

    public function test_export_rejects_a_stale_database_revision_before_processing_client_rows(): void
    {
        [$company, $first] = $this->book();
        $this->asMember($first, $company);
        $this->save(CompanyLedgerService::emptyState())->assertOk();
        $this->withHeader('X-Ledger-Revision', '0')->postJson(route('reports.sales.dat'), [])->assertConflict();
        $this->withHeader('X-Ledger-Revision', '1')->postJson(route('reports.sales.dat'), [])->assertUnprocessable();
    }

    public function test_payroll_remittance_cannot_be_duplicated_or_left_active_after_voiding_payroll(): void
    {
        [$company, $first] = $this->book();
        Employee::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Example Employee']);
        $run = ['id' => 'run', 'number' => 'PYRJ-000001', 'date' => '2026-10-31', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31', 'status' => 'posted', 'employees' => [['name' => 'Example Employee', 'basic' => '1000.00']]];
        $state = CompanyLedgerService::emptyState();
        $remit = $this->journal();
        $remit['date'] = '2026-11-01';
        $remit['origin'] = 'payroll-remittance';
        $remit['payrollAllocations'] = [['runId' => 'run', 'amounts' => ['salary' => 100000]]];
        $remit['lines'] = [['account' => '2340 · Salaries Payable', 'debit' => 100000, 'credit' => 0], ['account' => '1010 · Cash on Hand', 'debit' => 0, 'credit' => 100000]];
        $state['journalEntries'] = [$remit];
        $this->asMember($first, $company);
        $this->save($state, 0, [$run])->assertOk();
        $this->assertDatabaseCount('journal_entries', 2);
        $run['status'] = 'void';
        $run['voidReason'] = 'Correction';
        $this->save($state, 1, [$run])->assertUnprocessable();
        $run['status'] = 'posted';
        $state['journalEntries'][] = [...$remit, 'id' => 'duplicate', 'number' => 'GJ-000002'];
        $this->save($state, 1, [$run])->assertUnprocessable();
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame(1, CompanyLedger::query()->sole()->revision);
    }

    public function test_mid_save_audit_failure_rolls_back_journals_lines_and_snapshot(): void
    {
        [$company, $first] = $this->book();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'company_ledger_changes')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal()];
        try {
            app(CompanyLedgerService::class)->save($company, $first, ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()]);
            $this->fail('The audit failure must abort the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_entry_lines', 0);
        $this->assertDatabaseCount('company_ledgers', 0);
        $this->assertDatabaseCount('company_ledger_changes', 0);
    }

    public function test_read_only_modules_do_not_receive_payroll_details_or_private_attachments(): void
    {
        [$company] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['sales'] = [['id' => 1, 'number' => 'SI-000001', 'status' => 'draft', 'date' => '2026-10-01', 'attachments' => [['name' => 'PRIVATE-DOCUMENT']]]];
        CompanyLedger::factory()->create(['company_id' => $company->id, 'state' => $state, 'payroll' => [['name' => 'PRIVATE-PAYROLL']]]);
        $reader = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($reader, ['role' => 'staff', 'allowed_tabs' => json_encode(['dashboard', 'reports'])]);
        $this->asMember($reader, $company);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonCount(0, 'payroll')->assertJsonMissingPath('state.sales.0.attachments');
        $this->get(route('masters.accounts.catalog'))->assertOk()->assertDontSee('PRIVATE-DOCUMENT')->assertDontSee('PRIVATE-PAYROLL');
        $company->users()->updateExistingPivot($reader->id, ['allowed_tabs' => json_encode(['dashboard', 'lapsing'])]);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonCount(0, 'state.sales')->assertJsonCount(0, 'journals');
    }

    private function journal(string $id = 'first', string $number = 'GJ-000001', string $status = 'posted'): array
    {
        return ['id' => $id, 'number' => $number, 'status' => $status, 'date' => '2026-10-01', 'type' => 'General', 'lines' => [['account' => '1010 · Cash on Hand', 'debit' => 1000, 'credit' => 0], ['account' => '3010 · Owner Capital', 'debit' => 0, 'credit' => 1000]]];
    }

    private function save(array $state, int $revision = 0, array $payroll = [], ?string $requestId = null): TestResponse
    {
        return $this->postJson(route('sheets.database.save'), ['state' => $state, 'payroll' => $payroll, 'revision' => $revision, 'request_id' => $requestId ?? (string) Str::uuid()]);
    }

    private function asMember(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', (string) $company->id);
    }

    /** @return array{Company, User, User} */
    private function book(): array
    {
        $company = Company::factory()->create();
        $first = User::factory()->firm()->create();
        $second = User::factory()->accountant()->create(['must_change_password' => false]);
        $company->users()->attach($first, ['role' => 'owner']);
        $company->users()->attach($second, ['role' => 'accountant']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '000000000', 'branch_code' => '00000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());

        return [$company, $first, $second];
    }
}
