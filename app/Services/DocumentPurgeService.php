<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DocumentPurgeService
{
    public const GRACE_DAYS = 7;

    public function __construct(private LarkDocumentBackupService $lark) {}

    /** @param list<int> $ids */
    public function prepare(User $actor, int $companyId, array $ids): string
    {
        $this->lark->owner($actor, $companyId);
        abort_unless(count($ids) >= 1 && count($ids) <= 5 && count($ids) === count(array_unique($ids)), 422, 'Select one to five different documents.');
        $destination = $this->destination($companyId);
        $documents = InboxDocument::query()->where('company_id', $companyId)->whereIn('id', $ids)->get();
        abort_unless($documents->count() === count($ids), 404);
        foreach ($documents as $document) {
            $this->eligible($document);
        }
        $items = [];
        foreach ($documents as $document) {
            try {
                $this->localBytes($document);
                $this->lark->verifiedDownload($document);
            } catch (Throwable) {
                throw ValidationException::withMessages(['documents' => 'Could not verify both copies of '.$document->original_name.'. Nothing was purged. Check Lark access and retry.']);
            }
            $items[] = $this->snapshot($document);
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $companyId, $items, $destination): string {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->lark->owner($actor, $companyId);
            abort_unless($destination === $this->destination($companyId), 409, 'The Lark destination changed. Prepare a new batch.');
            foreach ($items as $item) {
                $document = InboxDocument::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($item['id']);
                $this->eligible($document);
                abort_unless($item === $this->snapshot($document), 409, 'A document changed. Prepare a new batch.');
                $document->forceFill(['lark_verified_at' => now()])->save();
            }
            $id = (string) Str::uuid();
            DB::connection(config('tenancy.database.central_connection'))->table('document_purge_batches')->insert(['id' => $id, 'company_id' => $companyId, 'created_by' => $actor->id, 'status' => 'ready', 'items' => json_encode($items, JSON_THROW_ON_ERROR), 'destination' => json_encode($destination, JSON_THROW_ON_ERROR), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($actor, $companyId, 'purge_batch_prepared', ['batch_id' => $id, 'document_ids' => array_column($items, 'id')]);

            return $id;
        });
    }

    public function batch(User $actor, int $companyId, string $batchId): object
    {
        $this->lark->owner($actor, $companyId);
        $batch = DB::connection(config('tenancy.database.central_connection'))->table('document_purge_batches')->where('id', $batchId)->where('company_id', $companyId)->where('created_by', $actor->id)->first();
        abort_unless($batch, 404);
        $batch->items = json_decode($batch->items, true, flags: JSON_THROW_ON_ERROR);
        $batch->destination = json_decode($batch->destination, true, flags: JSON_THROW_ON_ERROR);
        $batch->outcomes = json_decode($batch->outcomes ?? '[]', true, flags: JSON_THROW_ON_ERROR);

        return $batch;
    }

    public function confirm(User $actor, int $companyId, string $batchId, string $confirmation, bool $acknowledged): void
    {
        $this->lark->owner($actor, $companyId);
        if ($confirmation !== 'PURGE LOCAL COPIES' || ! $acknowledged) {
            throw ValidationException::withMessages(['confirmation' => 'Type PURGE LOCAL COPIES and confirm that you reviewed this batch.']);
        }
        $batch = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $companyId, $batchId): object {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            DB::connection(config('tenancy.database.central_connection'))->table('document_purge_batches')->where('id', $batchId)->lockForUpdate()->first();
            $batch = $this->batch($actor, $companyId, $batchId);
            if ($batch->status === 'completed') {
                return $batch;
            }
            abort_if($batch->status === 'ready' && CarbonImmutable::parse($batch->expires_at)->isPast(), 409, 'This review expired. Select documents and prepare a new batch.');
            abort_if($batch->status === 'processing' && CarbonImmutable::parse($batch->approved_at)->gt(now()->subMinutes(10)), 409, 'This approved batch is still processing. Wait before retrying.');
            abort_unless($batch->destination === $this->destination($companyId), 409, 'The destination changed. Do not purge these files.');
            foreach ($batch->items as $item) {
                $document = InboxDocument::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($item['id']);
                if ($document->local_purged_at && $document->local_purge_batch_id === $batchId) {
                    continue;
                }
                $this->eligible($document, $batchId);
                abort_unless($item === $this->snapshot($document), 409, 'A selected document changed. Prepare a new batch.');
                $document->forceFill(['local_purge_batch_id' => $batchId])->save();
            }
            DB::connection(config('tenancy.database.central_connection'))->table('document_purge_batches')->where('id', $batchId)->update(['status' => 'processing', 'approved_at' => now(), 'updated_at' => now()]);
            $this->event($actor, $companyId, 'local_purge_approved', ['batch_id' => $batchId, 'document_ids' => array_column($batch->items, 'id'), 'bytes' => array_sum(array_column($batch->items, 'size'))]);

            return $batch;
        });
        if ($batch->status === 'completed') {
            return;
        }
        $outcomes = [];
        foreach ($batch->items as $item) {
            $outcomes[] = $this->purgeOne($actor, $companyId, $batchId, $item);
        }
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($companyId, $batchId, $outcomes, $actor): void {
            $complete = ! in_array(false, array_column($outcomes, 'purged'), true);
            DB::connection(config('tenancy.database.central_connection'))->table('document_purge_batches')->where('id', $batchId)->update(['status' => $complete ? 'completed' : 'partial', 'outcomes' => json_encode($outcomes, JSON_THROW_ON_ERROR), 'completed_at' => $complete ? now() : null, 'updated_at' => now()]);
            $this->event($actor, $companyId, 'purge_batch_finished', ['batch_id' => $batchId, 'purged_count' => count(array_filter($outcomes, fn (array $outcome): bool => $outcome['purged']))]);
        });
    }

    /** @param array<string, mixed> $item
     * @return array{id: int, purged: bool, message: string}
     */
    private function purgeOne(User $actor, int $companyId, string $batchId, array $item): array
    {
        $restoreBytes = null;
        $deleted = false;
        $document = InboxDocument::query()->where('company_id', $companyId)->findOrFail($item['id']);
        if ($document->local_purged_at && $document->local_purge_batch_id === $batchId) {
            return ['id' => $document->id, 'purged' => true, 'message' => 'Local copy previously purged by this approval.'];
        }
        try {
            $this->lark->owner($actor, $companyId);
            $this->eligible($document, $batchId);
            abort_unless($item === $this->snapshot($document), 409);
            $restoreBytes = $this->lark->verifiedDownload($document);
            if (Storage::disk('documents')->exists($document->path)) {
                $this->localBytes($document);
            }
            DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $companyId, $batchId, $item, &$deleted): void {
                Company::query()->lockForUpdate()->findOrFail($companyId);
                $this->lark->owner($actor, $companyId);
                $document = InboxDocument::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($item['id']);
                $this->eligible($document, $batchId);
                abort_unless($document->local_purge_batch_id === $batchId && $item === $this->snapshot($document), 409);
                $batch = $this->batch($actor, $companyId, $batchId);
                abort_unless($batch->status === 'processing' && $batch->destination === $this->destination($companyId), 409);
                $this->validPath($document);
                $disk = Storage::disk('documents');
                if ($disk->exists($document->path)) {
                    $deleted = true;
                    if (! $disk->delete($document->path) || $disk->exists($document->path)) {
                        throw new RuntimeException('Local file removal was not confirmed.');
                    }
                }
                $document->forceFill(['lark_verified_at' => now(), 'local_purged_at' => now(), 'local_purged_by' => $actor->id])->save();
                $this->event($actor, $companyId, 'local_copy_purged', ['batch_id' => $batchId, 'bytes' => $document->size], $document->id);
            });

            return ['id' => $document->id, 'purged' => true, 'message' => 'Local copy purged; verified Lark copy retained.'];
        } catch (Throwable) {
            if ($deleted && $restoreBytes !== null) {
                try {
                    $this->validPath($document);
                    if (! Storage::disk('documents')->exists($document->path)) {
                        Storage::disk('documents')->put($document->path, $restoreBytes);
                    }
                } catch (Throwable) {
                    // The durable approval remains available for manual reconciliation through Lark.
                }
            }
            $this->event($actor, $companyId, 'local_purge_failed', ['batch_id' => $batchId], $document->id);

            return ['id' => $document->id, 'purged' => false, 'message' => 'Local purge not confirmed. Check Lark and local storage, then review and retry this approval.'];
        }
    }

    private function eligible(InboxDocument $document, ?string $batchId = null): void
    {
        if ($document->local_purged_at || ($document->local_purge_batch_id && $document->local_purge_batch_id !== $batchId) || $document->lark_status !== 'backed_up' || ! $document->lark_file_token || ! $document->lark_backed_up_at || $document->lark_backed_up_at->gt(now()->subDays(self::GRACE_DAYS))) {
            throw ValidationException::withMessages(['documents' => 'Select local documents copied to Lark at least '.self::GRACE_DAYS.' days ago, without another active purge approval.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(InboxDocument $document): array
    {
        return $document->only(['id', 'original_name', 'path', 'size', 'sha256', 'lark_file_token']);
    }

    /** @return array{app_id: string, folder_token: string} */
    private function destination(int $companyId): array
    {
        $row = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $companyId)->first();
        abort_unless($row, 422, 'Configure Lark before preparing a purge.');

        return ['app_id' => $row->app_id, 'folder_token' => $row->folder_token];
    }

    private function localBytes(InboxDocument $document): string
    {
        $this->validPath($document);
        $bytes = Storage::disk('documents')->get($document->path);
        if (strlen($bytes) !== $document->size || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('The local original did not match.');
        }

        return $bytes;
    }

    private function validPath(InboxDocument $document): void
    {
        if (! preg_match('/^'.preg_quote((string) $document->company_id, '/').'\/[a-zA-Z0-9-]+\.(pdf|jpg|png)$/D', $document->path)) {
            throw new RuntimeException('The private document path is invalid.');
        }
    }

    /** @param array<string, mixed> $details */
    private function event(User $actor, int $companyId, string $action, array $details, ?int $documentId = null): void
    {
        DB::connection(config('tenancy.database.central_connection'))->table('document_backup_events')->insert(['company_id' => $companyId, 'actor_id' => $actor->id, 'inbox_document_id' => $documentId, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
