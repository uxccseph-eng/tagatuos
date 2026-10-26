<link rel="stylesheet" href="{{ asset('css/working-period.css') }}?v={{ filemtime(public_path('css/working-period.css')) }}">
<script src="{{ asset('js/working-period.js') }}?v={{ filemtime(public_path('js/working-period.js')) }}" defer></script>
@if(auth()->check() && request()->attributes->get('active_company'))
@php($accessCompany = request()->attributes->get('active_company'))
@php($accessConfig = ['companyId' => $accessCompany->id, 'role' => $accessCompany->pivot->role, 'tabs' => \App\TeamPermissions::allowed($accessCompany->pivot->role, $accessCompany->pivot->allowed_tabs)])
<script type="application/json" id="workspace-access-config">@json($accessConfig)</script>
<script src="{{ asset('js/workspace-access.js') }}?v={{ filemtime(public_path('js/workspace-access.js')) }}" defer></script>
@endif
