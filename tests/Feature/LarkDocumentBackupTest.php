<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\User;
use App\Services\LarkDocumentBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LarkDocumentBackupTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sampleSecretForTestsOnly';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Storage::fake('documents');
        Http::preventStrayRequests();
    }

    public function test_only_active_company_owner_can_configure_or_send_backups(): void
    {
        [$owner, $company] = $this->book();
        $url = route('documents.backup.show', $company->id);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Lark document backup');
        foreach (['manager', 'accountant', 'staff'] as $role) {
            $member = User::factory()->accountant()->create(['must_change_password' => false]);
            $company->users()->attach($member, ['role' => $role]);
            $this->actingAs($member)->get($url)->assertForbidden();
            $this->putJson(route('documents.backup.update', $company->id), $this->payload())->assertForbidden();
            $this->postJson(route('documents.backup.queue', $company->id))->assertForbidden();
        }
        [$foreign] = $this->book();
        $this->actingAs($foreign)->get($url)->assertNotFound();
        $company->users()->updateExistingPivot($owner->id, ['is_suspended' => true]);
        $this->actingAs($owner)->get($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_never_rendered_or_flashed_and_get_does_not_connect(): void
    {
        [$owner, $company] = $this->book();
        $this->actingAs($owner)->put(route('documents.backup.update', $company->id), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $stored = DB::table('company_document_backups')->first();
        $this->assertNotSame(self::SECRET, $stored->app_secret);
        $this->assertSame(self::SECRET, Crypt::decryptString($stored->app_secret));
        $this->get(route('documents.backup.show', $company->id))->assertOk()->assertDontSee(self::SECRET)->assertSee('saved securely');
        $this->put(route('documents.backup.update', $company->id), $this->payload(['version' => 1, 'folder_token' => 'invalid/url']))->assertSessionHasErrors('folder_token')->assertSessionMissing('_old_input.app_secret');
        Http::assertNothingSent();
    }

    public function test_enable_requires_saved_verified_connection_and_authorization(): void
    {
        [$owner, $company] = $this->book();
        $this->actingAs($owner)->putJson(route('documents.backup.update', $company->id), $this->payload(['enabled' => true, 'consent' => 1]))->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $this->saveAndVerify($owner, $company);
        $this->putJson(route('documents.backup.update', $company->id), $this->payload(['version' => 2, 'app_secret' => null, 'enabled' => true]))->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->enable($company);
        $this->assertDatabaseHas('company_document_backups', ['company_id' => $company->id, 'enabled' => true]);
        Http::assertSentCount(2);
    }

    public function test_auto_queue_upload_backup_uses_only_its_folder_and_preserves_original(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $this->post(route('documents.store', $company->id), ['category' => 'purchases', 'file' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 sample')])->assertRedirect();
        $doc = InboxDocument::firstOrFail();
        $this->assertSame('pending', $doc->lark_status);
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertSame('backed_up', $doc->fresh()->lark_status);
        $this->assertSame('fileToken123', $doc->fresh()->lark_file_token);
        $this->assertDatabaseHas('document_backup_events', ['company_id' => $company->id, 'inbox_document_id' => $doc->id, 'action' => 'backup_confirmed', 'actor_id' => null]);
        Storage::disk('documents')->assertExists($doc->path);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/upload_all') && str_contains($request->body(), 'folderSample123') && str_contains($request->body(), 'explorer') && str_contains($request->body(), $doc->sha256) && str_contains($request->body(), '%PDF-1.4 sample'));
        $this->assertArrayNotHasKey('lark_file_token', $doc->fresh()->toArray());
        $this->get(route('documents.show', ['companyId' => $company->id, 'documentId' => $doc->id]))->assertOk()->assertSee('Copied to Lark');
        $before = count(Http::recorded());
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertCount($before, Http::recorded());
    }

    public function test_disabled_connections_do_not_queue_new_uploads_and_queue_is_company_scoped(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->post(route('documents.store', $company->id), ['category' => 'sales', 'file' => UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 retained')])->assertRedirect();
        $doc = InboxDocument::firstOrFail();
        $this->assertNull($doc->lark_status);
        $this->postJson(route('documents.backup.queue', $company->id))->assertUnprocessable();
        $other = InboxDocument::factory()->create();
        $this->enable($company);
        $this->post(route('documents.backup.queue', $company->id))->assertRedirect();
        $this->assertSame('pending', $doc->fresh()->lark_status);
        $this->assertNull($other->fresh()->lark_status);
    }

    public function test_expired_upload_lease_reconciles_previous_copy_without_uploading_again(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $doc = $this->document($company, $owner, ['lark_status' => 'uploading', 'lark_attempts' => 1, 'lark_started_at' => now()->subMinutes(11)]);
        $name = 'book-'.$company->id.'-doc-'.$doc->id.'-'.$doc->sha256.'.pdf';
        $this->fakeLark(['files' => [['type' => 'file', 'name' => $name, 'token' => 'existingFile123']], 'has_more' => false]);
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertSame('existingFile123', $doc->fresh()->lark_file_token);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/upload_all'));
    }

    public function test_live_upload_lease_blocks_second_worker_and_pause_blocks_transmission(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $doc = $this->document($company, $owner, ['lark_status' => 'uploading', 'lark_attempts' => 1, 'lark_started_at' => now()]);
        $before = count(Http::recorded());
        $this->assertFalse(app(LarkDocumentBackupService::class)->backUp($doc->id));
        DB::table('company_document_backups')->where('company_id', $company->id)->update(['enabled' => false]);
        $doc->forceFill(['lark_status' => 'pending'])->save();
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertCount($before, Http::recorded());
    }

    public function test_failures_preserve_local_file_apply_retry_delay_and_hide_provider_details(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $doc = $this->document($company, $owner);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 999, 'msg' => self::SECRET], 429)]);
        $this->artisan('documents:backup-lark')->assertFailed();
        $doc->refresh();
        $this->assertSame('failed', $doc->lark_status);
        $this->assertTrue($doc->lark_next_attempt_at->isFuture());
        $this->assertStringNotContainsString(self::SECRET, $doc->lark_error);
        Storage::disk('documents')->assertExists($doc->path);
        $sent = count(Http::recorded());
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());
        $doc->forceFill(['lark_attempts' => 5, 'lark_next_attempt_at' => now()->subDay()])->save();
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_duplicate_folder_stale_settings_and_destination_changes_are_rejected(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->putJson(route('documents.backup.update', $company->id), $this->payload(['version' => 0]))->assertConflict();
        $this->enable($company);
        $this->document($company, $owner);
        $this->putJson(route('documents.backup.update', $company->id), $this->payload(['version' => 3, 'folder_token' => 'differentFolder123']))->assertUnprocessable()->assertJsonValidationErrors('folder_token');
        [$other, $otherCompany] = $this->book();
        $this->actingAs($other)->putJson(route('documents.backup.update', $otherCompany->id), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('folder_token');
        $this->assertDatabaseCount('company_document_backups', 1);
    }

    public function test_secret_rotation_requires_reverification_and_bad_test_does_not_enable(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->put(route('documents.backup.update', $company->id), $this->payload(['version' => 2, 'app_secret' => 'RotatedSecret123']))->assertRedirect();
        $this->assertDatabaseHas('company_document_backups', ['company_id' => $company->id, 'verified_at' => null, 'enabled' => false]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 1, 'msg' => self::SECRET])]);
        $this->postJson(route('documents.backup.verify', $company->id), ['version' => 3])->assertUnprocessable()->assertDontSee(self::SECRET);
        $this->assertDatabaseHas('company_document_backups', ['enabled' => false]);
    }

    public function test_modified_local_file_is_not_sent_and_invalid_worker_limit_is_rejected(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $doc = $this->document($company, $owner);
        Storage::disk('documents')->put($doc->path, 'modified');
        $before = count(Http::recorded());
        $this->artisan('documents:backup-lark')->assertFailed();
        $this->assertCount($before, Http::recorded());
        $this->assertSame('failed', $doc->fresh()->lark_status);
        $this->artisan('documents:backup-lark --limit=0')->assertExitCode(2);
    }

    public function test_expired_fifth_attempt_is_marked_failed_and_can_be_explicitly_requeued(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $doc = $this->document($company, $owner, ['lark_status' => 'uploading', 'lark_attempts' => 5, 'lark_started_at' => now()->subMinutes(11)]);
        $before = count(Http::recorded());
        $this->artisan('documents:backup-lark')->assertFailed();
        $this->assertSame('failed', $doc->fresh()->lark_status);
        $this->assertCount($before, Http::recorded());
        $this->post(route('documents.backup.queue', $company->id))->assertRedirect();
        $this->assertSame(0, $doc->fresh()->lark_attempts);
        $this->assertSame('pending', $doc->fresh()->lark_status);
    }

    public function test_reconciliation_checks_all_pages_and_does_not_upload_on_incomplete_listing(): void
    {
        [$owner, $company] = $this->book();
        $this->saveAndVerify($owner, $company);
        $this->enable($company);
        $doc = $this->document($company, $owner);
        $name = 'book-'.$company->id.'-doc-'.$doc->id.'-'.$doc->sha256.'.pdf';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/auth/v3/tenant_access_token/internal' => Http::response(['code' => 0, 'tenant_access_token' => 'test-token']),
            '*/drive/v1/files?*' => Http::sequence()
                ->push(['code' => 0, 'data' => ['files' => [], 'has_more' => true, 'next_page_token' => 'next123']])
                ->push(['code' => 0, 'data' => ['files' => [['name' => $name, 'token' => 'foundOnPageTwo123', 'type' => 'file']], 'has_more' => false]]),
        ]);
        $this->artisan('documents:backup-lark')->assertSuccessful();
        $this->assertSame('foundOnPageTwo123', $doc->fresh()->lark_file_token);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page_token=next123'));
        $other = $this->document($company, $owner);
        $this->fakeLark(['files' => [], 'has_more' => true]);
        $this->artisan('documents:backup-lark')->assertFailed();
        $this->assertSame('failed', $other->fresh()->lark_status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/upload_all'));
    }

    /** @return array{User, Company} */
    private function book(): array
    {
        $owner = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);

        return [$owner, $company];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [...['version' => 0, 'app_id' => 'cli_sampleApp123', 'app_secret' => self::SECRET, 'folder_token' => 'folderSample123', 'enabled' => false], ...$overrides];
    }

    private function saveAndVerify(User $owner, Company $company): void
    {
        $this->fakeLark();
        $this->actingAs($owner)->put(route('documents.backup.update', $company->id), $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('documents.backup.verify', $company->id), ['version' => 1])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function enable(Company $company): void
    {
        $this->put(route('documents.backup.update', $company->id), $this->payload(['version' => 2, 'app_secret' => null, 'enabled' => true, 'consent' => 1]))->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $listing */
    private function fakeLark(array $listing = ['files' => [], 'has_more' => false]): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/auth/v3/tenant_access_token/internal' => Http::response(['code' => 0, 'tenant_access_token' => 'test-access-token']),
            '*/drive/v1/files?*' => Http::response(['code' => 0, 'data' => $listing]),
            '*/drive/v1/files/upload_all' => Http::response(['code' => 0, 'data' => ['file_token' => 'fileToken123']]),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function document(Company $company, User $owner, array $overrides = []): InboxDocument
    {
        $fileId = fake()->uuid();
        $bytes = '%PDF-1.4 backup example '.$fileId;
        $document = InboxDocument::factory()->create([...['company_id' => $company->id, 'uploaded_by' => $owner->id, 'path' => $company->id.'/'.$fileId.'.pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'lark_status' => 'pending'], ...$overrides]);
        Storage::disk('documents')->put($document->path, $bytes);

        return $document;
    }
}
