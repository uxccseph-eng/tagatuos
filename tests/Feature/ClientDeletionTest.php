<?php

namespace Tests\Feature;

use App\Jobs\DeleteClientData;
use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\User;
use App\Services\ClientDeletion;
use App\Services\ClientProvisioner;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class ClientDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'database.connections.client_template' => [...config('database.connections.sqlite'), 'url' => null, 'database' => null]]);
        Storage::fake('client_files');
        config(['filesystems.disks.documents.root' => Storage::disk('client_files')->path('')]);
        Bus::fake([DeleteClientData::class]);
    }

    protected function tearDown(): void
    {
        app(Tenancy::class)->end();
        DB::purge('tenant');
        DB::purge('client_delete');
        foreach ($this->files as $path) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
        parent::tearDown();
    }

    private function client(User $owner, bool $ready = true): Company
    {
        $company = Company::factory()->create(['organization_owner_id' => $owner->id]);
        $company->users()->attach($owner, ['role' => 'owner']);
        $this->files[] = database_path('client_'.substr($company->workspaceKey(), 0, 40).'.sqlite');
        if ($ready) {
            app(ClientProvisioner::class)->provision($company->id);
        }

        return $company->fresh();
    }

    public function test_owner_must_confirm_exact_name_and_password_before_deletion_is_queued(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner);
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id])->get(route('account.companies.delete', $company->id))->assertOk()->assertSee('Permanently delete client data');
        $this->deleteJson(route('account.companies.destroy', $company->id), ['confirmation_name' => 'wrong', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors(['confirmation_name', 'password']);
        Bus::assertNotDispatched(DeleteClientData::class);
        $this->deleteJson(route('account.companies.destroy', $company->id), ['confirmation_name' => $company->name, 'password' => 'password'])->assertStatus(202)->assertSessionMissing('active_company_id');
        $this->assertSame('deleting', $company->fresh()->provisioning_status);
        Bus::assertDispatched(DeleteClientData::class, fn (DeleteClientData $job): bool => $job->companyId === $company->id && $job->ownerId === $owner->id && $job->afterCommit);
        $this->postJson(route('account.companies.provision', $company->id))->assertConflict();
        $this->getJson(route('sheets.database.show', ['company_id' => $company->id]))->assertStatus(503);
        $this->get(route('account.dashboard'))->assertOk()->assertSee('Deleting client');
    }

    public function test_accountants_managers_and_unrelated_system_owners_cannot_delete_a_client(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner, false);
        foreach (['accountant', 'manager', 'staff'] as $role) {
            $user = User::factory()->accountant()->create(['must_change_password' => false]);
            $company->users()->attach($user, ['role' => $role]);
            $this->actingAs($user)->deleteJson(route('account.companies.destroy', $company->id), ['confirmation_name' => $company->name, 'password' => 'password'])->assertForbidden();
        }
        $foreign = User::factory()->firm()->systemOwner()->create();
        $this->actingAs($foreign)->getJson(route('account.companies.delete', $company->id))->assertNotFound();
        Bus::assertNotDispatched(DeleteClientData::class);
        $this->assertSame('pending', $company->fresh()->provisioning_status);
    }

    public function test_deletion_removes_only_the_chosen_database_folder_and_central_records(): void
    {
        $owner = User::factory()->firm()->create();
        $a = $this->client($owner);
        $b = $this->client($owner);
        $aPath = database_path($a->tenancy_db_name);
        $bPath = database_path($b->tenancy_db_name);
        foreach ([$a, $b] as $company) {
            $path = $company->storageDirectory().'/documents/invoice.pdf';
            Storage::disk('client_files')->put($path, 'Client invoice');
            InboxDocument::factory()->create(['company_id' => $company->id, 'uploaded_by' => $owner->id, 'path' => $path]);
        }
        $deletion = app(ClientDeletion::class);
        $deletion->queue($owner, $a->id, $a->name);
        $deletion->purge($a->id, $owner->id);
        $deletion->purge($a->id, $owner->id);
        app(ClientProvisioner::class)->provision($a->id);
        $this->assertDatabaseMissing('companies', ['id' => $a->id]);
        $this->assertDatabaseMissing('company_user', ['company_id' => $a->id]);
        $this->assertDatabaseMissing('inbox_documents', ['company_id' => $a->id]);
        $this->assertFileDoesNotExist($aPath);
        Storage::disk('client_files')->assertMissing($a->storageDirectory());
        $this->assertFileExists($bPath);
        Storage::disk('client_files')->assertExists($b->storageDirectory().'/documents/invoice.pdf');
        $this->assertDatabaseHas('inbox_documents', ['company_id' => $b->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertTrue($b->fresh()->databaseReady());
        $b->run(fn () => $this->assertSame($b->id, DB::table('client_database_identity')->value('company_id')));
    }

    public function test_foreign_folder_identity_blocks_all_destructive_actions_and_can_be_retried(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner);
        $disk = Storage::disk('client_files');
        $manifest = $disk->get($company->storageDirectory().'/identity.json');
        $disk->put($company->storageDirectory().'/identity.json', json_encode(['company_id' => 999, 'workspace_key' => $company->workspaceKey()]));
        $deletion = app(ClientDeletion::class);
        $deletion->queue($owner, $company->id, $company->name);
        try {
            $deletion->purge($company->id, $owner->id);
            $this->fail('A foreign folder must never be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belongs to another client', $exception->getMessage());
        }
        $this->assertSame('delete_failed', $company->fresh()->provisioning_status);
        $this->assertFileExists(database_path($company->tenancy_db_name));
        app(ClientProvisioner::class)->provision($company->id);
        $this->assertSame('delete_failed', $company->fresh()->provisioning_status);
        $disk->put($company->storageDirectory().'/identity.json', $manifest);
        $deletion->queue($owner, $company->id, $company->name);
        $deletion->purge($company->id, $owner->id);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_database_identity_mismatch_and_invalid_database_name_are_rejected(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner);
        $company->run(fn () => DB::table('client_database_identity')->where('id', 1)->update(['company_id' => 999]));
        $deletion = app(ClientDeletion::class);
        $deletion->queue($owner, $company->id, $company->name);
        try {
            $deletion->purge($company->id, $owner->id);
            $this->fail('A foreign database must not be deleted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('belongs to another client', $exception->getMessage());
        }
        $this->assertFileExists(database_path($company->tenancy_db_name));
        Storage::disk('client_files')->assertExists($company->storageDirectory().'/identity.json');
        $company->refresh()->forceFill(['tenancy_db_name' => 'central'])->save();
        $this->expectException(RuntimeException::class);
        $deletion->queue($owner, $company->id, $company->name);
    }

    public function test_deletion_retries_after_the_database_was_removed_but_folder_cleanup_failed(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner);
        $disk = Storage::disk('client_files');
        $failingDisk = Mockery::mock($disk);
        $failingDisk->shouldReceive('deleteDirectory')->once()->with($company->storageDirectory())->andReturn(false);
        Storage::set('client_files', $failingDisk);
        $deletion = app(ClientDeletion::class);
        $deletion->queue($owner, $company->id, $company->name);
        try {
            $deletion->purge($company->id, $owner->id);
            $this->fail('Folder cleanup must report a retryable failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('folder could not be deleted', $exception->getMessage());
        } finally {
            Storage::set('client_files', $disk);
        }
        $this->assertFileDoesNotExist(database_path($company->tenancy_db_name));
        $this->assertSame('delete_failed', $company->fresh()->provisioning_status);
        $disk->assertExists($company->storageDirectory().'/identity.json');
        $deletion->purge($company->id, $owner->id);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $disk->assertMissing($company->storageDirectory());
    }

    public function test_mysql_deletion_drops_only_the_owned_database_and_revokes_its_exact_runtime_grant(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner, false);
        $name = 'client_'.substr($company->workspaceKey(), 0, 40);
        config([
            'database.connections.client_template.driver' => 'mysql',
            'database.connections.client_template.username' => 'client_runtime',
            'database.connections.client_provisioner' => ['driver' => 'delete-mysql-test', 'username' => 'client_provisioner'],
            'tenancy.runtime_user_host' => 'localhost',
        ]);
        $pdo = DB::connection('sqlite')->getPdo();
        $statements = [];
        DB::extend('delete-mysql-test', function () use ($pdo, $company, $name, &$statements): Connection {
            $connection = $this->getMockBuilder(Connection::class)->setConstructorArgs([$pdo, '', '', ['driver' => 'delete-mysql-test']])->onlyMethods(['selectOne', 'select', 'statement'])->getMock();
            $connection->method('selectOne')->with('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name])->willReturn((object) ['SCHEMA_NAME' => $name]);
            $connection->method('select')->willReturnCallback(function (string $sql, array $bindings = []) use ($company, $name): array {
                if (str_contains($sql, 'information_schema.TABLES')) {
                    $this->assertSame([$name], $bindings);

                    return [(object) ['name' => 'client_database_identity']];
                }
                $this->assertStringContainsString($name.'.', str_replace('"', '', $sql));

                return [(object) ['id' => 1, 'company_id' => $company->id, 'workspace_key' => $company->workspaceKey()]];
            });
            $connection->method('statement')->willReturnCallback(function (string $sql) use (&$statements): bool {
                $statements[] = $sql;

                return true;
            });

            return $connection;
        });
        $deletion = app(ClientDeletion::class);
        $deletion->queue($owner, $company->id, $company->name);
        $deletion->purge($company->id, $owner->id);
        $this->assertSame([
            'DROP DATABASE IF EXISTS `'.$name.'`',
            'REVOKE ALL PRIVILEGES ON `client\_'.substr($company->workspaceKey(), 0, 40)."`.* FROM 'client_runtime'@'localhost'",
        ], $statements);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_failed_unprovisioned_client_can_be_removed_and_queued_setup_cannot_recreate_it(): void
    {
        $owner = User::factory()->firm()->create();
        $company = $this->client($owner, false);
        $deletion = app(ClientDeletion::class);
        $deletion->queue($owner, $company->id, $company->name);
        app(ClientProvisioner::class)->provision($company->id);
        $this->assertSame('deleting', $company->fresh()->provisioning_status);
        $deletion->purge($company->id, $owner->id);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        app(ClientProvisioner::class)->provision($company->id);
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }
}
