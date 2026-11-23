@props(['title', 'previewMode' => false, 'companyScope' => false, 'company' => []])
@php($workspaceNavigation = $previewMode || (auth()->user()?->hasApprovedAccess() && ! auth()->user()?->must_change_password))
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('role-access-assets')
    <title>{{ $title }} · kontadurya.io</title>
    <link rel="stylesheet" href="{{ asset('css/account.css') }}?v={{ filemtime(public_path('css/account.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/workspace-typography.css') }}?v={{ filemtime(public_path('css/workspace-typography.css')) }}">
    @if($workspaceNavigation)

        <link rel="stylesheet" href="{{ asset('css/workspace-navigation.css') }}?v={{ filemtime(public_path('css/workspace-navigation.css')) }}">
        <script src="{{ asset('js/workspace-navigation.js') }}?v={{ filemtime(public_path('js/workspace-navigation.js')) }}" defer></script>
    @endif
    <link rel="stylesheet" href="{{ asset('css/account-redesign.css') }}?v={{ filemtime(public_path('css/account-redesign.css')) }}">
</head>
<body @class(['account-layout', 'workspace-shell' => $workspaceNavigation])>
    @if($workspaceNavigation)
        @include('workspace-navigation')
    @else
    <header class="account-header">
        <a class="account-brand" href="{{ route('home') }}" aria-label="Kontadurya home">@include('components.project-logo')kontadurya<span class="accent">.io</span></a>
        <nav class="account-nav" aria-label="Account navigation">
            @include('theme-toggle')
            @if($previewMode)
                @foreach(['my-work' => 'My work', 'follow-ups' => 'Client follow-ups', 'documents' => 'Documents', 'document-review' => 'Review document', 'closing' => 'Manager review', 'lark-backup' => 'Lark backup', 'manual-purge' => 'Manual purge'] as $page => $label)<a href="{{ route('workspace.preview', ['page' => $page]) }}" @if(request('page') === $page) aria-current="page" @endif>{{ $label }}</a>@endforeach
                <a href="{{ route('team.preview') }}">Team &amp; roles</a>
            @else
            @auth
                @if(auth()->user()->hasApprovedAccess())
                @if(auth()->user()->is_system_owner)<a href="{{ route('system.dashboard') }}">System dashboard</a>@endif
                <a href="{{ route('my-work') }}" @if(request()->routeIs('my-work')) aria-current="page" @endif>My work</a>
                <a href="{{ route('documents.entry') }}">Documents</a>
                <a href="{{ route('follow-ups.entry') }}" @if(request()->routeIs('follow-ups.*')) aria-current="page" @endif>Client follow-ups</a>
                <a href="{{ route('account.dashboard') }}">Company books</a>
                @if(in_array(auth()->user()->type, ['individual', 'firm'], true))<a href="{{ route('team.entry') }}">Users &amp; access</a>@endif
                @else
                <a href="{{ route('account.registration-status') }}">Registration status</a>
                @endif
                <a href="{{ route('account.password.edit') }}">Change password</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="button secondary compact" type="submit">Sign out</button></form>
            @else
                <a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>Sign in</a>
                <a class="button secondary compact" href="{{ route('register') }}">Create account</a>
            @endauth
            @endif
        </nav>
    </header>
    @endif
    <main class="account-main" id="account-workspace-main" @if($companyScope) data-company-workspace="{{ $company['company_id'] }}" @endif>
        @if($previewMode)<div class="notice" role="status"><strong>Presentation preview · Sample data · Read-only</strong><br>These are the actual workspace layouts with fictional examples. Controls are disabled; no company records are created or changed.</div>@endif
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="notice error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if($previewMode)<div inert>{{ $slot }}</div>@else{{ $slot }}@endif
    </main>
    <footer class="account-footer">© {{ date('Y') }} kontadurya.io <span>Company books · Accounting workspace</span></footer>
</body>
</html>
