@include('workspace-theme-assets')
@once
<link rel="stylesheet" href="{{ asset('css/workspace-controls.css') }}?v={{ filemtime(public_path('css/workspace-controls.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-redesign.css') }}?v={{ filemtime(public_path('css/workspace-redesign.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-pages.css') }}?v={{ filemtime(public_path('css/workspace-pages.css')) }}">
<link rel="stylesheet" href="{{ asset('css/workspace-topbar.css') }}?v={{ filemtime(public_path('css/workspace-topbar.css')) }}">
@endonce
