@if(auth()->user()?->hasApprovedAccess() && !auth()->user()->must_change_password && !request()->is('preview', 'preview/*'))
<link rel="stylesheet" href="{{ asset('css/workspace-presence.css') }}?v={{ filemtime(public_path('css/workspace-presence.css')) }}">
<script type="application/json" id="workspace-presence-config">@json(['url' => route('workspace.presence.heartbeat'), 'csrf' => csrf_token(), 'companyId' => (request()->attributes->get('active_company') ?? request()->attributes->get('tenant_company'))?->id])</script>
<script src="{{ asset('js/workspace-presence.js') }}?v={{ filemtime(public_path('js/workspace-presence.js')) }}" defer></script>
@endif
