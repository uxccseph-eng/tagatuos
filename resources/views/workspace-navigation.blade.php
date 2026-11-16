@php
    $navigationUser = auth()->user();
    $navigationCompanyId = request()->attributes->get('active_company')?->id ?? session('active_company_id');
    $bookUrl = fn (string $name): string => $previewMode ? route('workspace.preview', ['page' => 'my-work']) : ($navigationCompanyId ? route($name, ['company_id' => $navigationCompanyId]) : route('account.dashboard'));
    $navigationGroups = $previewMode ? [
        'Workspace preview' => [
            ['My work', 'task_alt', route('workspace.preview', ['page' => 'my-work']), request('page') === 'my-work'],
            ['Documents', 'folder_open', route('workspace.preview', ['page' => 'documents']), request('page') === 'documents'],
            ['Client follow-ups', 'chat_bubble_outline', route('workspace.preview', ['page' => 'follow-ups']), request('page') === 'follow-ups'],
            ['Manager review', 'checklist', route('workspace.preview', ['page' => 'closing']), request('page') === 'closing'],
            ['Review document', 'description', route('workspace.preview', ['page' => 'document-review']), request('page') === 'document-review'],
            ['Lark backup', 'cloud_upload', route('workspace.preview', ['page' => 'lark-backup']), request('page') === 'lark-backup'],
            ['Manual purge', 'inventory_2', route('workspace.preview', ['page' => 'manual-purge']), request('page') === 'manual-purge'],
            ['Team & roles', 'group', route('team.preview'), request()->routeIs('team.preview')],
        ],
    ] : [
        'Main ledger' => [
            ['Home', 'home', $bookUrl('dashboard'), false],
            ['My work', 'task_alt', route('my-work'), request()->routeIs('my-work')],
            ['Sheets', 'table_chart', $bookUrl('sheets.sales'), false],
            ['Books', 'menu_book', $bookUrl('books'), false],
            ['Lapsing (PPE)', 'domain', $bookUrl('lapsing'), false],
        ],
        'Workspace' => [
            ['Documents', 'folder_open', route('documents.entry'), request()->routeIs('documents.*')],
            ['Client follow-ups', 'chat_bubble_outline', route('follow-ups.entry'), request()->routeIs('follow-ups.*')],
        ],
        'Accounting' => [
            ['Masters', 'account_tree', $bookUrl('masters.accounts'), false],
            ['Financial Reports', 'bar_chart', $bookUrl('reports'), false],
            ['Tax Reports', 'receipt_long', $bookUrl('tax-reports'), false],
            ['Tax Returns', 'description', $bookUrl('tax-returns.index'), false],
            ['Tax Summary', 'analytics', $bookUrl('tax-summary.index'), request()->routeIs('tax-summary.*')],
        ],
        'Admin' => [
            ['Settings', 'settings', $bookUrl('settings'), false],
            ['Clients', 'business_center', route('account.dashboard'), request()->routeIs('account.dashboard', 'closing.*')],
        ],
    ];
    if ($companyScope) {
        unset($navigationGroups['Workspace']);
    }
@endphp
<a class="workspace-skip-link" href="#account-workspace-main">Skip to content</a>
<aside class="workspace-app-sidebar" id="workspace-app-sidebar" aria-label="Workspace sidebar">
    <div class="workspace-sidebar-brand">
        <a href="{{ $previewMode ? route('home') : ($companyScope ? $bookUrl('dashboard') : route('my-work')) }}" class="workspace-brand">
            <span class="workspace-brand-symbol material-symbols-outlined" aria-hidden="true">account_balance</span>
            <span><strong>kontadurya<span>.io</span></strong><small>LEDGER CORE</small></span>
        </a>
        <button type="button" class="workspace-icon-button workspace-nav-close" data-workspace-close aria-label="Close navigation"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </div>
    <nav class="workspace-app-navigation" aria-label="Workspace navigation">
        @foreach($navigationGroups as $group => $links)
            <div class="workspace-nav-group"><h2>{{ $group }}</h2>
                @foreach($links as [$caption, $icon, $url, $active])
                    <a href="{{ $url }}" @if($active) aria-current="page" @endif><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span><span>{{ $caption }}</span></a>
                @endforeach
                @if(!$previewMode && $group === 'Admin' && in_array($navigationUser->type, ['individual', 'firm'], true))
                    <a href="{{ route('team.entry') }}" @if(request()->routeIs('team.*')) aria-current="page" @endif><span class="material-symbols-outlined" aria-hidden="true">group</span><span>Users &amp; access</span></a>
                @endif
                @if(!$previewMode && $group === 'Admin' && $navigationUser->is_system_owner)
                    <a href="{{ route('system.dashboard') }}" @if(request()->routeIs('system.*')) aria-current="page" @endif><span class="material-symbols-outlined" aria-hidden="true">admin_panel_settings</span><span>System dashboard</span></a>
                @endif
            </div>
        @endforeach
    </nav>
    <div class="workspace-sidebar-foot"><span class="workspace-status-dot" aria-hidden="true"></span><div><strong>{{ $previewMode ? 'Read-only preview' : ($companyScope ? 'Company book' : 'Firm workspace') }}</strong><small>{{ $previewMode ? 'Fictional sample records' : ($companyScope ? 'Private client data' : 'Client books stay separate') }}</small></div></div>
</aside>
<button type="button" class="workspace-nav-backdrop" data-workspace-close hidden aria-label="Close navigation"></button>
<header class="workspace-app-header" id="workspace-app-header">
    <button type="button" class="workspace-icon-button workspace-nav-toggle" id="workspace-nav-toggle" aria-label="Open navigation" aria-controls="workspace-app-sidebar" aria-expanded="false"><span class="material-symbols-outlined" aria-hidden="true">menu</span></button>
    <span class="workspace-header-location">{{ $title }}</span>
    @if($companyScope)
    <a class="workspace-header-company-context" href="{{ $bookUrl('dashboard') }}" aria-label="Open {{ $company['company_name'] }} dashboard">{{ $company['company_name'] }}</a>
    @else
    <form method="GET" action="{{ $previewMode ? route('workspace.preview', ['page' => 'my-work']) : route('my-work') }}" class="workspace-header-search" role="search" aria-label="Find a client book">
        <span class="material-symbols-outlined" aria-hidden="true">search</span><input type="search" name="q" aria-label="Search client books" placeholder="Search client books…" maxlength="100"><kbd aria-hidden="true">Ctrl K</kbd>
    </form>
    @endif
    <div class="workspace-header-actions">
        @if($companyScope)
            @include('company-switcher')
        @else
        <a href="{{ $previewMode ? route('team.preview') : route('account.dashboard') }}" class="workspace-book-chooser" aria-label="Company books" title="Company books"><span class="material-symbols-outlined" aria-hidden="true">business</span><span>Company books</span><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a>
        @endif
        @include('theme-toggle')
        @include('components.account-profile', ['variant' => 'workspace', 'previewMode' => $previewMode])
    </div>
</header>
