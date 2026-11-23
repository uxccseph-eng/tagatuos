@once
<link rel="preload" href="{{ asset('fonts/material-symbols-outlined-v385.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('css/workspace-icons.css') }}?v={{ filemtime(public_path('css/workspace-icons.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-theme.css') }}?v={{ filemtime(public_path('css/workspace-theme.css')) }}">
<link rel="stylesheet" href="{{ asset('css/account-profile.css') }}?v={{ filemtime(public_path('css/account-profile.css')) }}">
<script src="{{ asset('js/sales-theme.js') }}?v={{ filemtime(public_path('js/sales-theme.js')) }}"></script>
<link rel="stylesheet" href="{{ asset('css/workspace-popups.css') }}?v={{ filemtime(public_path('css/workspace-popups.css')) }}">
<script src="{{ asset('js/workspace-popups.js') }}?v={{ filemtime(public_path('js/workspace-popups.js')) }}" defer></script>
<link rel="stylesheet" href="{{ asset('css/workspace-consistency.css') }}?v={{ filemtime(public_path('css/workspace-consistency.css')) }}">
<link rel="icon" type="image/png" href="{{ asset('kontadurya-logo.png') }}">
<script src="{{ asset('js/workspace-rail.js') }}?v={{ filemtime(public_path('js/workspace-rail.js')) }}" defer></script>
@include('workspace-presence-assets')
@endonce
