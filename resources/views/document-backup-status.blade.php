<small class="document-backup-state">{{ \App\Models\InboxDocument::BACKUP_STATUSES[$document->lark_status] ?? 'Local copy only' }}@if($document->lark_backed_up_at) · {{ $document->lark_backed_up_at->timezone('Asia/Manila')->format('M j, g:i A') }}@endif</small>
@if($document->lark_status === 'failed')<small>{{ $document->lark_error }}</small>@endif

@if($document->local_purged_at)<small>Local copy purged {{ $document->local_purged_at->timezone('Asia/Manila')->format('M j, Y') }} · Retrieved from Lark on demand</small>@elseif($document->local_purge_batch_id)<small>Manual purge approval in progress · Owner review available</small>@endif
