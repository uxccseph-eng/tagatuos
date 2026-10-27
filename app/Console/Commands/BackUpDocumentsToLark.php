<?php

namespace App\Console\Commands;

use App\Models\InboxDocument;
use App\Services\LarkDocumentBackupService;
use Illuminate\Console\Command;

class BackUpDocumentsToLark extends Command
{
    protected $signature = 'documents:backup-lark {--limit=5 : Maximum documents per run}';

    protected $description = 'Copy pending company documents to their authorized Lark Drive folders';

    public function handle(LarkDocumentBackupService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 100.');

            return self::INVALID;
        }
        $ids = InboxDocument::query()->where(fn ($query) => $query->where('lark_attempts', '<', 5)->orWhere('lark_status', 'uploading'))
            ->where(fn ($query) => $query->whereIn('lark_status', ['pending', 'failed'])->orWhere(fn ($query) => $query->where('lark_status', 'uploading')->where('lark_started_at', '<=', now()->subMinutes(10))))
            ->where(fn ($query) => $query->whereNull('lark_next_attempt_at')->orWhere('lark_next_attempt_at', '<=', now()))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('company_document_backups')->whereColumn('company_document_backups.company_id', 'inbox_documents.company_id')->where('enabled', true)->whereNotNull('verified_at'))
            ->orderBy('id')->limit($limit)->pluck('id');
        $backedUp = 0;
        foreach ($ids as $id) {
            if ($service->backUp($id)) {
                $backedUp++;
            }
        }
        $this->info($backedUp.' documents confirmed in Lark; '.($ids->count() - $backedUp).' pending, skipped or failed.');

        return $backedUp === $ids->count() ? self::SUCCESS : self::FAILURE;
    }
}
