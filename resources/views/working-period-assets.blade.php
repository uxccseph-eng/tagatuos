<link rel="stylesheet" href="{{ asset('css/working-period.css') }}?v={{ filemtime(public_path('css/working-period.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-typography.css') }}?v={{ filemtime(public_path('css/workspace-typography.css')) }}">
<script src="{{ asset('js/working-period.js') }}?v={{ filemtime(public_path('js/working-period.js')) }}" defer></script>
<script src="{{ asset('js/company-ledger-sync.js') }}?v={{ filemtime(public_path('js/company-ledger-sync.js')) }}" defer></script>
<link rel="stylesheet" href="{{ asset('css/company-ledger-sync.css') }}?v={{ filemtime(public_path('css/company-ledger-sync.css')) }}">
@if(auth()->check() && request()->attributes->get('active_company'))
@php($accessCompany = request()->attributes->get('active_company'))
@php($accessConfig = ['companyId' => $accessCompany->id, 'role' => $accessCompany->pivot->role, 'tabs' => \App\TeamPermissions::allowed($accessCompany->pivot->role, $accessCompany->pivot->allowed_tabs)])
<script type="application/json" id="workspace-access-config">@json($accessConfig)</script>
<script src="{{ asset('js/workspace-access.js') }}?v={{ filemtime(public_path('js/workspace-access.js')) }}" defer></script>
@endif
