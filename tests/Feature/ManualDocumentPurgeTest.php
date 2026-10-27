<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\User;
use App\Services\DocumentPurgeService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ManualDocumentPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES = '%PDF-1.4 safely retained source';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Storage::fake('documents');
        Http::preventStrayRequests();
    }

    public function test_only_active_owner_can_select_verify_and_approve_own_company_documents(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $url = route('documents.purge.index', $company->id);
        $this->get($url)->assertRedirect(route('login'));
        foreach (['manager', 'accountant', 'staff'] as $role) {
            $user = User::factory()->accountant()->create(['must_change_password' => false]);
            $company->users()->attach($user, ['role' => $role]);
            $this->actingAs($user)->get($url)->assertForbidden();
            $this->postJson(route('documents.purge.prepare', $company->id), ['document_ids' => [$document->id]])->assertForbidden();
        }
        [$other] = $this->fixture();
        $this->actingAs($other)->get($url)->assertNotFound();
        $company->users()->updateExistingPivot($owner->id, ['is_suspended' => true]);
        $this->actingAs($owner)->get($url)->assertNotFound();
        Http::assertNothingSent();
        Storage::disk('documents')->assertExists($document->path);
    }

    public function test_preparation_verifies_both_files_and_shows_exact_batch_without_deleting(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertNull($document->fresh()->local_purged_at);
        $this->assertNotNull($document->fresh()->lark_verified_at);
        $this->get(route('documents.purge.show', ['companyId' => $company->id, 'batchId' => $batch]))->assertOk()->assertSee($document->original_name)->assertSee('PURGE LOCAL COPIES')->assertDontSee($document->lark_file_token)->assertDontSee($document->sha256);
        $this->assertDatabaseHas('document_backup_events', ['company_id' => $company->id, 'actor_id' => $owner->id, 'action' => 'purge_batch_prepared']);
        $this->assertDatabaseMissing('document_backup_events', ['action' => 'local_purge_approved']);
    }

    public function test_wrong_remote_file_never_creates_an_approval_or_deletes_local_file(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark('wrong content');
        $this->actingAs($owner)->postJson(route('documents.purge.prepare', $company->id), ['document_ids' => [$document->id]])->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->assertDatabaseCount('document_purge_batches', 0);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertNull($document->fresh()->local_purged_at);
    }

    public function test_uncopied_recent_and_foreign_documents_are_rejected_before_network_access(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->actingAs($owner);
        $document->forceFill(['lark_backed_up_at' => now()->subDays(2)])->save();
        $this->postJson(route('documents.purge.prepare', $company->id), ['document_ids' => [$document->id]])->assertUnprocessable();
        $document->forceFill(['lark_status' => 'pending', 'lark_backed_up_at' => now()->subDays(8)])->save();
        $this->postJson(route('documents.purge.prepare', $company->id), ['document_ids' => [$document->id]])->assertUnprocessable();
        [, , $foreign] = $this->fixture();
        $this->postJson(route('documents.purge.prepare', $company->id), ['document_ids' => [$foreign->id]])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_confirmation_is_explicit_and_expired_initial_review_cannot_delete(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $url = $this->confirmUrl($company, $batch);
        $this->postJson($url, ['confirmation' => 'YES', 'acknowledge' => 1])->assertUnprocessable();
        $this->postJson($url, ['confirmation' => 'PURGE LOCAL COPIES'])->assertUnprocessable();
        DB::table('document_purge_batches')->where('id', $batch)->update(['expires_at' => now()->subMinute()]);
        $this->postJson($url, $this->approval())->assertConflict();
        Storage::disk('documents')->assertExists($document->path);
        $this->assertDatabaseMissing('document_backup_events', ['action' => 'local_purge_approved']);
    }

    public function test_approved_purge_removes_only_selected_local_copy_and_lark_preview_keeps_working(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $other = InboxDocument::factory()->create(['company_id' => $company->id, 'uploaded_by' => $owner->id]);
        Storage::disk('documents')->put($other->path, 'unselected copy');
        $document->forceFill(['linked_sheet' => 'purchases', 'linked_record_id' => 'existing-entry-123'])->save();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $this->post($this->confirmUrl($company, $batch), [...$this->approval(), 'document_ids' => [$other->id]])->assertRedirect();
        Storage::disk('documents')->assertMissing($document->path);
        Storage::disk('documents')->assertExists($other->path);
        $document->refresh();
        $this->assertNotNull($document->local_purged_at);
        $this->assertSame($owner->id, $document->local_purged_by);
        $this->assertSame('existing-entry-123', $document->linked_record_id);
        $this->assertSame('backed_up', $document->lark_status);
        $this->assertDatabaseHas('document_backup_events', ['action' => 'local_copy_purged', 'actor_id' => $owner->id, 'inbox_document_id' => $document->id]);
        $this->assertDatabaseHas('document_purge_batches', ['id' => $batch, 'status' => 'completed']);
        $file = route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]);
        $this->get($file)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'application/pdf')->assertStreamedContent(self::BYTES);
        $this->get($file.'?download=1')->assertDownload($document->original_name)->assertStreamedContent(self::BYTES);
        Storage::disk('documents')->assertMissing($document->path);
        $before = count(Http::recorded());
        $events = DB::table('document_backup_events')->count();
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        $this->assertCount($before, Http::recorded());
        $this->assertSame($events, DB::table('document_backup_events')->count());
    }

    public function test_remote_change_after_review_blocks_removal_and_owner_can_explicitly_retry(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $this->fakeLark('remote file changed');
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        Storage::disk('documents')->assertExists($document->path);
        $this->assertNull($document->fresh()->local_purged_at);
        $this->assertDatabaseHas('document_purge_batches', ['id' => $batch, 'status' => 'partial']);
        $this->fakeLark();
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        Storage::disk('documents')->assertMissing($document->path);
        $this->assertNotNull($document->fresh()->local_purged_at);
    }

    public function test_failed_filesystem_delete_is_audited_without_marking_purged(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $disk = Storage::disk('documents');
        $wrapped = Mockery::mock($disk);
        $wrapped->shouldReceive('delete')->with($document->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('documents')->andReturn($wrapped);
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        $this->assertTrue($disk->exists($document->path));
        $this->assertNull($document->fresh()->local_purged_at);
        $this->assertDatabaseHas('document_backup_events', ['action' => 'local_purge_failed', 'inbox_document_id' => $document->id]);
    }

    public function test_database_failure_after_file_removal_restores_local_bytes_and_keeps_approval_history(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $failOnce = true;
        DB::listen(function (QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'local_purged_at')) {
                $failOnce = false;
                throw new \RuntimeException('Simulated database write failure');
            }
        });
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        $this->assertFalse($failOnce);
        $this->assertSame(self::BYTES, Storage::disk('documents')->get($document->path));
        $this->assertNull($document->fresh()->local_purged_at);
        $this->assertDatabaseHas('document_backup_events', ['action' => 'local_purge_approved', 'actor_id' => $owner->id]);
        $this->assertDatabaseMissing('document_backup_events', ['action' => 'local_copy_purged']);
    }

    public function test_interrupted_approval_can_be_manually_reconciled_without_local_file(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $document->forceFill(['local_purge_batch_id' => $batch])->save();
        DB::table('document_purge_batches')->where('id', $batch)->update(['status' => 'processing', 'approved_at' => now()]);
        $this->postJson($this->confirmUrl($company, $batch), $this->approval())->assertConflict();
        Storage::disk('documents')->assertExists($document->path);
        Storage::disk('documents')->delete($document->path);
        DB::table('document_purge_batches')->where('id', $batch)->update(['approved_at' => now()->subMinutes(11)]);
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        $this->assertNotNull($document->fresh()->local_purged_at);
        $this->assertDatabaseHas('document_purge_batches', ['id' => $batch, 'status' => 'completed']);
    }

    public function test_foreign_batch_and_suspended_owner_cannot_approve_or_fetch_remote_documents(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        [$other, $otherCompany] = $this->fixture();
        $this->actingAs($other)->postJson($this->confirmUrl($company, $batch), $this->approval())->assertNotFound();
        $this->postJson($this->confirmUrl($otherCompany, $batch), $this->approval())->assertNotFound();
        Storage::disk('documents')->delete($document->path);
        $count = count(Http::recorded());
        $this->get(route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]))->assertNotFound();
        $company->users()->updateExistingPivot($owner->id, ['is_suspended' => true]);
        $this->actingAs($owner)->postJson($this->confirmUrl($company, $batch), $this->approval())->assertNotFound();
        $this->get(route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]))->assertNotFound();
        $this->assertCount($count, Http::recorded());
    }

    public function test_unavailable_or_mismatched_remote_file_is_not_served_after_purge(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        $this->fakeLark('wrong bytes');
        $this->get(route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]))->assertStatus(503)->assertDontSee('wrong bytes');
        $this->fakeLark(self::BYTES, 403);
        $this->get(route('documents.file', ['companyId' => $company->id, 'documentId' => $document->id]))->assertStatus(503);
        Storage::disk('documents')->assertMissing($document->path);
    }

    public function test_partial_batch_keeps_failed_file_and_retry_skips_previously_removed_file(): void
    {
        [$owner, $company, $first] = $this->fixture();
        $secondBytes = self::BYTES.' second invoice';
        $second = InboxDocument::factory()->create(['company_id' => $company->id, 'uploaded_by' => $owner->id, 'path' => $company->id.'/'.Str::uuid().'.pdf', 'size' => strlen($secondBytes), 'sha256' => hash('sha256', $secondBytes), 'lark_status' => 'backed_up', 'lark_file_token' => 'secondFile123', 'lark_backed_up_at' => now()->subDays(8)]);
        Storage::disk('documents')->put($second->path, $secondBytes);
        $fakePair = function (string $secondResponse): void {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([
                '*/auth/v3/tenant_access_token/internal' => Http::response(['code' => 0, 'tenant_access_token' => 'test-token']),
                '*/drive/v1/files/fileCopy123/download' => Http::response(self::BYTES),
                '*/drive/v1/files/secondFile123/download' => Http::response($secondResponse),
            ]);
        };
        $fakePair($secondBytes);
        $this->actingAs($owner)->post(route('documents.purge.prepare', $company->id), ['document_ids' => [$first->id, $second->id]])->assertRedirect()->assertSessionHasNoErrors();
        $batch = DB::table('document_purge_batches')->value('id');
        $fakePair('changed remote file');
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        Storage::disk('documents')->assertMissing($first->path);
        Storage::disk('documents')->assertExists($second->path);
        $this->assertDatabaseHas('document_purge_batches', ['id' => $batch, 'status' => 'partial']);
        $fakePair($secondBytes);
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        Storage::disk('documents')->assertMissing($second->path);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'fileCopy123/download'));
        $this->assertSame(2, DB::table('document_backup_events')->where('action', 'local_copy_purged')->count());
    }

    public function test_local_changes_after_review_block_purge_and_keep_current_local_bytes(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $batch = $this->prepare($owner, $company, $document);
        Storage::disk('documents')->put($document->path, 'different local data');
        $this->post($this->confirmUrl($company, $batch), $this->approval())->assertRedirect();
        $this->assertSame('different local data', Storage::disk('documents')->get($document->path));
        $this->assertNull($document->fresh()->local_purged_at);
        $this->assertDatabaseHas('document_purge_batches', ['id' => $batch, 'status' => 'partial']);
    }

    public function test_competing_review_batch_cannot_remove_a_document_twice(): void
    {
        [$owner, $company, $document] = $this->fixture();
        $this->fakeLark();
        $first = $this->prepare($owner, $company, $document);
        $second = app(DocumentPurgeService::class)->prepare($owner, $company->id, [$document->id]);
        $this->post($this->confirmUrl($company, $first), $this->approval())->assertRedirect();
        $before = count(Http::recorded());
        $this->postJson($this->confirmUrl($company, $second), $this->approval())->assertUnprocessable();
        $this->assertCount($before, Http::recorded());
        $this->assertSame(1, DB::table('document_backup_events')->where('action', 'local_copy_purged')->count());
    }

    /** @return array{User, Company, InboxDocument} */
    private function fixture(): array
    {
        $owner = User::factory()->firm()->create();
        $company = Company::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        DB::table('company_document_backups')->insert(['company_id' => $company->id, 'app_id' => 'cli_testPurge123', 'app_secret' => Crypt::encryptString('privateTestSecret'), 'folder_token' => 'folder'.Str::random(20), 'enabled' => false, 'version' => 1, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $document = InboxDocument::factory()->create(['company_id' => $company->id, 'uploaded_by' => $owner->id, 'path' => $company->id.'/'.Str::uuid().'.pdf', 'size' => strlen(self::BYTES), 'sha256' => hash('sha256', self::BYTES), 'lark_status' => 'backed_up', 'lark_file_token' => 'fileCopy123', 'lark_backed_up_at' => now()->subDays(8)]);
        Storage::disk('documents')->put($document->path, self::BYTES);

        return [$owner, $company, $document];
    }

    private function fakeLark(string $bytes = self::BYTES, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/auth/v3/tenant_access_token/internal' => Http::response(['code' => 0, 'tenant_access_token' => 'private-token']),
            '*/drive/v1/files/fileCopy123/download' => Http::response($bytes, $status, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function prepare(User $owner, Company $company, InboxDocument $document): string
    {
        $this->actingAs($owner)->post(route('documents.purge.prepare', $company->id), ['document_ids' => [$document->id]])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('document_purge_batches')->where('company_id', $company->id)->latest('created_at')->value('id');
    }

    /** @return array{confirmation: string, acknowledge: int} */
    private function approval(): array
    {
        return ['confirmation' => 'PURGE LOCAL COPIES', 'acknowledge' => 1];
    }

    private function confirmUrl(Company $company, string $batch): string
    {
        return route('documents.purge.confirm', ['companyId' => $company->id, 'batchId' => $batch]);
    }
}
