<x-account-layout title="Accountant setup">
    <section class="account-card password-card"><span class="eyebrow">ONE-TIME ACCOUNT SETUP</span><h1>{{ $portalCompany->name }}</h1><p class="muted">This setup link is for <strong>{{ $member->name }}</strong> ({{ $member->email }}). Continue to set your own password before opening any company records.</p>
    @auth<p class="notice">Sign out of your current account before continuing with this setup link.</p>
    @else<form method="POST" action="{{ $accessUrl }}">@csrf<button type="submit" class="button primary full">Continue as {{ $member->name }}</button></form>@endauth
    </section>
</x-account-layout>
