@include('role-access-assets')
<link rel="stylesheet" href="{{ asset('css/working-period.css') }}?v={{ filemtime(public_path('css/working-period.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-typography.css') }}?v={{ filemtime(public_path('css/workspace-typography.css')) }}">
<script src="{{ asset('js/working-period.js') }}?v={{ filemtime(public_path('js/working-period.js')) }}" defer></script>
<script src="{{ asset('js/company-ledger-records.js') }}?v={{ filemtime(public_path('js/company-ledger-records.js')) }}" defer></script>
<script src="{{ asset('js/company-ledger-sync.js') }}?v={{ filemtime(public_path('js/company-ledger-sync.js')) }}" defer></script>
<link rel="stylesheet" href="{{ asset('css/company-ledger-sync.css') }}?v={{ filemtime(public_path('css/company-ledger-sync.css')) }}">
