<!DOCTYPE html>
<html class="h-full transition-colors duration-300 dark" lang="en">
<head>
  <meta charset="utf-8">
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
      <div class="flex items-baseline">
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

  <!-- Right Controls: Free Badge, Company Switcher, Theme Toggle, Notification, Profile -->
  <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">
    <!-- Free Core Unlimited Pill Badge -->
    <div class="hidden lg:flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 font-mono text-[11px] text-emerald-700 dark:text-[#00f5a0]">
      <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-[#00f5a0] animate-pulse"></span>
      <span class="font-semibold">Free Core · Unlimited</span>
    </div>

    <!-- Company Switcher -->
    <a href="{{ route('account.dashboard') }}" aria-label="Switch company book" class="h-8 px-2.5 rounded-lg bg-slate-100 dark:bg-[#1d2433] border border-slate-200 dark:border-[#2b3548] hover:bg-slate-200/70 dark:hover:bg-[#252f42] flex items-center gap-1.5 text-slate-800 dark:text-slate-200 font-mono text-xs transition-colors">
      <span class="material-symbols-outlined text-[15px] text-cyan-600 dark:text-[#00d8f6]">domain</span>
      <span class="font-semibold tracking-tight max-w-[120px] xl:max-w-[160px] truncate">{{ $company['company_name'] }}</span>
      <span class="material-symbols-outlined text-[14px] text-slate-400">unfold_more</span>
    </a>

    <!-- LIVE INTERACTIVE THEME TOGGLE (DARK / LIGHT) -->
    <button id="themeToggleBtn" type="button" aria-label="Toggle dark/light theme" title="Toggle theme (Light / Dark)" class="relative w-8 h-8 rounded-lg bg-slate-100 dark:bg-[#1d2433] border border-slate-200 dark:border-[#2b3548] flex items-center justify-center text-slate-600 dark:text-amber-400 hover:text-slate-900 dark:hover:text-amber-300 hover:bg-slate-200/70 dark:hover:bg-[#252f42] transition-colors focus:outline-none focus:ring-1 focus:ring-emerald-500 cursor-pointer">
      <span id="themeToggleIcon" class="material-symbols-outlined text-[18px]">light_mode</span>
    </button>

    @auth
    <a href="{{ route('account.dashboard') }}" class="text-xs font-semibold text-emerald-500">Company books</a>
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
        <span class="text-emerald-700 dark:text-[#00f5a0] font-semibold">Local preview</span>
        <span class="text-slate-400">No BIR connection</span>
      </div>
    </div>
  </div>
</aside>

<button type="button" id="sidebarBackdrop" aria-label="Close navigation" hidden></button><!-- MAIN WORKSPACE CONTENT -->
<div id="workspaceContent" class="pl-0 md:pl-[240px] transition-all duration-300">
  <main id="workspaceMain" class="relative pt-14 min-h-screen workspace-bg text-slate-800 dark:text-slate-100 px-6 sm:px-8 py-6 lg:py-7 transition-colors duration-300">
    @include('dashboard-overview')
    <div data-dashboard-onboarding class="max-w-5xl mx-auto space-y-4">
@if(session('status'))<div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-200">{{ session('status') }}</div>@endif
<p class="text-xs text-slate-500 dark:text-slate-400">Local workspace preview · Settings are session-based. Records stay in this browser tab. No BIR filings are submitted.</p>
      <!-- COMPANY HEADER -->
      <section class="ui-panel rounded-xl bg-white dark:bg-[#161c28] border border-slate-200 dark:border-[#262f40] p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div class="flex items-center gap-3.5 min-w-0">
            <div class="w-10 h-10 rounded-lg bg-slate-100 dark:bg-[#202838] border border-slate-200 dark:border-[#2b3548] flex items-center justify-center text-slate-600 dark:text-slate-300 shrink-0">
              <span class="material-symbols-outlined text-[22px]">apartment</span>
            </div>
            <div class="flex flex-col gap-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h1 class="font-headline font-bold text-base sm:text-lg text-slate-900 dark:text-white tracking-tight leading-snug truncate">{{ $company['company_name'] }}</h1>
                <span class="inline-flex items-center px-2 py-0.5 rounded bg-slate-100 dark:bg-[#202838] border border-slate-200 dark:border-[#2b3548] text-slate-600 dark:text-slate-300 font-mono text-[10px] font-medium">Active</span>
              </div>
              <p class="font-mono text-[11px] text-slate-500 dark:text-slate-400 tracking-tight leading-tight truncate">{{ now('Asia/Manila')->format('F j, Y') }} · {{ $company['accounting_period'] === 'fiscal' ? 'Fiscal year' : 'Calendar year' }} · {{ $company['vat_type'] === 'vat' ? 'VAT-registered' : 'Non-VAT' }} · RDO {{ $company['rdo'] ?? 'not set' }} · TIN: {{ \App\Tin::format($company['tin'] ?? '', $company['branch_code'] ?? '00000') ?: 'not set' }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3 shrink-0 self-start md:self-center">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-400 font-mono text-xs font-semibold">
              <span class="material-symbols-outlined text-[15px] font-bold">check_circle</span>Setup saved
            </span>
            <button data-workspace-topic="Sheets" class="inline-flex items-center justify-center gap-2 whitespace-nowrap font-medium cursor-pointer transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:cursor-not-allowed bg-orange-500 hover:bg-orange-600 text-slate-950 font-semibold shadow-sm h-8 rounded-md px-3.5 text-xs">
              <span>Open sheets</span>
              <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
            </button>
          </div>
        </div>
      </section>

      <!-- CARD 3: JUMP IN QUICK ACTIONS DOCK -->
      <section class="ui-panel rounded-xl bg-white dark:bg-[#161c28] border border-slate-200 dark:border-[#262f40] px-6 sm:px-8 py-3.5 shadow-sm">
        <div class="flex items-center gap-2 overflow-x-auto scrollbar-none py-0.5">
          <span class="font-mono text-[10px] font-bold tracking-wider uppercase text-slate-400 shrink-0 whitespace-nowrap mr-2">JUMP IN</span>
          <div class="flex items-center gap-1.5 shrink-0">
            <a href="{{ route('sheets.sales') }}" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-orange-500">receipt</span>
              <span>Sales</span>
            </a>
            <a href="#workspace-dialog" data-workspace-topic="Collections" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-amber-500">payments</span>
              <span>Collections</span>
            </a>
            <a href="#workspace-dialog" data-workspace-topic="Purchases" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-emerald-500">shopping_cart</span>
              <span>Purchases</span>
            </a>
            <a href="#workspace-dialog" data-workspace-topic="Payments" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-cyan-500">credit_card</span>
              <span>Payments</span>
            </a>
            <a href="#workspace-dialog" data-workspace-topic="Journal" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-slate-400">edit_note</span>
              <span>Journal</span>
            </a>
            <a href="#workspace-dialog" data-workspace-topic="Documents" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-rose-500">document_scanner</span>
              <span>Documents</span>
            </a>
            <a href="{{ route('reports') }}" data-workspace-topic="Reports" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-indigo-400">bar_chart</span>
              <span>Reports</span>
            </a>
            <a href="#workspace-dialog" data-workspace-topic="Masters" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md hover:bg-slate-100 dark:hover:bg-[#202838] text-slate-700 dark:text-slate-300 font-mono text-xs transition-colors shrink-0">
              <span class="material-symbols-outlined text-[16px] text-amber-600">layers</span>
              <span>Masters</span>
            </a>
          </div>
        </div>
      </section>

      <!-- CARD 4: GET STARTED 5-STEP CHECKLIST -->
      <section id="setupChecklist" class="ui-panel rounded-xl bg-white dark:bg-[#161c28] border border-slate-200 dark:border-[#262f40] p-6 sm:p-8 space-y-4 shadow-sm">
        <!-- Checklist Header -->
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-lg bg-orange-500/10 dark:bg-orange-500/15 border border-orange-500/30 text-orange-600 dark:text-orange-400 flex items-center justify-center shrink-0">
              <span class="material-symbols-outlined text-[18px] leading-none">rocket_launch</span>
            </div>
            <div class="min-w-0">
              <h2 class="font-headline font-bold text-base text-slate-900 dark:text-white leading-tight">Get started</h2>
              <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">A few steps to set up the books and start recording.</p>
            </div>
          </div>
          <button data-checklist-dismiss class="inline-flex items-center gap-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 font-mono text-xs transition-colors shrink-0">
            <span class="material-symbols-outlined text-[15px] leading-none">schedule</span>
            <span>Set up later</span>
          </button>
        </div>

        <!-- Progress Indicator -->
        <div class="space-y-1.5 pt-0.5">
          <div class="flex justify-between items-center font-mono text-xs leading-none">
            <span class="text-slate-500 dark:text-slate-400">1 of 5 complete</span>
            <span class="font-bold text-orange-600 dark:text-orange-400">20%</span>
          </div>
          <div class="w-full h-1.5 rounded-full bg-slate-200 dark:bg-[#262f40] overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r from-orange-500 to-amber-500 w-[20%]"></div>
          </div>
        </div>

        <!-- 5 Steps List with generous breathing room -->
        <div class="space-y-1 pt-1 divide-y divide-slate-100 dark:divide-[#242c3d]">
          <!-- Step 1: Add your company details (Done) -->
          <div class="flex items-center justify-between gap-4 py-3.5">
            <div class="flex items-center gap-3.5 min-w-0 flex-1">
              <div class="w-6 h-6 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[14px] font-bold leading-none">check</span>
              </div>
              <div class="min-w-0 flex-1">
                <span class="text-xs font-semibold text-slate-800 dark:text-slate-200 block leading-tight">Add your company details</span>
                <p class="text-[11px] text-slate-400 mt-0.5 truncate">TIN, RDO, and registered name — the header block every BIR form needs.</p>
              </div>
            </div>
            <div class="shrink-0 flex items-center justify-end min-w-[110px]">
              <span class="text-xs font-mono font-medium text-emerald-600 dark:text-emerald-400 inline-flex items-center gap-1">
                <span class="material-symbols-outlined text-[14px] font-bold leading-none">check</span>
                <span>Done</span>
              </span>
            </div>
          </div>

          <!-- Step 2: Review your chart of accounts (Done) -->
          <button type="button" data-workspace-topic="Chart of accounts" class="checklist-review flex w-full items-center justify-between gap-4 py-3.5 text-left">
            <div class="flex items-center gap-3.5 min-w-0 flex-1">
              <div class="w-6 h-6 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[14px] font-bold leading-none">account_tree</span>
              </div>
              <div class="min-w-0 flex-1">
                <span class="text-xs font-semibold text-slate-800 dark:text-slate-200 block leading-tight">Review your chart of accounts</span>
                <p class="text-[11px] text-slate-400 mt-0.5 truncate">Explore the proposed account structure before recording transactions.</p>
              </div>
            </div>
            <div class="shrink-0 flex items-center justify-end min-w-[110px]">
              <span class="text-xs font-mono font-medium text-emerald-600 dark:text-emerald-400 inline-flex items-center gap-1">
                <span class="material-symbols-outlined text-[14px] font-bold leading-none">account_tree</span>
                <span>Review</span>
              </span>
            </div>
          </button>
<!-- Step 3: Record your first transaction (Active Next Step) -->
          <div class="flex items-center justify-between gap-4 py-3.5">
            <div class="flex items-center gap-3.5 min-w-0 flex-1">
              <div class="w-6 h-6 rounded-full bg-orange-500/20 border border-orange-500/40 text-orange-600 dark:text-orange-400 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[14px] leading-none">edit_document</span>
              </div>
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <span class="text-xs font-bold text-slate-900 dark:text-white leading-tight">Record your first transaction</span>
                  <span class="px-1.5 py-0.5 rounded bg-cyan-50 dark:bg-cyan-500/15 text-cyan-700 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-500/30 font-mono text-[10px] font-semibold leading-none">Next step</span>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">Enter a sale, purchase, or journal entry in the sheets, then post it to the ledger.</p>
              </div>
            </div>
            <div class="shrink-0 flex items-center justify-end min-w-[110px]">
              <button data-workspace-topic="Sheets" class="h-8 px-4 rounded-md bg-orange-500 hover:bg-[#ea580c] text-slate-950 font-semibold text-xs inline-flex items-center justify-center shadow-sm transition-colors cursor-pointer">Open sheets</button>
            </div>
          </div>

          <!-- Step 4: Review your books (Pending) -->
          <div class="flex items-center justify-between gap-4 py-3.5 opacity-80 hover:opacity-100 transition-opacity">
            <div class="flex items-center gap-3.5 min-w-0 flex-1">
              <div class="w-6 h-6 rounded-full bg-slate-100 dark:bg-[#202838] border border-slate-200 dark:border-slate-700 text-slate-400 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[13px] leading-none">menu_book</span>
              </div>
              <div class="min-w-0 flex-1">
                <span class="text-xs font-medium text-slate-700 dark:text-slate-300 block leading-tight">Review your books</span>
                <p class="text-[11px] text-slate-400 mt-0.5 truncate">Trial balance, financial statements, agings, and the BIR schedules.</p>
              </div>
            </div>
            <div class="shrink-0 flex items-center justify-end min-w-[110px]">
              <button data-workspace-topic="Financial Reports" class="h-7 px-2.5 rounded border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-500 text-slate-600 dark:text-slate-300 text-[11px] font-mono transition-colors">Open reports</button>
            </div>
          </div>

          <!-- Step 5: File your BIR returns (Pending) -->
          <div class="flex items-center justify-between gap-4 py-3.5 opacity-80 hover:opacity-100 transition-opacity">
            <div class="flex items-center gap-3.5 min-w-0 flex-1">
              <div class="w-6 h-6 rounded-full bg-slate-100 dark:bg-[#202838] border border-slate-200 dark:border-slate-700 text-slate-400 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[13px] leading-none">assignment</span>
              </div>
              <div class="min-w-0 flex-1">
                <span class="text-xs font-medium text-slate-700 dark:text-slate-300 block leading-tight">File your BIR returns</span>
                <p class="text-[11px] text-slate-400 mt-0.5 truncate">Generate the eBIRForms XML for a period straight from your posted books.</p>
              </div>
            </div>
            <div class="shrink-0 flex items-center justify-end min-w-[110px]">
              <button data-workspace-topic="Tax Returns" class="h-7 px-2.5 rounded border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-500 text-slate-600 dark:text-slate-300 text-[11px] font-mono transition-colors">Open Tax Returns</button>
            </div>
          </div>
        </div>
      </section>
    </div>
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
<button type="button" data-checklist-restore hidden class="fixed bottom-5 right-5 z-30 rounded-lg bg-orange-500 px-4 py-2.5 text-xs font-bold text-slate-950 shadow-lg">Show setup checklist</button>
</body>
</html>
