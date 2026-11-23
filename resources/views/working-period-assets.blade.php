@include('role-access-assets')
<link rel="stylesheet" href="{{ asset('css/working-period.css') }}?v={{ filemtime(public_path('css/working-period.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-typography.css') }}?v={{ filemtime(public_path('css/workspace-typography.css')) }}">
<script src="{{ asset('js/working-period.js') }}?v={{ filemtime(public_path('js/working-period.js')) }}" defer></script>
@if($loadCompanyLedger ?? true)
@include('company-ledger-assets')
@endif
