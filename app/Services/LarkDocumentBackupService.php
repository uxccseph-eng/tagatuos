<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class LarkDocumentBackupService
{
    private const API = 'https://open.larksuite.com/open-apis/';

    public function __construct(private CompanyBookService $books) {}

    public function owner(User $actor, int $companyId): Company
    {
        $company = $this->books->findAccessibleBook(User::query()->findOrFail($actor->id), $companyId);
        abort_unless($company->pivot->role === 'owner', 403, 'Only the company book owner can manage document backup destinations.');

        return $company;
    }

    /** @return array<string, mixed> */
    public function configuration(int $companyId): array
    {
        $row = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $companyId)->first();

        return ['app_id' => $row?->app_id, 'folder_token' => $row?->folder_token, 'has_secret' => $row !== null, 'enabled' => (bool) $row?->enabled, 'verified_at' => $row?->verified_at, 'version' => $row?->version ?? 0, 'locked' => InboxDocument::query()->where('company_id', $companyId)->whereNotNull('lark_status')->exists()];
    }

    /** @param array<string, mixed> $data */
    public function saveConfiguration(User $actor, int $companyId, array $data): void
    {
        try {
            DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $companyId, $data): void {
                Company::query()->lockForUpdate()->findOrFail($companyId);
                $this->owner($actor, $companyId);
                $row = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $companyId)->lockForUpdate()->first();
                abort_unless((int) ($row?->version ?? 0) === (int) $data['version'], 409, 'The connection changed. Reload before saving.');
                $secret = $data['app_secret'] ?? null;
                if (! $row && ! $secret) {
                    throw ValidationException::withMessages(['app_secret' => 'Enter your Lark App Secret for the first connection.']);
                }
                $destinationChanged = ! $row || $row->app_id !== $data['app_id'] || $row->folder_token !== $data['folder_token'];
                $locked = InboxDocument::query()->where('company_id', $companyId)->whereNotNull('lark_status')->exists();
                if ($locked && $destinationChanged) {
                    throw ValidationException::withMessages(['folder_token' => 'The destination is locked after backups are queued. Retain this company folder; you may rotate its App Secret.']);
                }
                $credentialsChanged = $destinationChanged || ($secret && ! hash_equals(Crypt::decryptString($row->app_secret), $secret));
                $enabled = (bool) ($data['enabled'] ?? false);
                if ($enabled && ($credentialsChanged || ! $row?->verified_at)) {
                    throw ValidationException::withMessages(['enabled' => 'Save the connection disabled, test folder access, then enable backups.']);
                }
                if ($enabled && ! filter_var($data['consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw ValidationException::withMessages(['consent' => 'Confirm that this company’s documents may be copied to its Lark folder.']);
                }
                $values = ['app_id' => $data['app_id'], 'app_secret' => $secret ? Crypt::encryptString($secret) : $row->app_secret, 'folder_token' => $data['folder_token'], 'enabled' => $enabled, 'verified_at' => $credentialsChanged ? null : $row?->verified_at, 'version' => (int) ($row?->version ?? 0) + 1, 'updated_by' => $actor->id, 'updated_at' => now()];
                if ($row) {
                    DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('id', $row->id)->update($values);
                } else {
                    DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->insert([...$values, 'company_id' => $companyId, 'created_at' => now()]);
                }
                $this->event($companyId, $enabled ? 'backups_enabled' : 'backups_paused', $actor->id, details: ['connection_version' => $values['version']]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['folder_token' => 'This folder is already assigned to another company. Choose a separate company folder.']);
        }
    }

    public function verify(User $actor, int $companyId, int $version): void
    {
        $this->owner($actor, $companyId);
        $row = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $companyId)->first();
        abort_unless($row && (int) $row->version === $version, 409, 'Save the connection, then reload before testing.');
        try {
            $token = $this->accessToken($row);
            $this->data($this->request($token)->get(self::API.'drive/v1/files', ['folder_token' => $row->folder_token, 'page_size' => 1]));
        } catch (Throwable) {
            throw ValidationException::withMessages(['connection' => 'Lark access failed. Check the App ID, App Secret, published app permissions, and folder access. No documents were uploaded.']);
        }
        $this->owner($actor, $companyId);
        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($row, $version, $actor, $companyId): void {
            $updated = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('id', $row->id)->where('version', $version)->update(['verified_at' => now(), 'version' => $version + 1, 'updated_by' => $actor->id, 'updated_at' => now()]);
            abort_unless($updated === 1, 409, 'The connection changed during the test. Reload and test again.');
            $this->event($companyId, 'folder_access_verified', $actor->id);
        });
    }

    public function queueExisting(User $actor, int $companyId): int
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($actor, $companyId): int {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->owner($actor, $companyId);
            $row = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $companyId)->first();
            abort_unless($row?->enabled && $row->verified_at, 422, 'Enable a verified Lark connection first.');

            $count = InboxDocument::query()->where('company_id', $companyId)->where(fn ($query) => $query->whereNull('lark_status')->orWhere('lark_status', 'failed'))->update(['lark_status' => 'pending', 'lark_attempts' => 0, 'lark_next_attempt_at' => now(), 'lark_error' => null]);
            $this->event($companyId, 'documents_queued', $actor->id, details: ['count' => $count]);

            return $count;
        });
    }

    public function backUp(int $documentId): bool
    {
        $document = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($documentId): ?InboxDocument {
            $document = InboxDocument::query()->lockForUpdate()->find($documentId);
            if (! $document || $document->lark_status === null || $document->lark_status === 'backed_up') {
                return null;
            }
            if (($document->lark_status === 'uploading' && $document->lark_started_at?->gt(now()->subMinutes(10))) || $document->lark_next_attempt_at?->isFuture()) {
                return null;
            }
            if ($document->lark_attempts >= 5) {
                if ($document->lark_status === 'uploading') {
                    $document->forceFill(['lark_status' => 'failed', 'lark_error' => 'Backup confirmation expired after five attempts. Use Queue existing / retry failed to reconcile the Lark copy.'])->save();
                    $this->event($document->company_id, 'backup_confirmation_expired', documentId: $document->id);
                }

                return null;
            }
            $connection = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $document->company_id)->first();
            if (! $connection?->enabled || ! $connection->verified_at) {
                return null;
            }
            $document->forceFill(['lark_status' => 'uploading', 'lark_attempt_id' => (string) Str::uuid(), 'lark_started_at' => now(), 'lark_attempts' => $document->lark_attempts + 1])->save();

            return $document;
        });
        if (! $document) {
            return false;
        }
        try {
            $connection = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $document->company_id)->first();
            if (! $connection?->enabled || ! $connection->verified_at) {
                throw new RuntimeException('Backups were paused.');
            }
            $bytes = Storage::disk('documents')->get($document->path);
            if (strlen($bytes) !== $document->size || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('The original file could not be verified.');
            }
            $token = $this->accessToken($connection);
            $name = 'book-'.$document->company_id.'-doc-'.$document->id.'-'.$document->sha256.'.'.pathinfo($document->path, PATHINFO_EXTENSION);
            $fileToken = $this->findExisting($token, $connection->folder_token, $name);
            if (! $fileToken) {
                $data = $this->data($this->request($token)->timeout(40)->attach('file', $bytes, $name)->post(self::API.'drive/v1/files/upload_all', ['file_name' => $name, 'parent_type' => 'explorer', 'parent_node' => $connection->folder_token, 'size' => (string) $document->size]));
                $fileToken = $data['file_token'] ?? null;
                if (! is_string($fileToken) || ! preg_match('/^[a-zA-Z0-9]{1,100}$/D', $fileToken)) {
                    throw new RuntimeException('Lark did not acknowledge the file.');
                }
            }
            DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($document, $fileToken): void {
                $changed = InboxDocument::query()->whereKey($document->id)->where('lark_attempt_id', $document->lark_attempt_id)->update(['lark_status' => 'backed_up', 'lark_file_token' => $fileToken, 'lark_backed_up_at' => now(), 'lark_error' => null, 'lark_next_attempt_at' => null]);
                if ($changed) {
                    $this->event($document->company_id, 'backup_confirmed', documentId: $document->id);
                }
            });

            return true;
        } catch (Throwable) {
            DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($document): void {
                $changed = InboxDocument::query()->whereKey($document->id)->where('lark_attempt_id', $document->lark_attempt_id)->update(['lark_status' => 'failed', 'lark_error' => 'Backup not confirmed. Check Lark access, storage quota and the original file, then retry. The local original is retained.', 'lark_next_attempt_at' => now()->addMinutes(min(60, 2 ** $document->lark_attempts))]);
                if ($changed) {
                    $this->event($document->company_id, 'backup_failed', documentId: $document->id);
                }
            });

            return false;
        }
    }

    private function accessToken(object $connection): string
    {
        $response = $this->request()->post(self::API.'auth/v3/tenant_access_token/internal', ['app_id' => $connection->app_id, 'app_secret' => Crypt::decryptString($connection->app_secret)]);
        $this->data($response);
        $token = $response->json('tenant_access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Lark authentication failed.');
        }

        return $token;
    }

    public function verifiedDownload(InboxDocument $document): string
    {
        $connection = DB::connection(config('tenancy.database.central_connection'))->table('company_document_backups')->where('company_id', $document->company_id)->first();
        if (! $connection || $document->lark_status !== 'backed_up' || ! preg_match('/^[a-zA-Z0-9]{1,100}$/D', $document->lark_file_token ?? '') || $document->size < 1 || $document->size > 2097152) {
            throw new RuntimeException('A verified Lark copy is unavailable.');
        }
        $token = $this->accessToken($connection);
        $limit = $document->size;
        $response = $this->request($token)->timeout(40)->withOptions(['progress' => function (int|float $total, int|float $downloaded) use ($limit): void {
            if ($total > $limit || $downloaded > $limit) {
                throw new RuntimeException('Lark returned an unexpected file size.');
            }
        }])->get(self::API.'drive/v1/files/'.$document->lark_file_token.'/download');
        if (! $response->successful()) {
            throw new RuntimeException('The Lark copy could not be retrieved.');
        }
        $bytes = $response->body();
        if (strlen($bytes) !== $document->size || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('The Lark copy does not match the original.');
        }

        return $bytes;
    }

    /** @param array<string, mixed> $details */
    private function event(int $companyId, string $action, ?int $actorId = null, ?int $documentId = null, array $details = []): void
    {
        DB::connection(config('tenancy.database.central_connection'))->table('document_backup_events')->insert(['company_id' => $companyId, 'actor_id' => $actorId, 'inbox_document_id' => $documentId, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function request(?string $token = null): PendingRequest
    {
        $request = Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();

        return $token ? $request->withToken($token) : $request;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        if (! $response->successful() || $response->json('code') !== 0) {
            throw new RuntimeException('Lark request was not accepted.');
        }

        return $response->json('data') ?? [];
    }

    private function findExisting(string $token, string $folder, string $name): ?string
    {
        $pageToken = null;
        $deadline = microtime(true) + 60;
        do {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Folder reconciliation timed out.');
            }
            $data = $this->data($this->request($token)->get(self::API.'drive/v1/files', array_filter(['folder_token' => $folder, 'page_size' => 200, 'page_token' => $pageToken])));
            foreach ($data['files'] ?? [] as $file) {
                if (($file['type'] ?? '') === 'file' && ($file['name'] ?? '') === $name && preg_match('/^[a-zA-Z0-9]{1,100}$/D', $file['token'] ?? '')) {
                    return $file['token'];
                }
            }
            $pageToken = $data['has_more'] ?? false ? ($data['next_page_token'] ?? null) : null;
            if (($data['has_more'] ?? false) && ! $pageToken) {
                throw new RuntimeException('Folder listing was incomplete.');
            }
        } while ($pageToken);

        return null;
    }
}
