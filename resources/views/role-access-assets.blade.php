@once
<meta name="role-access-home" data-role-home="{{ route('account.dashboard') }}">
<link rel="stylesheet" href="{{ asset('css/role-access-feedback.css') }}?v={{ filemtime(public_path('css/role-access-feedback.css')) }}">
<script src="{{ asset('js/role-access-feedback.js') }}?v={{ filemtime(public_path('js/role-access-feedback.js')) }}" defer></script>
@if(auth()->check() && request()->attributes->get('active_company'))
@php($accessCompany = request()->attributes->get('active_company'))
@php($accessConfig = ['companyId' => $accessCompany->id, 'role' => $accessCompany->pivot->role, 'canEditSettings' => \App\TeamPermissions::canEditBookSettings($accessCompany->pivot->role, $accessCompany->pivot->allowed_tabs), 'tabs' => \App\TeamPermissions::allowed($accessCompany->pivot->role, $accessCompany->pivot->allowed_tabs)])
<script type="application/json" id="workspace-access-config">@json($accessConfig)</script>
<script src="{{ asset('js/workspace-access.js') }}?v={{ filemtime(public_path('js/workspace-access.js')) }}" defer></script>
@endif
@endonce
