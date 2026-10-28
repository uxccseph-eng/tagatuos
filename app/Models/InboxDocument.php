<?php

namespace App\Models;

use Database\Factories\InboxDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Hidden(['path', 'sha256', 'lark_file_token', 'lark_attempt_id'])]
class InboxDocument extends Model
{
    use CentralConnection;

    /** @use HasFactory<InboxDocumentFactory> */
    use HasFactory;

    public const STATUSES = ['received' => 'Received', 'needs_entry' => 'Needs entry', 'linked' => 'Linked', 'needs_clarification' => 'Needs clarification', 'archived' => 'Archived'];

    public const BACKUP_STATUSES = ['pending' => 'Waiting for backup', 'uploading' => 'Backup in progress', 'backed_up' => 'Copied to Lark', 'failed' => 'Backup needs retry'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer', 'lark_attempts' => 'integer', 'lark_started_at' => 'datetime', 'lark_next_attempt_at' => 'datetime', 'lark_backed_up_at' => 'datetime', 'lark_verified_at' => 'datetime', 'local_purged_at' => 'datetime'];
    }
}
