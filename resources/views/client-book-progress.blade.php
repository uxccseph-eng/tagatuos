@php
    $active = in_array($status, ['pending', 'provisioning', 'deleting'], true);
    $failed = in_array($status, ['failed', 'delete_failed'], true);
    $caption = $message ?: match($status) {
        'pending' => 'Waiting for client setup to start…',
        'provisioning' => 'Preparing the client database and private folder…',
        'deleting' => 'Deleting the client database and private folder…',
        'failed' => 'Client setup failed. Retry setup to continue.',
        'delete_failed' => 'Client deletion needs attention. Retry deletion to continue.',
        default => '',
    };
@endphp
<div class="client-book-progress {{ $failed ? 'is-failed' : '' }}" data-client-progress role="status" aria-live="polite" @if(! $active && ! $failed) hidden @endif>
    <div class="client-progress-track" data-progress-bar role="progressbar" aria-label="{{ $status === 'deleting' ? 'Client deletion in progress' : 'Client setup in progress' }}" @if(! $active) hidden @endif><span></span></div>
    <p data-progress-message>{{ $caption }}</p>
    <small data-progress-connection hidden></small>
</div>
