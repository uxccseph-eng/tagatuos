@props(['variant' => 'sheet', 'previewMode' => false])
@php
    $profileUser = auth()->user();
    $profileCompany = request()->attributes->get('active_company');
    $profileRole = $profileCompany?->pivot?->role;
    $isPreviewProfile = $previewMode || request()->routeIs('workspace.preview', 'team.preview');
    $profileName = $isPreviewProfile ? 'Sample Manager' : ($profileUser?->name ?? data_get($company ?? [], 'full_name', 'Workspace user'));
    $profileRoleLabel = $isPreviewProfile ? 'Read-only preview' : match ($profileRole) {
        'owner' => $profileUser?->type === 'firm' ? 'Firm owner' : 'Owner',
        'manager' => 'Manager',
        'accountant' => 'Accountant',
        'staff' => 'Staff',
        default => match ($profileUser?->type) {
            'firm' => 'Firm owner',
            'individual' => 'Individual owner',
            'accountant' => 'Accountant',
            default => 'Workspace member',
        },
    };
    $profileInitials = collect(preg_split('/\s+/u', trim($profileName)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp
<details class="workspace-profile account-profile account-profile--{{ $variant }}">
    <summary aria-label="Account menu for {{ $profileName }}">
        <span class="workspace-profile-copy">
            <span class="workspace-profile-name">{{ $profileName }}</span>
            <span class="workspace-profile-role">{{ $profileRoleLabel }}</span>
        </span>
        <span class="workspace-profile-avatar" aria-hidden="true">{{ $profileInitials ?: 'U' }}</span>
    </summary>
    <div class="workspace-profile-menu account-profile-menu">
        <strong>{{ $profileName }}</strong>
        <span class="account-profile-menu-role">{{ $profileRoleLabel }}</span>
        @if($profileUser && !$isPreviewProfile)
            <a href="{{ route('account.password.edit') }}">Change password</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
        @else
            <span class="account-profile-preview">Presentation preview</span>
        @endif
    </div>
</details>
