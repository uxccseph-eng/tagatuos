<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\InboxDocument;
use App\Models\Party;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\ClientDatabaseTransfer;
use App\Services\CompanyLedgerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ClientDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $clientFiles = [];

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach (DB::getConnections() as $name => $connection) {
            if (in_array($connection->getDatabaseName(), $this->clientFiles, true)) {
                DB::purge($name);
            }
        }
        foreach ($this->clientFiles as $index => $file) {
            DB::purge('client_test_'.$index);
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_existing_records_are_verified_and_routed_to_a_separate_database(): void
    {
        [$company, $owner] = $this->book();
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [['id' => 'preserved-draft', 'status' => 'draft', 'number' => '', 'date' => '2026-10-01']];
        $state['journalEntries'] = [$this->journal($company)];
        app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()]);
        Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'name' => 'Original supplier']);
        $connection = $this->destination();
        $counts = app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        $this->assertSame(1, $counts['company_ledgers']);
        $this->assertNotNull($company->fresh()->tenancy_activated_at);
        $this->assertSame(1, app(CompanyLedgerService::class)->read($company)['revision']);
        $this->assertSame(1, $counts['journal_entries']);
        $this->assertSame(2, $counts['journal_entry_lines']);
        $this->assertSame(1, $counts['company_ledger_changes']);
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertDatabaseCount('company_ledgers', 1);
        $this->assertSame('preserved-draft', json_decode(DB::connection($connection)->table('company_ledgers')->value('state'), true)['purchases'][0]['id']);
        $this->assertFalse(DB::connection($connection)->getSchemaBuilder()->hasTable('users'));
        $company->run(fn () => Party::query()->where('workspace_key', $company->workspaceKey())->update(['name' => 'Client database supplier']));
        $this->assertDatabaseHas('parties', ['name' => 'Original supplier']);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('revision', 1);
        $this->get(route('account.dashboard'))->assertOk()->assertSee($company->name);
        $this->get(route('my-work'))->assertOk()->assertSee($company->name);
    }

    public function test_posting_is_committed_to_only_the_selected_client_and_survives_a_new_request(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book($owner);
        $firstConnection = $this->destination();
        $secondConnection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($first->id, $firstConnection);
        app(ClientDatabaseTransfer::class)->transfer($second->id, $secondConnection);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal($first)];
        $requestId = (string) Str::uuid();
        $payload = ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => $requestId];
        $this->actingAs($owner)->withSession(['active_company_id' => $first->id])->withHeader('X-Company-ID', (string) $first->id);
        $this->postJson(route('sheets.database.save'), $payload)->assertOk()->assertJsonPath('revision', 1);
        $this->postJson(route('sheets.database.save'), $payload)->assertOk()->assertJsonPath('revision', 1);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonPath('state.journalEntries.0.number', 'GJ-000001');
        $this->assertSame(1, DB::connection($firstConnection)->table('journal_entries')->count());
        $this->assertSame(2, DB::connection($firstConnection)->table('journal_entry_lines')->count());
        $this->assertSame(0, DB::connection($secondConnection)->table('journal_entries')->count());
        $this->assertDatabaseCount('journal_entries', 0);
        $this->withSession(['active_company_id' => $second->id])->withHeader('X-Company-ID', (string) $second->id);
        $this->getJson(route('sheets.database.show'))->assertOk()->assertJsonCount(0, 'state.journalEntries');
        $this->get(route('my-work'))->assertOk()->assertSee('GJ-000001');
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_unassigned_users_and_stale_company_tabs_cannot_write_client_data(): void
    {
        [$company, $owner] = $this->book();
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        $outsider = User::factory()->firm()->create();
        $this->actingAs($outsider)->withSession(['active_company_id' => $company->id]);
        $this->getJson(route('sheets.database.show'))->assertForbidden();
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->withHeader('X-Company-ID', '999');
        $this->postJson(route('sheets.database.save'), [])->assertConflict();
        $this->assertSame(0, DB::connection($connection)->table('company_ledgers')->count());
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_a_missing_client_connection_fails_closed_instead_of_using_old_shared_records(): void
    {
        [$company, $owner] = $this->book();
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        config()->set('database.connections.'.$connection, null);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->getJson(route('sheets.database.show'))->assertStatus(503);
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_transfer_rejects_shared_databases_and_different_existing_records(): void
    {
        [$company] = $this->book();
        config()->set('database.connections.client_central', config('database.connections.sqlite'));
        try {
            app(ClientDatabaseTransfer::class)->transfer($company->id, 'client_central');
            $this->fail('A central database must never become a client destination.');
        } catch (RuntimeException) {
            $this->assertNull($company->fresh()->tenancy_activated_at);
        }
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->migrate($connection);
        DB::connection($connection)->table('company_ledgers')->insert(['company_id' => 999, 'revision' => 0, 'state' => '{}', 'payroll' => '[]']);
        try {
            app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
            $this->fail('Unrelated records must never be overwritten.');
        } catch (RuntimeException) {
            $this->assertNull($company->fresh()->tenancy_activated_at);
            $this->assertSame(999, DB::connection($connection)->table('company_ledgers')->value('company_id'));
        }
    }

    public function test_context_and_connection_are_restored_when_a_client_operation_throws(): void
    {
        [$company] = $this->book();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $this->destination());
        try {
            $company->run(function (): void {
                $this->assertSame('tenant', DB::getDefaultConnection());
                throw new RuntimeException('Interrupted operation');
            });
        } catch (RuntimeException) {
            $this->assertSame('sqlite', DB::getDefaultConnection());
            $this->assertNull(tenancy()->tenant);
        }
    }

    public function test_document_draft_links_use_the_client_database_and_keep_firm_metadata_central(): void
    {
        Storage::fake('documents');
        [$company, $owner] = $this->book();
        $party = Party::factory()->create(['workspace_key' => $company->workspaceKey(), 'classification' => 'Supplier']);
        TaxCode::seedWorkspace($company->workspaceKey());
        $tax = TaxCode::query()->where('workspace_key', $company->workspaceKey())->where('code', 'VAT')->firstOrFail();
        $account = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', '6110')->firstOrFail();
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        $this->actingAs($owner)->post(route('documents.store', $company->id), ['file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\nTest invoice"), 'category' => 'purchases'])->assertRedirect();
        $document = InboxDocument::query()->sole();
        $this->putJson(route('documents.update', ['companyId' => $company->id, 'documentId' => $document->id]), ['version' => 1, 'action' => 'create_draft', 'ledger_revision' => 0, 'sheet' => 'purchases', 'date' => '2026-10-26', 'party_id' => $party->id, 'account_id' => $account->id, 'tax_code_id' => $tax->id, 'gross' => '500.00', 'reference' => 'INV-123', 'description' => ''])->assertRedirect();
        $this->assertSame('linked', $document->fresh()->status);
        $this->assertDatabaseCount('company_ledgers', 0);
        $state = app(CompanyLedgerService::class)->read($company)['state'];
        $this->assertSame('', $state['purchases'][0]['number']);
        $this->assertSame($document->fresh()->linked_record_id, (string) $state['purchases'][0]['id']);
        $this->get(route('documents.show', ['companyId' => $company->id, 'documentId' => $document->id]))->assertOk()->assertSee('Draft');
        $this->assertFalse(DB::connection($connection)->getSchemaBuilder()->hasTable('inbox_documents'));
    }

    public function test_failed_posting_rolls_back_the_client_snapshot_journals_and_audit(): void
    {
        [$company, $owner] = $this->book();
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [$this->journal($company)];
        DB::listen(function (QueryExecuted $query): void {
            if ($query->connectionName === 'tenant' && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'company_ledger_changes')) {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()]);
            $this->fail('The interrupted save must not commit financial records.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        foreach (['company_ledgers', 'journal_entries', 'journal_entry_lines', 'company_ledger_changes'] as $table) {
            $this->assertSame(0, DB::connection($connection)->table($table)->count());
        }
    }

    public function test_firm_infrastructure_stays_central_and_a_destination_cannot_be_reassigned(): void
    {
        [$company] = $this->book();
        [$other] = $this->book();
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        $company->run(function (): void {
            Cache::store('database')->put('client-routing-test', 'central', 60);
            $this->assertSame('central', Cache::store('database')->get('client-routing-test'));
            $this->assertTrue(User::query()->exists());
        });
        $this->assertDatabaseHas('cache', ['value' => serialize('central')]);
        config()->set('database.connections.client_alias', config('database.connections.'.$connection));
        try {
            app(ClientDatabaseTransfer::class)->transfer($other->id, 'client_alias');
            $this->fail('One database must not be shared by different clients.');
        } catch (RuntimeException) {
            $this->assertNull($other->fresh()->tenancy_activated_at);
        }
        $this->artisan('clients:list')->expectsOutputToContain('Separate database')->assertSuccessful();
        $this->artisan('clients:migrate', ['--company' => $company->id])->assertSuccessful();
    }

    public function test_misconfigured_connections_cannot_open_another_clients_database(): void
    {
        [$first, $owner] = $this->book();
        [$second] = $this->book($owner);
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($first->id, $connection);
        $second->forceFill(['tenancy_db_connection' => 'client_misdirected', 'tenancy_db_name' => basename(DB::connection($connection)->getDatabaseName()), 'tenancy_activated_at' => now()])->save();
        config()->set('database.connections.client_misdirected', config('database.connections.'.$connection));
        $this->actingAs($owner)->withSession(['active_company_id' => $second->id]);
        $this->getJson(route('sheets.database.show'))->assertStatus(503);
        $this->assertSame($first->id, DB::connection($connection)->table('client_database_identity')->value('company_id'));
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_masters_and_import_mapping_edits_stay_in_the_selected_client_database(): void
    {
        [$company, $owner] = $this->book();
        $connection = $this->destination();
        app(ClientDatabaseTransfer::class)->transfer($company->id, $connection);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $payload = ['code' => '7160', 'name' => 'Client delivery expense', 'type' => 'Expense', 'normal_balance' => 'Debit', 'statement_section' => 'General and Administrative Expenses', 'is_cash_bank' => false, 'is_advance' => false, 'is_control' => false, 'subsidiary' => null, 'ppe_role' => null, 'ppe_category' => null, 'relief' => false, 'percentage_tax' => false, 'ewt' => false];
        $this->postJson(route('masters.accounts.store'), $payload)->assertCreated();
        $this->assertDatabaseMissing('chart_accounts', ['name' => 'Client delivery expense']);
        $this->assertSame('Client delivery expense', DB::connection($connection)->table('chart_accounts')->where('code', '7160')->value('name'));
        $mapping = ['sheet' => 'payroll', 'headers' => ['Employee Name', 'Basic Salary', 'Tax Deducted'], 'mapping' => ['employee' => 0, 'basic' => 1, 'withholding' => 2], 'version' => 0];
        $this->putJson(route('sheets.import.mapping.save'), $mapping)->assertOk();
        $this->assertDatabaseCount('sheet_import_profiles', 0);
        $this->assertSame(1, DB::connection($connection)->table('sheet_import_profiles')->count());
        $this->get(route('masters.accounts'))->assertOk()->assertSee('Client delivery expense');
        $this->get(route('tax-reports'))->assertOk()->assertSee('RELIEF Sales');
    }

    /** @return array{Company, User} */
    private function book(?User $owner = null): array
    {
        $owner ??= User::factory()->firm()->create();
        $company = Company::factory()->create(['organization_owner_id' => $owner->id]);
        $company->users()->attach($owner, ['role' => 'owner']);
        CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '000000000', 'branch_code' => '00000']]);
        ChartAccount::seedWorkspace($company->workspaceKey());

        return [$company, $owner];
    }

    private function destination(): string
    {
        $index = count($this->clientFiles);
        $path = database_path('client-test-'.Str::uuid().'.sqlite');
        touch($path);
        $this->clientFiles[] = $path;
        $name = 'client_test_'.$index;
        config()->set('database.connections.'.$name, [...config('database.connections.sqlite'), 'database' => $path, 'url' => null]);

        return $name;
    }

    /** @return array<string, mixed> */
    private function journal(Company $company): array
    {
        $accounts = $company->run(fn (): array => ChartAccount::query()->whereIn('code', ['1010', '3010'])->get()->mapWithKeys(fn (ChartAccount $account): array => [$account->code => $account->code.' '.mb_chr(183).' '.$account->name])->all());

        return ['id' => 'first', 'number' => 'GJ-000001', 'status' => 'posted', 'date' => '2026-10-01', 'type' => 'General', 'lines' => [['account' => $accounts['1010'], 'debit' => 1000, 'credit' => 0], ['account' => $accounts['3010'], 'debit' => 0, 'credit' => 1000]]];
    }
}
