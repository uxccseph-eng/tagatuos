<?php

namespace Tests\Feature;

use App\Jobs\ProvisionClientDatabase;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\InboxDocument;
use App\Models\Party;
use App\Models\User;
use App\Services\ClientProvisioner;
use App\Services\CompanyLedgerService;
use App\Services\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class ClientProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $databaseFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'database.connections.client_template' => [...config('database.connections.sqlite'), 'url' => null, 'database' => null]]);
        Storage::fake('client_files');
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->end();
        DB::purge('tenant');
        foreach ($this->databaseFiles as $path) {
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    public function test_creating_a_client_dispatches_setup_after_the_central_transaction(): void
    {
        Bus::fake([ProvisionClientDatabase::class]);
        $owner = User::factory()->firm()->create();
        $this->actingAs($owner)->post(route('account.companies.store'), ['name' => 'New customer'])->assertRedirect(route('account.dashboard'));
        $company = Company::query()->sole();
        $this->assertSame('pending', $company->provisioning_status);
        Bus::assertDispatched(ProvisionClientDatabase::class, fn (ProvisionClientDatabase $job): bool => $job->companyId === $company->id && $job->afterCommit);
        $this->assertFalse($company->databaseReady());
    }

    public function test_mysql_provisioning_grants_runtime_access_to_the_exact_database_before_migrating(): void
    {
        $company = $this->newClient();
        $name = 'client_'.substr($company->workspaceKey(), 0, 40);
        config([
            'database.connections.client_template.driver' => 'mysql',
            'database.connections.client_template.username' => 'client_runtime',
            'database.connections.client_provisioner' => ['driver' => 'grant-test', 'username' => 'client_provisioner'],
            'tenancy.runtime_user_host' => 'localhost',
        ]);
        $pdo = DB::connection('sqlite')->getPdo();
        $admin = $this->getMockBuilder(Connection::class)->setConstructorArgs([$pdo, '', '', ['driver' => 'grant-test']])->onlyMethods(['statement'])->getMock();
        $statements = [];
        $admin->expects($this->exactly(2))->method('statement')->willReturnCallback(function (string $sql, array $bindings = []) use (&$statements): bool {
            $statements[] = $sql;

            return true;
        });
        DB::extend('grant-test', fn (): Connection => $admin);
        $this->mock(DatabaseManager::class)->shouldReceive('createTenantConnection')->once()->andThrow(new RuntimeException('Stop before connecting to MySQL in this regression test.'));

        try {
            app(ClientProvisioner::class)->provision($company->id);
            $this->fail('The test must stop before the tenant connection is opened.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stop before connecting to MySQL in this regression test.', $exception->getMessage());
        }

        $this->assertSame([
            'CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'GRANT ALL PRIVILEGES ON `client\\_'.substr($company->workspaceKey(), 0, 40)."`.* TO 'client_runtime'@'localhost'",
        ], $statements);
    }

    public function test_setup_creates_and_seeds_a_database_and_private_folder_idempotently(): void
    {
        $company = $this->newClient();
        app(ClientProvisioner::class)->provision($company->id);
        $company->refresh();
        $this->assertTrue($company->databaseReady());
        $this->assertFileExists(database_path($company->tenancy_db_name));
        Storage::disk('client_files')->assertExists($company->storageDirectory().'/identity.json');
        $company->run(function () use ($company): void {
            $this->assertSame('tenant', DB::getDefaultConnection());
            $this->assertGreaterThan(0, ChartAccount::query()->count());
            $this->assertSame(0, ChartAccount::query()->where('company_id', '!=', $company->id)->count());
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('users'));
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('companies'));
        });
        app(ClientProvisioner::class)->provision($company->id);
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(0, DB::table('chart_accounts')->count());
        $this->assertSame(1, $company->fresh()->provisioning_attempts);
        $originalName = $company->tenancy_db_name;
        $originalFolder = $company->storageDirectory();
        $company->update(['name' => 'Renamed customer']);
        $this->assertSame($originalName, $company->fresh()->tenancy_db_name);
        $this->assertSame($originalFolder, $company->storageDirectory());
    }

    public function test_two_client_databases_and_nested_contexts_do_not_share_records(): void
    {
        $a = $this->readyClient();
        $b = $this->readyClient();
        $a->run(function () use ($a, $b): void {
            Party::factory()->create(['workspace_key' => $a->workspaceKey(), 'name' => 'A supplier']);
            $b->run(fn () => $this->assertSame(0, Party::query()->count()));
            $this->assertSame('A supplier', Party::query()->sole()->name);
        });
        $b->run(fn () => $this->assertSame(0, Party::query()->count()));
        $this->assertSame(0, DB::table('parties')->count());
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_setup_ignores_tables_visible_in_another_client_schema(): void
    {
        $existing = $this->readyClient();
        $new = $this->newClient();
        $manager = app(DatabaseManager::class);
        $this->partialMock(DatabaseManager::class, function ($mock) use ($manager, $existing): void {
            $mock->shouldReceive('createTenantConnection')->andReturnUsing(function (Company $company) use ($manager, $existing): void {
                $manager->createTenantConnection($company);
                DB::connection('tenant')->statement('ATTACH DATABASE ? AS other_client', [database_path($existing->tenancy_db_name)]);
            });
        });

        app(ClientProvisioner::class)->provision($new->id);

        $this->assertTrue($new->fresh()->databaseReady());
        $this->assertTrue($existing->fresh()->databaseReady());
        $new->fresh()->run(function () use ($new): void {
            $this->assertSame($new->id, DB::table('client_database_identity')->value('company_id'));
            $this->assertSame(0, CompanySetting::query()->count());
            $this->assertGreaterThan(0, ChartAccount::query()->count());
        });
    }

    public function test_two_tabs_save_to_their_own_database_after_switching_session_preference(): void
    {
        $owner = User::factory()->firm()->create();
        $a = $this->readyClient($owner);
        $b = $this->readyClient($owner);
        $this->actingAs($owner)->withSession(['active_company_id' => $b->id, '_token' => 'stable']);
        $state = CompanyLedgerService::emptyState();
        $state['purchases'] = [['id' => 1, 'status' => 'draft', 'number' => '', 'date' => '2026-10-01']];
        $this->withHeader('X-Company-ID', (string) $a->id)->postJson(route('sheets.database.save', ['company_id' => $a->id]), ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('company_id', $a->id)->assertSessionHas('active_company_id', $b->id)->assertSessionHas('_token', 'stable');
        $this->getJson(route('sheets.database.show', ['company_id' => $a->id]))->assertOk()->assertJsonCount(1, 'state.purchases');
        $this->withHeader('X-Company-ID', (string) $b->id)->getJson(route('sheets.database.show', ['company_id' => $b->id]))->assertOk()->assertJsonCount(0, 'state.purchases');
        $this->assertSame(0, DB::table('company_ledgers')->count());
    }

    public function test_pending_clients_cannot_open_or_save_and_only_an_owner_can_retry(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->newClient($owner);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->getJson(route('sheets.database.show', ['company_id' => $company->id]))->assertStatus(503);
        Bus::fake([ProvisionClientDatabase::class]);
        $this->post(route('account.companies.provision', $company->id))->assertRedirect(route('account.dashboard'));
        Bus::assertDispatched(ProvisionClientDatabase::class);
        $accountant = User::factory()->create();
        $company->users()->attach($accountant, ['role' => 'accountant']);
        $this->actingAs($accountant)->postJson(route('account.companies.provision', $company->id))->assertForbidden();
        Bus::assertDispatchedTimes(ProvisionClientDatabase::class, 1);
    }

    public function test_a_spoofed_database_identity_is_rejected_and_context_is_restored(): void
    {
        $company = $this->readyClient();
        $company->run(fn () => DB::table('client_database_identity')->update(['company_id' => 999]));
        try {
            $company->run(fn () => Party::query()->count());
            $this->fail('A foreign database must never be read.');
        } catch (LogicException) {
            $this->assertSame('sqlite', DB::getDefaultConnection());
            $this->assertFalse(app(Tenancy::class)->initialized);
        }
    }

    public function test_failed_setup_is_retryable_without_overwriting_existing_records(): void
    {
        $company = $this->newClient();
        $this->partialMock(ClientProvisioner::class, function ($mock): void {
            $mock->shouldReceive('migrate')->once()->andThrow(new RuntimeException('Injected schema failure'));
        });
        try {
            app(ClientProvisioner::class)->provision($company->id);
            $this->fail('Setup must fail.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $company->fresh()->provisioning_status);
            $this->assertNull($company->fresh()->tenancy_activated_at);
        }
        $this->app->forgetInstance(ClientProvisioner::class);
        app(ClientProvisioner::class)->provision($company->id);
        $this->assertTrue($company->fresh()->databaseReady());
    }

    public function test_existing_shared_data_is_not_silently_replaced_by_an_empty_database(): void
    {
        $company = $this->newClient();
        Party::factory()->create(['workspace_key' => $company->workspaceKey()]);
        try {
            app(ClientProvisioner::class)->provision($company->id);
            $this->fail('A verified transfer is required.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $company->fresh()->provisioning_status);
            $this->assertSame(1, DB::table('parties')->count());
            $this->assertNull($company->fresh()->tenancy_activated_at);
        }
    }

    public function test_client_migrations_run_for_every_ready_database_and_retain_data(): void
    {
        $a = $this->readyClient();
        $b = $this->readyClient();
        $a->run(fn () => Party::factory()->create(['workspace_key' => $a->workspaceKey()]));
        $this->artisan('clients:migrate')->assertSuccessful();
        $a->run(fn () => $this->assertSame(1, Party::query()->count()));
        $b->run(fn () => $this->assertSame(0, Party::query()->count()));
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    public function test_uploaded_documents_use_the_client_private_folder_and_keep_access_checks(): void
    {
        Storage::fake('documents');
        $owner = User::factory()->firm()->create();
        $company = $this->readyClient($owner);
        $this->actingAs($owner)->postJson(route('documents.store', $company->id), [
            'file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\nClient invoice"),
            'category' => 'purchases',
        ])->assertRedirect();
        $document = InboxDocument::query()->sole();
        $this->assertStringStartsWith($company->storageDirectory().'/documents/', $document->path);
        Storage::disk('documents')->assertExists($document->path);
        $url = route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]);
        $this->get($url)->assertOk()->assertStreamedContent("%PDF-1.4\nClient invoice");
        $this->actingAs(User::factory()->firm()->create())->get($url)->assertNotFound();
    }

    public function test_setup_resumes_after_a_schema_was_created_but_not_logged(): void
    {
        $company = $this->newClient();
        $this->partialMock(ClientProvisioner::class, function ($mock): void {
            $mock->shouldReceive('migrate')->once()->andReturnUsing(function (): void {
                $previous = DB::getDefaultConnection();
                DB::setDefaultConnection('tenant');
                try {
                    $migration = require database_path('migrations/clients/2026_10_28_030119_create_client_accounting_tables.php');
                    $migration->up();
                } finally {
                    DB::setDefaultConnection($previous);
                }
                throw new RuntimeException('Interrupted after schema creation');
            });
        });
        try {
            app(ClientProvisioner::class)->provision($company->id);
            $this->fail('The interrupted setup must remain closed.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $company->fresh()->provisioning_status);
        }
        $this->app->forgetInstance(ClientProvisioner::class);
        app(ClientProvisioner::class)->provision($company->id);
        $this->assertTrue($company->fresh()->databaseReady());
        $company->refresh()->run(fn () => $this->assertGreaterThan(0, ChartAccount::query()->count()));
    }

    public function test_company_books_displays_setup_status_without_opening_pending_databases(): void
    {
        $owner = User::factory()->firm()->create();
        $ready = $this->readyClient($owner);
        $pending = $this->newClient($owner);
        $this->actingAs($owner)->get(route('account.dashboard'))->assertOk()
            ->assertSee($ready->name)->assertSee($pending->name)
            ->assertSee('Ready database')->assertSee('Pending database')->assertSee('Retry setup');
        $this->post(route('account.companies.open', $ready->id))->assertRedirect(route('dashboard', ['company_id' => $ready->id]));
        $this->postJson(route('account.companies.open', $pending->id))->assertConflict();
    }

    public function test_switching_clients_cannot_disconnect_an_open_financial_transaction(): void
    {
        $a = $this->readyClient();
        $b = $this->readyClient();
        $a->run(function () use ($a, $b): void {
            DB::transaction(function () use ($a, $b): void {
                Party::factory()->create(['workspace_key' => $a->workspaceKey(), 'name' => 'Committed supplier']);
                try {
                    $b->run(fn () => Party::query()->count());
                    $this->fail('An active transaction cannot switch databases.');
                } catch (LogicException) {
                    $this->assertSame($a->id, app(TenantContext::class)->company->id);
                    $this->assertSame(1, DB::connection('tenant')->transactionLevel());
                }
            });
        });
        $a->run(fn () => $this->assertSame('Committed supplier', Party::query()->sole()->name));
        $b->run(fn () => $this->assertSame(0, Party::query()->count()));
    }

    public function test_posted_corrections_only_replace_journal_lines_in_the_selected_client_database(): void
    {
        $owner = User::factory()->firm()->create();
        $a = $this->readyClient($owner);
        $b = $this->readyClient($owner);
        $state = CompanyLedgerService::emptyState();
        $state['journalEntries'] = [['id' => 'one', 'number' => 'GJ-000001', 'date' => '2026-10-01', 'status' => 'posted', 'type' => 'General', 'lines' => [['account' => '1010', 'debit' => 1000, 'credit' => 0], ['account' => '3010', 'debit' => 0, 'credit' => 1000]]]];
        foreach ([$a, $b] as $company) {
            app(CompanyLedgerService::class)->save($company, $owner, ['state' => $state, 'payroll' => [], 'revision' => 0, 'request_id' => (string) Str::uuid()]);
        }
        $state['journalEntries'][0]['lines'][0]['debit'] = 2000;
        $state['journalEntries'][0]['lines'][1]['credit'] = 2000;
        $this->actingAs($owner)->withSession(['active_company_id' => $b->id])->withHeader('X-Company-ID', (string) $a->id)
            ->postJson(route('sheets.database.save', ['company_id' => $a->id]), ['state' => $state, 'payroll' => [], 'revision' => 1, 'request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('journals.0.lines.0.debit', 2000);
        $a->run(function (): void {
            $this->assertSame(1, DB::table('journal_entries')->count());
            $this->assertSame('20.0000', number_format((float) DB::table('journal_entry_lines')->where('debit', '>', 0)->value('debit'), 4, '.', ''));
        });
        $b->run(fn () => $this->assertSame(1000, app(CompanyLedgerService::class)->read($b)['journals'][0]['lines'][0]['debit']));
    }

    private function newClient(?User $owner = null): Company
    {
        $owner ??= User::factory()->firm()->create();
        $company = Company::factory()->create(['organization_owner_id' => $owner->id]);
        $company->users()->attach($owner, ['role' => 'owner']);
        $this->databaseFiles[] = database_path('client_'.substr($company->workspaceKey(), 0, 40).'.sqlite');

        return $company;
    }

    private function readyClient(?User $owner = null): Company
    {
        $company = $this->newClient($owner);
        app(ClientProvisioner::class)->provision($company->id);
        $company->refresh()->run(fn () => CompanySetting::factory()->create(['workspace_key' => $company->workspaceKey(), 'lookup_key' => $company->workspaceKey(), 'profile' => ['company_name' => $company->name, 'full_name' => 'Example', 'vat_type' => 'vat', 'tin' => '000000000', 'branch_code' => '00000']]));

        return $company;
    }
}
