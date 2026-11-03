<!DOCTYPE html>
<html class="h-full transition-colors duration-300 dark" lang="en">
<head>
  <meta charset="utf-8">
  @include('role-access-assets')
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>kontadurya.io — Double-Entry Bookkeeping &amp; BIR Compliance</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&amp;family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <script src="{{ asset('js/dashboard-config.js') }}"></script>
  <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ filemtime(public_path('css/dashboard.css')) }}"><script src="{{ route('masters.accounts.catalog', ['_company_id' => $company['company_id'] ?? null]) }}"></script><script src="{{ asset('js/sheet-masters.js') }}?v={{ filemtime(public_path('js/sheet-masters.js')) }}" defer></script><script src="{{ asset('js/workspace-ledger.js') }}?v={{ filemtime(public_path('js/workspace-ledger.js')) }}" defer></script><script src="{{ asset('js/dashboard-report.js') }}?v={{ filemtime(public_path('js/dashboard-report.js')) }}" defer></script><script src="{{ asset('js/dashboard.js') }}?v={{ filemtime(public_path('js/dashboard.js')) }}" defer></script>
<script src="{{ asset('js/draft-leave-guard.js') }}?v={{ filemtime(public_path('js/draft-leave-guard.js')) }}" defer></script><link rel="stylesheet" href="{{ asset('css/workspace-typography.css') }}?v={{ filemtime(public_path('css/workspace-typography.css')) }}">
</head>
<body class="bg-[#f8fafc] dark:bg-[#0e131f] text-slate-800 dark:text-slate-100 font-sans antialiased min-h-screen transition-colors duration-300">

<!-- TOP PERSISTENT NAVIGATION BAR -->
<header class="fixed top-0 left-0 right-0 h-14 bg-white/95 dark:bg-[#161c28]/95 backdrop-blur-xl z-50 border-b border-slate-200 dark:border-[#262f40] px-3.5 sm:px-5 flex items-center justify-between gap-3 transition-colors duration-300">
  
  <!-- Brand & Create Action -->
  <div class="flex items-center gap-3 shrink-0">
    <button id="sidebarToggle" aria-controls="sidebarNav" aria-expanded="true" aria-label="Toggle navigation" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-[#202838] transition-colors">
      <span class="material-symbols-outlined text-[20px]">menu</span>
    </button>
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 group cursor-pointer">
      <div class="w-7 h-7 rounded-lg bg-gradient-to-tr from-[#00f5a0] to-[#00d8f6] text-[#030712] flex items-center justify-center font-black shadow-[0_0_12px_rgba(0,245,160,0.35)]">
        <span class="material-symbols-outlined text-[17px] font-bold">hub</span>
      </div>
      <div class="hidden sm:flex items-baseline">
        <span class="font-headline font-bold text-base tracking-tight text-slate-900 dark:text-white">kontadurya</span>
        <span class="font-mono text-xs font-bold text-emerald-500 dark:text-[#00f5a0]">.io</span>
      </div>
    </a>
    <button type="button" data-workspace-topic="Create" aria-label="Create" title="Create" class="flex h-8 shrink-0 cursor-pointer items-center gap-2 rounded-full border border-slate-200 dark:border-[#2b3548] bg-slate-100 dark:bg-[#1d2433] pl-1.5 pr-1.5 text-xs font-medium text-slate-800 dark:text-slate-200 transition-colors hover:bg-slate-200/70 dark:hover:bg-[#252f42] sm:pr-3.5">
      <span class="w-5 h-5 flex items-center justify-center rounded-full bg-orange-500 text-white font-bold">
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>
      </span>
      <span class="hidden sm:inline font-headline font-semibold">Create</span>
    </button>
  </div>

  <!-- Search Bar with Keyboard Shortcut -->
  <div class="flex-1 max-w-md mx-auto hidden md:flex items-center">
    <button type="button" data-search-open aria-label="Search workspace" class="w-full h-8 px-3 bg-slate-100 dark:bg-[#1d2433] border border-slate-200 dark:border-[#2b3548] rounded-lg flex items-center justify-between text-slate-500 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-600 transition-all cursor-pointer">
      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined text-[16px] text-slate-400">search</span>
        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">Search workspace...</span>
      </div>
      <div class="flex items-center gap-0.5 font-mono text-[10px] bg-slate-200 dark:bg-[#283246] border border-slate-300 dark:border-[#38455e] px-1.5 py-0.5 rounded text-slate-600 dark:text-slate-300">
        <span>⌘</span><span>K</span>
      </div>
    </button>
  </div>

  <!-- Company and account controls -->
  <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">

    <!-- Company Switcher -->
    @include('company-switcher')

    <!-- LIVE INTERACTIVE THEME TOGGLE (DARK / LIGHT) -->
    <button id="themeToggleBtn" type="button" aria-label="Toggle dark/light theme" title="Toggle theme (Light / Dark)" class="relative w-8 h-8 rounded-lg bg-slate-100 dark:bg-[#1d2433] border border-slate-200 dark:border-[#2b3548] flex items-center justify-center text-slate-600 dark:text-amber-400 hover:text-slate-900 dark:hover:text-amber-300 hover:bg-slate-200/70 dark:hover:bg-[#252f42] transition-colors focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer">
      <span id="themeToggleIcon" class="material-symbols-outlined text-[18px]">light_mode</span>
    </button>

    @auth
    <a href="{{ route('account.dashboard') }}" class="hidden sm:inline text-xs font-semibold text-emerald-500">Company books</a>
    @endauth

    <!-- User Profile Avatar (JR) -->
    <button type="button" aria-label="{{ auth()->check() ? 'Profile for '.auth()->user()->name : 'Preview profile' }}" data-workspace-topic="Profile" class="profile-button flex items-center gap-2 cursor-pointer">
      @auth<span class="hidden lg:block max-w-[140px] truncate text-xs font-semibold text-slate-800 dark:text-slate-200">{{ auth()->user()->name }}</span>@endauth
      <span class="relative w-8 h-8 rounded-full bg-slate-200 dark:bg-gradient-to-br dark:from-slate-700 dark:to-slate-800 border border-slate-300 dark:border-slate-600 flex items-center justify-center">
        <span class="font-mono text-[11px] text-slate-800 dark:text-white font-bold">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? $company['full_name'], 0, 1)) }}</span>
        <span class="absolute bottom-0 right-0 w-2 h-2 rounded-full bg-emerald-500 dark:bg-[#00f5a0] ring-2 ring-white dark:ring-[#161c28]"></span>
      </span>
    </button>
  </div>
</header>

<!-- FIXED PERSISTENT SIDEBAR -->
<aside id="sidebarNav" class="fixed left-0 top-14 h-[calc(100vh-56px)] w-[240px] bg-white dark:bg-[#161c28] border-r border-slate-200 dark:border-[#262f40] z-40 flex flex-col py-3 overflow-y-auto transition-all duration-300 shadow-sm">
  <!-- Main Ledger Section -->
  <div class="px-4 py-1.5 flex items-center justify-between">
    <span class="font-mono text-[10px] tracking-wider uppercase text-slate-400 font-bold">Main Ledger</span>
  </div>
  <nav class="px-2 space-y-1 mt-0.5">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-semibold text-slate-900 dark:text-white bg-slate-100 dark:bg-[#202838] border-l-2 border-emerald-500 dark:border-[#00f5a0] transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px] text-emerald-600 dark:text-[#00f5a0]">dashboard</span>
      </span>
      <span>Home</span>
    </a>
    <a href="{{ route('my-work') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">task_alt</span>
      </span>
      <span>My work</span>
    </a>
    <a href="{{ route('sheets.sales') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">table_chart</span>
      </span>
      <span>Sheets</span>
    </a>
    <a href="{{ route('books') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">menu_book</span>
      </span>
      <span>Books</span>
    </a>
    @if($company['sheet_settings']['ppe'] ?? true)<a href="{{ route('lapsing') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">hourglass_empty</span>
      </span>
      <span>Lapsing (PPE)</span>
    </a>@endif
  </nav>

  <!-- Accounting Section -->
  <button type="button" data-section-toggle aria-expanded="true" class="w-full mt-4 px-4 py-1.5 flex items-center justify-between text-slate-400 cursor-pointer group">
    <span class="font-mono text-[10px] tracking-wider uppercase group-hover:text-slate-800 dark:group-hover:text-slate-300 font-bold">Accounting</span>
    <span class="w-4 h-4 flex items-center justify-center">
      <span class="material-symbols-outlined text-[15px]">expand_more</span>
    </span>
  </button>
  <nav class="px-2 space-y-1 mt-0.5">
    <a href="#workspace-dialog" data-workspace-topic="Masters" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">account_tree</span>
      </span>
      <span>Masters</span>
    </a>
    <a href="{{ route('reports') }}" data-workspace-topic="Financial Reports" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">assessment</span>
      </span>
      <span>Financial Reports</span>
    </a>
    <a href="{{ route('tax-reports') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">receipt_long</span>
      </span>
      <span>Tax Reports</span>
    </a>
    <a href="#workspace-dialog" data-workspace-topic="Tax Returns" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">assignment_turned_in</span>
      </span>
      <span>Tax Returns</span>
    </a>
    <a href="#workspace-dialog" data-workspace-topic="Tax Summary" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">query_stats</span>
      </span>
      <span>Tax Summary</span>
    </a>
  </nav>

  <!-- Admin Section -->
  <button type="button" data-section-toggle aria-expanded="true" class="w-full mt-4 px-4 py-1.5 flex items-center justify-between text-slate-400 cursor-pointer group">
    <span class="font-mono text-[10px] tracking-wider uppercase group-hover:text-slate-800 dark:group-hover:text-slate-300 font-bold">Admin</span>
    <span class="w-4 h-4 flex items-center justify-center">
      <span class="material-symbols-outlined text-[15px]">expand_more</span>
    </span>
  </button>
  <nav class="px-2 space-y-1 mt-0.5">
    <a href="{{ route('team.entry') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">shield_person</span>
      </span>
      <span>Users &amp; access</span>
    </a>
    <a href="{{ route('settings') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">settings</span>
      </span>
      <span>Settings</span>
    </a>
    <a href="#workspace-dialog" data-workspace-topic="Clients" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100/70 dark:hover:bg-[#1d2433] text-xs font-medium transition-colors">
      <span class="w-5 h-5 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-[18px]">storefront</span>
      </span>
      <span>Clients</span>
    </a>
  </nav>

  <!-- Bottom Sync Status -->
  <div class="mt-auto p-3">
    <div class="p-2.5 rounded-lg bg-slate-100/90 dark:bg-[#1d2433] border border-slate-200 dark:border-[#2b3548] flex flex-col gap-1 transition-colors">
      <div class="flex items-center justify-between">
        <span class="font-mono text-[10px] text-slate-500 dark:text-slate-400 font-medium">Workspace status</span>
        <span class="w-2 h-2 rounded-full bg-emerald-500 dark:bg-[#00f5a0] shadow-[0_0_6px_rgba(0,245,160,0.8)]"></span>
      </div>
      <div class="flex items-center justify-between font-mono text-[10px]">
        <span class="text-emerald-700 dark:text-[#00f5a0] font-semibold">{{ auth()->check() ? 'Company workspace' : 'Local preview' }}</span>
        <span class="text-slate-400">No BIR connection</span>
      </div>
    </div>
  </div>
</aside>

<button type="button" id="sidebarBackdrop" aria-label="Close navigation" hidden></button><!-- MAIN WORKSPACE CONTENT -->
<div id="workspaceContent" class="pl-0 md:pl-[240px] transition-all duration-300">
  <main id="workspaceMain" class="relative pt-14 min-h-screen workspace-bg text-slate-800 dark:text-slate-100 px-6 sm:px-8 py-6 lg:py-7 transition-colors duration-300">
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('dashboard-overview')
  <script type="application/json" id="workspace-ledger-config" data-key="{{ $company['browser_key'] ?? hash('sha256', session()->getId().'|'.$company['company_name'].'|'.($company['tin'] ?? '')) }}">{"period":"{{ now('Asia/Manila')->format('Y-m') }}"}</script>
  <script type="application/json" id="dashboard-routes">@json($dashboardRoutes)</script>
  </main>
</div>

<!-- JAVASCRIPT: THEME TOGGLE & INTERACTIVE CONTROLS -->

<dialog data-sales-url="{{ route('sheets.sales') }}" id="workspace-dialog" aria-labelledby="workspace-dialog-title" aria-describedby="workspace-dialog-description" data-company="{{ $company['company_name'] }}" data-owner="{{ $company['full_name'] }}" data-setup-url="{{ route('settings') }}" class="workspace-dialog rounded-2xl border border-slate-200 bg-white p-7 text-slate-800 shadow-2xl dark:border-slate-700 dark:bg-[#161c28] dark:text-slate-100">
    <form method="dialog" class="flex justify-end"><button type="submit" aria-label="Close dialog" class="rounded-lg border border-slate-300 px-3 py-1 text-lg dark:border-slate-600">×</button></form>
    <p class="mt-2 font-mono text-[10px] uppercase tracking-widest text-emerald-600 dark:text-emerald-400">{{ auth()->check() ? 'Account workspace' : 'Workspace preview' }}</p>
    <h2 id="workspace-dialog-title" class="mt-3 font-headline text-2xl font-bold"></h2>
    <p id="workspace-dialog-description" class="mt-4 text-sm leading-relaxed text-slate-500 dark:text-slate-400"></p>
    @auth
    <div data-dialog-profile hidden class="mt-4 space-y-3 text-sm">
        <dl class="space-y-2">
            <div><dt class="text-slate-500 dark:text-slate-400">Signed in as</dt><dd class="font-semibold">{{ auth()->user()->name }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Email</dt><dd class="break-all">{{ auth()->user()->email }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Role in this book</dt><dd>{{ ucfirst(request()->attributes->get('active_company')?->pivot?->role ?? auth()->user()->type) }}</dd></div>
        </dl>
        <a href="{{ route('account.password.edit') }}" class="inline-flex rounded-lg bg-orange-500 px-4 py-2.5 font-semibold text-slate-950">Change password</a>
        <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit" class="font-semibold text-rose-600 dark:text-rose-400">Sign out</button></form>
    </div>
    @endauth
    <div data-dialog-create class="mt-5 grid grid-cols-2 gap-3" hidden>
        @foreach(['Sales', 'Purchases', 'Payments', 'Journal'] as $action)
            <button type="button" data-workspace-topic="{{ $action }}" class="rounded-lg border border-slate-300 px-4 py-3 text-sm hover:border-orange-500 dark:border-slate-700">{{ $action }}</button>
        @endforeach
    </div>
    <a data-dialog-settings href="{{ route('settings') }}" class="mt-5 inline-flex rounded-lg bg-orange-500 px-4 py-2.5 text-sm font-semibold text-slate-950" hidden>Open settings</a>
    <form data-dialog-exit action="{{ route('preview.exit') }}" method="POST" class="mt-4" hidden>@csrf<button type="submit" class="text-sm font-semibold text-rose-600 dark:text-rose-400">Exit demo and clear session</button></form>
</dialog>
<dialog data-sales-url="{{ route('sheets.sales') }}" id="workspace-search" aria-labelledby="search-heading" data-dashboard-url="{{ route('dashboard') }}" data-setup-url="{{ route('settings') }}" class="workspace-dialog rounded-2xl border border-slate-200 bg-white p-6 text-slate-800 shadow-2xl dark:border-slate-700 dark:bg-[#161c28] dark:text-slate-100">
    <div class="flex items-center justify-between gap-4"><h2 id="search-heading" class="font-headline text-xl font-bold">Search workspace</h2><form method="dialog"><button aria-label="Close search" class="px-2 py-1 text-xl">×</button></form></div>
    <label for="workspace-query" class="sr-only">Search workspace pages</label>
    <input id="workspace-query" type="search" autocomplete="off" placeholder="Try settings, sheets, or reports" class="mt-5 w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-950">
    <p class="mt-3 text-xs text-slate-500">Search workspace pages and planned modules.</p>
    <div data-search-results class="mt-4 max-h-72 space-y-1 overflow-y-auto"></div>
</dialog>

</body>
</html>
