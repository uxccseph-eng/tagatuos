<x-account-layout :title="$portalCompany->name . ' sign in'">
    <section class="account-card password-card portal-card"><span class="eyebrow">COMPANY PORTAL</span><h1>{{ $portalCompany->name }}</h1><p class="muted">Sign in with the account assigned to this company book.</p>
    @auth<p>You are signed in as {{ auth()->user()->name }}.</p><a class="button primary full" href="{{ route('account.dashboard') }}">Choose a company book</a>
    @else<form method="POST" action="{{ route('portal.login', ['firmSlug' => $portalCompany->slug]) }}" class="field-stack">@csrf<div class="field"><label for="portal-email">Username or email address</label><input id="portal-email" name="email" type="text" autocomplete="username" value="{{ old('email') }}" required maxlength="254" autofocus></div><div class="field"><label for="portal-password">Password</label><input id="portal-password" name="password" type="password" autocomplete="current-password" required maxlength="128"></div><button type="submit" class="button primary full">Sign in to this book</button></form>@endauth
    </section>
</x-account-layout>
