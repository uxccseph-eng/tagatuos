@props(['title'])
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · kontadurya.io</title>
    <link rel="stylesheet" href="{{ asset('css/account.css') }}?v={{ filemtime(public_path('css/account.css')) }}">
</head>
<body>
    <header class="account-header">
        <a class="account-brand" href="{{ route('home') }}" aria-label="Kontadurya home"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round" stroke-linejoin="round"/></svg></span>kontadurya<span class="accent">.io</span></a>
        <nav class="account-nav" aria-label="Account navigation">
            @auth
                <a href="{{ route('account.dashboard') }}">Company books</a>
                <a href="{{ route('account.password.edit') }}">Change password</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="button secondary compact" type="submit">Sign out</button></form>
            @else
                <a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>Sign in</a>
                <a class="button secondary compact" href="{{ route('register') }}">Create account</a>
            @endauth
        </nav>
    </header>
    <main class="account-main">
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="notice error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        {{ $slot }}
    </main>
    <footer class="account-footer">© {{ date('Y') }} kontadurya.io <span>Company books · Accounting workspace</span></footer>
</body>
</html>
