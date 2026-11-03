<x-account-layout title="Review firm handover">
    <section class="page-heading"><div><span class="eyebrow">OWNERSHIP HANDOVER</span><h1>Review {{ $scope['owner']['name'] }}</h1><p class="muted">Confirm that you want to take responsibility for this entire firm.</p></div><a class="button secondary" href="{{ route('account.dashboard') }}">Company books</a></section>
    @include('account-ownership-scope')
    @if($transfer->status === 'pending' && $transfer->expires_at->isFuture())
    <div class="notice"><strong>You will become the firm owner.</strong><p>All client books and existing team assignments will move to your account. The previous owner loses access to these clients. Client databases, files and accounting records stay in place. Your own name, email and password remain unchanged.</p><p>Expires {{ $transfer->expires_at->format('M j, Y H:i T') }}.</p></div>
    <section class="account-card"><form class="field-stack" method="POST" action="{{ route('account.ownership.accept', $transfer->id) }}">@csrf<input name="scope_hash" type="hidden" value="{{ $scopeHash }}">
        <div class="field"><label for="firm-confirmation">Type {{ $scope['owner']['name'] }} to accept all client books</label><input id="firm-confirmation" name="confirmation" maxlength="255" required autocomplete="off"></div>
        <div class="field"><label for="accept-password">Your current password</label><input id="accept-password" name="current_password" type="password" maxlength="128" required autocomplete="current-password"></div>
        <button class="button primary" type="submit">Accept firm ownership</button>
    </form><form method="POST" action="{{ route('account.ownership.close', $transfer->id) }}">@csrf<button class="button secondary" type="submit">Decline handover</button></form></section>
    @else<div class="notice">This handover is {{ $transfer->status === 'pending' ? 'expired' : $transfer->status }}.</div>@endif
</x-account-layout>
