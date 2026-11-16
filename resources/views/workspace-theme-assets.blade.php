@once
<link rel="stylesheet" href="{{ asset('css/workspace-theme.css') }}?v={{ filemtime(public_path('css/workspace-theme.css')) }}">
<link rel="stylesheet" href="{{ asset('css/account-profile.css') }}?v={{ filemtime(public_path('css/account-profile.css')) }}">
<script src="{{ asset('js/sales-theme.js') }}?v={{ filemtime(public_path('js/sales-theme.js')) }}"></script>
<link rel="stylesheet" href="{{ asset('css/workspace-popups.css') }}?v={{ filemtime(public_path('css/workspace-popups.css')) }}">
<script src="{{ asset('js/workspace-popups.js') }}?v={{ filemtime(public_path('js/workspace-popups.js')) }}" defer></script>
@endonce
