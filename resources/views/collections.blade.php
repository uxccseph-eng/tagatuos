<!DOCTYPE html>

<html class="dark" lang="en"><head><script src="{{ asset('js/sheet-posting.js') }}?v={{ filemtime(public_path('js/sheet-posting.js')) }}" defer></script>@include('working-period-assets')<meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/><meta content="web_dashboard" name="shell-type"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&amp;family=Manrope:wght@400;500;600&amp;family=Space+Grotesk:wght@600;700&amp;display=swap" rel="stylesheet"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><script src="https://cdn.tailwindcss.com"></script><script src="{{ asset('js/sales-theme.js') }}"></script><script src="{{ asset('js/sales-config.js') }}"></script><link rel="stylesheet" href="{{ asset('css/sales.css') }}"><script src="{{ route('masters.accounts.catalog', ['_company_id' => $company['company_id'] ?? null]) }}"></script><script src="{{ asset('js/sheet-masters.js') }}?v={{ filemtime(public_path('js/sheet-masters.js')) }}" defer></script><script src="{{ asset('js/sheet-master-picker.js') }}?v={{ filemtime(public_path('js/sheet-master-picker.js')) }}" defer></script><script src="{{ asset('js/workspace-ledger.js') }}?v={{ filemtime(public_path('js/workspace-ledger.js')) }}" defer></script><script src="{{ asset('js/draft-leave-guard.js') }}?v={{ filemtime(public_path('js/draft-leave-guard.js')) }}" defer></script><script src="{{ asset('js/collection-entry.js') }}?v={{ filemtime(public_path('js/collection-entry.js')) }}" defer></script><script src="{{ asset('js/collections.js') }}?v={{ filemtime(public_path('js/collections.js')) }}" defer></script><title>Collections · {{ $company['company_name'] }} · kontadurya.io</title><link rel="stylesheet" href="{{ asset('css/collections.css') }}"><link rel="stylesheet" href="{{ asset('css/sheet-navigation.css') }}?v={{ filemtime(public_path('css/sheet-navigation.css')) }}"></head><body class="bg-background dark:bg-background text-on-surface dark:text-on-surface antialiased font-body-md theme-transition selection:bg-primary-container selection:text-on-primary-fixed"><aside id="sales-sidebar" aria-label="Workspace navigation" class="fixed left-0 top-0 h-full w-72 bg-white dark:bg-surface-container-low/90 border-r border-slate-200 dark:border-surface-container-highest/40 backdrop-blur-2xl z-50 flex flex-col justify-between shadow-sm dark:shadow-[0_1px_8px_rgba(0,0,0,0.04)] theme-transition"><div class="flex flex-col flex-1 overflow-y-auto"><div class="h-16 px-space-lg flex items-center justify-between border-b border-slate-100 dark:border-transparent"><div class="flex items-center gap-space-sm"><div class="w-8 h-8 rounded-lg bg-emerald-500 dark:bg-primary-container flex items-center justify-center shadow-md dark:shadow-[0_0_16px_rgba(0,245,160,0.35)]"><span class="material-symbols-outlined text-white dark:text-on-primary-fixed text-[20px]">account_balance</span></div><div class="flex flex-col"><span class="font-headline-sm text-headline-sm text-slate-900 dark:text-on-surface tracking-tight leading-none">kontadurya<span class="text-emerald-600 dark:text-primary-container">.io</span></span><span class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant tracking-wider uppercase mt-1">Ledger Core</span></div></div></div><nav class="px-space-md py-space-sm space-y-space-xs" data-active-classes="bg-primary-container text-on-primary-container font-headline-sm rounded-lg"><div class="px-space-sm py-space-xs"><span class="font-label-sm text-label-sm uppercase tracking-wider text-slate-400 dark:text-on-surface-variant/70">Main Ledger</span></div><a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="home" href="{{ route('dashboard') }}"><span class="material-symbols-outlined text-[18px]">home</span><span>Home</span></a><a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="my-work" href="{{ route('my-work') }}"><span class="material-symbols-outlined text-[18px]">task_alt</span><span>My work</span></a><a aria-current="page" class="flex items-center gap-space-sm px-space-sm py-2 transition-all bg-emerald-500 text-white dark:bg-primary-container dark:text-on-primary-container font-headline-sm rounded-lg shadow-sm" data-path="sheets" href="{{ route('sheets.sales') }}"><span class="material-symbols-outlined text-[18px]">table_chart</span><span>Sheets</span></a><a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="books" href="{{ route('books') }}"><span class="material-symbols-outlined text-[18px]">menu_book</span><span>Books</span></a>@if($company['sheet_settings']['ppe'] ?? true)<a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="lapsing-ppe" href="{{ route('lapsing') }}"><span class="material-symbols-outlined text-[18px]">domain</span><span>Lapsing (PPE)</span></a>@endif<details class="group pt-space-xs" open=""><summary class="flex items-center justify-between px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface cursor-pointer transition-all list-none"><div class="flex items-center gap-space-sm font-label-sm text-label-sm uppercase tracking-wider"><span class="material-symbols-outlined text-[16px]">receipt_long</span><span>Accounting</span></div><span class="material-symbols-outlined text-[16px] transition-transform group-open:rotate-180">expand_more</span></summary><div class="pl-space-lg pr-space-xs py-space-xs space-y-1"><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="masters" href="{{ route('masters.accounts') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">account_tree</span><span>Masters</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="financial-reports" href="{{ route('reports') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">assessment</span><span>Financial Reports</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="tax-reports" href="{{ route('tax-reports') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">receipt_long</span><span>Tax Reports</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="tax-returns" href="#sales-dialog" data-sales-info="Tax Returns"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">assignment_turned_in</span><span>Tax Returns</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="tax-summary" href="#sales-dialog" data-sales-info="Tax Summary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">query_stats</span><span>Tax Summary</span></a></div></details><details class="group pt-space-xs" open=""><summary class="flex items-center justify-between px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface cursor-pointer transition-all list-none"><div class="flex items-center gap-space-sm font-label-sm text-label-sm uppercase tracking-wider"><span class="material-symbols-outlined text-[16px]">shield_person</span><span>Admin</span></div><span class="material-symbols-outlined text-[16px] transition-transform group-open:rotate-180">expand_more</span></summary><div class="pl-space-lg pr-space-xs py-space-xs space-y-1"><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="users-and-access" href="{{ route('team.entry') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">shield_person</span><span>Users &amp; access</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="settings" href="{{ route('settings') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">settings</span><span>Settings</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="clients" href="#sales-dialog" data-sales-info="Clients"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">storefront</span><span>Clients</span></a></div></details></nav></div><div class="p-space-md"><div class="bg-slate-100 dark:bg-surface-container-lowest/80 border border-slate-200 dark:border-transparent rounded-xl p-space-sm shadow-sm dark:shadow-[0_1px_8px_rgba(0,0,0,0.04)]"><div class="flex items-center gap-space-xs mb-1"><span class="w-2 h-2 rounded-full bg-emerald-500 dark:bg-primary-container shadow-[0_0_8px_rgba(0,245,160,0.8)]"></span><span class="font-label-sm text-label-sm text-emerald-600 dark:text-primary-container uppercase tracking-wide font-semibold">Local sales preview</span></div><p class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant">Sample data · No BIR connection</p></div></div></aside><div id="sales-shell" class="pl-72"><header id="sales-header" class="fixed top-0 left-72 right-0 h-16 bg-white/80 dark:bg-surface/80 backdrop-blur-xl border-b border-slate-200 dark:border-surface-container-highest/40 shadow-sm dark:shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-40 px-gutter flex items-center justify-between gap-space-md theme-transition"><div class="flex items-center gap-space-md flex-1"><button data-collection-action="add" class="flex items-center gap-space-xs bg-emerald-500 text-white dark:bg-primary-container dark:text-on-primary-fixed px-space-md py-2 rounded-lg font-label-md text-label-md uppercase tracking-wider hover:bg-emerald-600 dark:hover:bg-primary-fixed dark:hover:text-on-primary-fixed transition-all shadow-sm dark:shadow-[0_0_16px_rgba(0,245,160,0.25)] font-semibold" type="button"><span class="material-symbols-outlined text-[16px]">add</span><span>Create</span></button><div class="relative flex-1 max-w-md"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-on-surface-variant text-[18px]">search</span><input class="w-full bg-slate-100 dark:bg-surface-container-lowest/70 text-slate-900 dark:text-on-surface placeholder:text-slate-400 dark:placeholder:text-on-surface-variant pl-10 pr-12 py-2 rounded-lg font-body-sm text-body-sm outline-none focus:bg-white dark:focus:bg-surface-container-high border border-transparent focus:border-slate-300 dark:focus:border-transparent transition-all" id="sales-global-search" aria-label="Search collections" placeholder="Search collections..." type="text"/><span class="absolute right-2.5 top-1/2 -translate-y-1/2 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant px-1.5 py-0.5 rounded bg-slate-200 dark:bg-surface-container-high">⌘K</span></div><div class="flex items-center gap-space-xs bg-slate-100 dark:bg-surface-container-low border border-slate-200/80 dark:border-transparent px-space-sm py-1.5 rounded-lg cursor-pointer hover:bg-slate-200/60 dark:hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[18px] text-sky-600 dark:text-secondary">corporate_fare</span><span class="font-label-sm text-label-sm text-slate-800 dark:text-on-surface uppercase font-medium">{{ $company['company_name'] }}</span><span class="material-symbols-outlined text-[16px] text-slate-400 dark:text-on-surface-variant">arrow_drop_down</span></div></div><div class="flex items-center gap-space-md">@include('company-switcher')<div class="flex items-center gap-space-xs"><button aria-label="Toggle dark/light mode" class="w-9 h-9 rounded-lg flex items-center justify-center text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all border border-slate-200 dark:border-surface-container-highest/60" id="theme-toggle-btn" title="Toggle Dark/Light Mode" type="button"><span class="material-symbols-outlined text-[20px] transition-transform duration-300" id="theme-icon">light_mode</span></button></div><div class="flex items-center gap-space-sm pl-space-xs border-l border-slate-200 dark:border-surface-container-highest/50"><div class="flex flex-col text-right hidden sm:flex"><span class="font-body-sm text-body-sm text-slate-900 dark:text-on-surface font-semibold leading-tight">{{ $company['full_name'] }}</span><span class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant">Demo workspace</span></div><div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-800 dark:bg-primary dark:text-on-primary flex items-center justify-center shadow-sm dark:shadow-[0_0_12px_rgba(0,245,160,0.2)] font-semibold"><span class="material-symbols-outlined text-[18px]">person</span></div></div></div></header><main id="collections-main" data-period="{{ now('Asia/Manila')->format('Y-m') }}" class="relative pt-16 w-full bg-slate-50 dark:bg-surface min-h-screen theme-transition"><div class="flex flex-col w-full">
<div class="flex flex-col w-full pb-space-xl">
<!-- Subtle Spatial Glow Overlays -->
<div class="fixed top-16 right-1/4 w-[480px] h-[480px] rounded-full bg-primary-container/5 blur-[120px] pointer-events-none -z-10"></div>
<div class="fixed bottom-10 left-1/3 w-[560px] h-[560px] rounded-full bg-secondary-container/5 blur-[140px] pointer-events-none -z-10"></div>
<header class="sheet-page-header">@include('sheet-heading', ['sheet' => 'collections'])
</header>
@include('sheet-tabs', ['sheet' => 'collections'])
<!-- SECTION 3: SPREADSHEET ACTION TOOLBAR -->
<div class="w-full px-gutter pt-space-md pb-space-sm">
@include('sheet-toolbar', ['sheet' => 'collections'])
</div>
<div class="px-gutter mt-3"><p class="collection-notice">Local collections · Cash sales are collected automatically. On-account sales remain unpaid until you post a collection. Records stay in this browser tab across page changes and reloads. No real payment or BIR filing is made.</p><p id="collection-status" role="status" aria-live="polite" class="text-body-sm text-secondary mt-2"></p><div id="collection-filter" hidden class="collection-filter"><label>Payment method <select id="collection-method-filter"><option value="all">All methods</option><option>Cash</option><option>Bank Transfer</option><option>Check</option><option>GCash / E-Wallet</option></select></label><label>Status <select id="collection-status-filter"><option value="all">All</option><option value="draft">Drafts</option><option value="posted">Posted</option><option value="void">Voided</option></select></label></div></div><section class="unpaid-sales mx-gutter mt-space-md" aria-labelledby="unpaid-sales-title"><div class="flex items-center justify-between gap-2"><h2 id="unpaid-sales-title" class="font-headline-sm text-body-md">Unpaid sales invoices</h2><span id="unpaid-invoice-count" class="text-label-sm text-secondary"></span></div><p class="text-body-sm text-on-surface-variant">Posted sales awaiting collection · All dates · Balances are net of expected withholding.</p><div id="unpaid-invoice-list"></div></section><!-- SECTION 4: COLLECTIONS SPREADSHEET TABLE (HIGH-DENSITY NEO-SPATIAL GRID) -->
<div class="w-full px-gutter mt-space-sm">
<div class="w-full overflow-hidden rounded-xl bg-surface-container-lowest/70 backdrop-blur-2xl shadow-xl shadow-black/30">
<div id="collections-scroll" class="w-full overflow-x-auto no-scrollbar" role="region" aria-label="Scrollable collection grid" tabindex="0">
<table id="collections-table" aria-label="Collection transactions" class="w-full min-w-[1440px] text-left border-collapse">
<!-- Table Sticky Header -->
<thead>
<tr class="bg-surface-container-high/90 text-on-surface-variant font-label-sm text-label-sm uppercase tracking-wider select-none">
<!-- 1. Selection Checkbox -->
<th class="w-12 px-space-md py-3 text-center sticky left-0 bg-surface-container-high z-20" scope="col">
<input aria-label="Select all rows" class="w-4 h-4 rounded bg-surface-container-lowest text-primary-container accent-primary-container cursor-pointer" type="checkbox"/>
</th>
<!-- 2. Status -->
<th class="w-28 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Status</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 3. Date -->
<th class="w-32 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Date</span>
<span class="material-symbols-outlined text-[14px] text-secondary">arrow_downward</span>
</div>
</th>
<!-- 4. Txn No -->
<th class="w-36 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Txn No</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 5. Customer / Payor -->
<th class="w-72 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Customer / Payor</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 6. Source SI -->
<th class="w-56 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Source SI (Invoice)</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 7. Amount Applied (PHP) -->
<th class="w-44 px-space-sm py-3 text-right font-semibold" scope="col">
<div class="flex items-center justify-end gap-1 hover:text-on-surface cursor-pointer">
<span>Amount (₱)</span>
<span class="material-symbols-outlined text-[14px] text-primary-fixed-dim">swap_vert</span>
</div>
</th>
<!-- 8. Cash / Bank Account -->
<th class="w-56 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Cash / Bank COA</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 9. Official Receipt / Ref No -->
<th class="w-40 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>OR / Ref No.</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 10. Payment Method -->
<th class="w-36 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Method</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 11. Entry Type -->
<th class="w-36 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Entry Type</span>
<span class="material-symbols-outlined text-[14px] text-outline">unfold_more</span>
</div>
</th>
<!-- 12. BIR Compliance Flag -->
<th class="w-32 px-space-sm py-3 font-semibold" scope="col">
<div class="flex items-center gap-1 hover:text-on-surface cursor-pointer">
<span>Audit / BIR</span>
<span class="material-symbols-outlined text-[14px] text-outline">verified_user</span>
</div>
</th>
<!-- 13. Actions Column -->
<th class="w-24 px-space-sm py-3 text-right pr-space-md font-semibold" scope="col">Actions</th>
</tr>
</thead>
<!-- Table Body with Dense Alternating Glass Fills -->
<tbody id="collection-rows" class="divide-y-0 text-on-surface font-body-sm text-body-sm">
<!-- ROW 1 (Posted Entry) -->
<tr class="hover:bg-surface-container-high/40 transition-colors group">
<!-- Checkbox (Sticky) -->
<td class="w-12 px-space-md py-3 text-center sticky left-0 bg-surface-container-lowest group-hover:bg-surface-container-high/40 z-10">
<input aria-label="Select CR-000001" class="w-4 h-4 rounded bg-surface-container-low text-primary-container accent-primary-container cursor-pointer" type="checkbox"/>
</td>
<!-- Status Pill -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-label-sm text-label-sm bg-primary-container/15 text-primary-fixed-dim font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-primary-fixed-dim"></span>
                  Posted
                </span>
</td>
<!-- Date -->
<td class="px-space-sm py-3 font-mono text-on-surface-variant whitespace-nowrap">2026-10-16</td>
<!-- Txn No -->
<td class="px-space-sm py-3 font-mono font-semibold text-secondary-fixed whitespace-nowrap">CR-000001</td>
<!-- Customer -->
<td class="px-space-sm py-3">
<div class="flex flex-col">
<span class="font-medium text-on-surface font-body-sm">VARIOUS CUSTOMERS</span>
<span class="font-label-sm text-[11px] text-outline">Walk-in OTC Retail · VAT Registered</span>
</div>
</td>
<!-- Source SI -->
<td class="px-space-sm py-3 font-mono text-body-sm text-on-surface-variant whitespace-nowrap">
<div class="flex items-center gap-1.5">
<span class="text-on-surface">SI-000001</span>
<span class="text-xs px-1.5 py-0.5 rounded bg-surface-container-high text-outline">₱8.93 open</span>
</div>
</td>
<!-- Amount Applied -->
<td class="px-space-sm py-3 text-right font-mono font-bold text-primary-fixed whitespace-nowrap">
                ₱491.07
              </td>
<!-- Cash/Bank COA -->
<td class="px-space-sm py-3 whitespace-nowrap">
<div class="flex items-center gap-1.5">
<span class="w-2 h-2 rounded-full bg-secondary-fixed-dim"></span>
<span class="font-mono text-on-surface">1010</span>
<span class="text-outline">· Cash on Hand</span>
</div>
</td>
<!-- OR / Ref -->
<td class="px-space-sm py-3 font-mono text-on-surface whitespace-nowrap">OR-00912</td>
<!-- Method -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="px-2 py-0.5 rounded bg-surface-container-high text-on-surface font-label-sm text-label-sm">Cash</span>
</td>
<!-- Entry Type -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="px-2 py-0.5 rounded-full bg-surface-container-high text-secondary-fixed font-label-sm text-label-sm font-medium">Settlement</span>
</td>
<!-- BIR Compliance -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="inline-flex items-center gap-1 text-primary-fixed font-label-sm text-label-sm">
<span class="material-symbols-outlined text-[14px]">check_circle</span>
<span>Posted (demo)</span>
</span>
</td>
<!-- Row Actions -->
<td class="px-space-sm py-3 text-right pr-space-md whitespace-nowrap">
<div class="inline-flex items-center gap-1 opacity-70 group-hover:opacity-100 transition-opacity">
<button class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface" title="View / Edit" type="button" data-collection-action="edit">
<span class="material-symbols-outlined text-[16px]">edit</span>
</button>
<button class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface" title="Duplicate row" type="button" data-collection-action="duplicate">
<span class="material-symbols-outlined text-[16px]">content_copy</span>
</button>
<button class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface" title="More options" type="button" data-collection-action="more">
<span class="material-symbols-outlined text-[16px]">more_vert</span>
</button>
</div>
</td>
</tr>
<!-- ROW 2 (Posted Corporate Entry) -->
<tr class="bg-surface-container/20 hover:bg-surface-container-high/40 transition-colors group">
<!-- Checkbox (Sticky) -->
<td class="w-12 px-space-md py-3 text-center sticky left-0 bg-surface-container-lowest group-hover:bg-surface-container-high/40 z-10">
<input aria-label="Select CR-000002" class="w-4 h-4 rounded bg-surface-container-low text-primary-container accent-primary-container cursor-pointer" type="checkbox"/>
</td>
<!-- Status Pill -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-label-sm text-label-sm bg-primary-container/15 text-primary-fixed-dim font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-primary-fixed-dim"></span>
                  Posted
                </span>
</td>
<!-- Date -->
<td class="px-space-sm py-3 font-mono text-on-surface-variant whitespace-nowrap">2026-10-18</td>
<!-- Txn No -->
<td class="px-space-sm py-3 font-mono font-semibold text-secondary-fixed whitespace-nowrap">CR-000002</td>
<!-- Customer -->
<td class="px-space-sm py-3">
<div class="flex flex-col">
<span class="font-medium text-on-surface font-body-sm">Cruz Dental Clinic Corp.</span>
<span class="font-label-sm text-[11px] text-outline">TIN: 249-119-302-000 · Regular Corp</span>
</div>
</td>
<!-- Source SI -->
<td class="px-space-sm py-3 font-mono text-body-sm text-on-surface-variant whitespace-nowrap">
<div class="flex items-center gap-1.5">
<span class="text-on-surface">SI-000002</span>
<span class="text-xs px-1.5 py-0.5 rounded bg-primary-container/10 text-primary-fixed-dim">₱9,800 settled</span>
</div>
</td>
<!-- Amount Applied -->
<td class="px-space-sm py-3 text-right font-mono font-bold text-primary-fixed whitespace-nowrap">
                ₱9,800.00
              </td>
<!-- Cash/Bank COA -->
<td class="px-space-sm py-3 whitespace-nowrap">
<div class="flex items-center gap-1.5">
<span class="w-2 h-2 rounded-full bg-secondary-fixed"></span>
<span class="font-mono text-on-surface">1020</span>
<span class="text-outline">· BDO Operating</span>
</div>
</td>
<!-- OR / Ref -->
<td class="px-space-sm py-3 font-mono text-on-surface whitespace-nowrap">OR-00913</td>
<!-- Method -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="px-2 py-0.5 rounded bg-surface-container-high text-on-surface font-label-sm text-label-sm">Bank Transfer</span>
</td>
<!-- Entry Type -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="px-2 py-0.5 rounded-full bg-surface-container-high text-secondary-fixed font-label-sm text-label-sm font-medium">Settlement</span>
</td>
<!-- BIR Compliance -->
<td class="px-space-sm py-3 whitespace-nowrap">
<span class="inline-flex items-center gap-1 text-primary-fixed font-label-sm text-label-sm">
<span class="material-symbols-outlined text-[14px]">check_circle</span>
<span>Posted (demo)</span>
</span>
</td>
<!-- Row Actions -->
<td class="px-space-sm py-3 text-right pr-space-md whitespace-nowrap">
<div class="inline-flex items-center gap-1 opacity-70 group-hover:opacity-100 transition-opacity">
<button class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface" title="View / Edit" type="button" data-collection-action="edit">
<span class="material-symbols-outlined text-[16px]">edit</span>
</button>
<button class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface" title="Duplicate row" type="button" data-collection-action="duplicate">
<span class="material-symbols-outlined text-[16px]">content_copy</span>
</button>
<button class="p-1 rounded hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface" title="More options" type="button" data-collection-action="more">
<span class="material-symbols-outlined text-[16px]">more_vert</span>
</button>
</div>
</td>
</tr>
<!-- ROW 3 (DRAFT / ACTIVE INTERACTIVE ROW WITH BIOLUMINESCENT FOCUS) -->
<tr class="bg-surface-container-high/60 shadow-[inset_0_0_0_2px_rgba(0,216,246,0.35)] relative z-10 transition-colors">
<!-- Checkbox (Sticky) -->
<td class="w-12 px-space-md py-2.5 text-center sticky left-0 bg-surface-container-high z-20">
<input aria-label="Select Draft Row" checked="" class="w-4 h-4 rounded bg-surface-container-low text-secondary-container accent-secondary cursor-pointer" type="checkbox"/>
</td>
<!-- Status Pill (Draft / New) -->
<td class="px-space-sm py-2.5 whitespace-nowrap">
<span class="draft-status-badge">Drafting</span>
</td>
<!-- Date Input -->
<td class="px-space-sm py-2.5 whitespace-nowrap">
<input class="bg-surface-container-lowest/80 text-on-surface font-mono text-label-sm px-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim" type="date" value="2026-10-20"/>
</td>
<!-- Txn No -->
<td class="px-space-sm py-2.5 font-mono text-outline whitespace-nowrap">
<span class="text-xs italic">CR-000003 (Auto)</span>
</td>
<!-- Customer Dropdown -->
<td class="px-space-sm py-2.5 min-w-[240px]">
<div class="relative">
<select class="w-full bg-surface-container-lowest/90 text-on-surface font-body-sm px-2.5 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim cursor-pointer">
<option selected="" value="">Acme Solutions Phil. Inc.</option>
<option value="1">VARIOUS CUSTOMERS</option>
<option value="2">Cruz Dental Clinic Corp.</option>
<option value="3">+ Add New Customer...</option>
</select>
</div>
</td>
<!-- Source SI Dropdown / Input -->
<td class="px-space-sm py-2.5 min-w-[200px]">
<select class="w-full bg-surface-container-lowest/90 text-on-surface font-mono text-body-sm px-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim cursor-pointer">
<option selected="">SI-000003 — ₱25,400 open</option>
<option>SI-000004 — ₱14,250 open</option>
<option>Unlinked / Advance Deposit</option>
</select>
</td>
<!-- Amount Applied Field -->
<td class="px-space-sm py-2.5 text-right whitespace-nowrap">
<div class="relative inline-block w-32">
<span class="absolute left-2 top-1/2 -translate-y-1/2 font-mono text-secondary-fixed font-bold text-xs">₱</span>
<input class="w-full bg-surface-container-lowest text-right font-mono font-bold text-secondary-fixed pl-5 pr-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim text-body-sm" type="text" value="25,400.00"/>
</div>
</td>
<!-- Cash / Bank Dropdown -->
<td class="px-space-sm py-2.5 min-w-[200px]">
<select class="w-full bg-surface-container-lowest/90 text-on-surface font-mono text-body-sm px-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim cursor-pointer">
<option selected="">1020 · BDO Operating</option>
<option>1010 · Cash on Hand</option>
<option>1030 · BPI Payroll Checking</option>
<option>1040 · GCash Merchant Wallet</option>
</select>
</td>
<!-- OR / Ref Input -->
<td class="px-space-sm py-2.5 whitespace-nowrap">
<input class="w-28 bg-surface-container-lowest/90 text-on-surface font-mono text-body-sm px-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim" placeholder="Enter OR..." type="text" value="OR-00914"/>
</td>
<!-- Payment Method Dropdown -->
<td class="px-space-sm py-2.5 whitespace-nowrap">
<select class="bg-surface-container-lowest/90 text-on-surface font-body-sm px-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim cursor-pointer">
<option selected="">Bank Transfer</option>
<option>Cash</option>
<option>Check</option>
<option>GCash / E-Wallet</option>
</select>
</td>
<!-- Entry Type -->
<td class="px-space-sm py-2.5 whitespace-nowrap">
<select class="bg-surface-container-lowest/90 text-on-surface font-body-sm px-2 py-1 rounded border-none focus:outline-none focus:ring-1 focus:ring-secondary-fixed-dim cursor-pointer">
<option selected="">Settlement</option>
<option>Advance</option>
<option>Downpayment</option>
</select>
</td>
<!-- BIR Compliance Pending -->
<td class="px-space-sm py-2.5 whitespace-nowrap">
<span class="inline-flex items-center gap-1 text-secondary-fixed-dim font-label-sm text-label-sm">
<span class="material-symbols-outlined text-[14px]">pending</span>
<span>Check before posting</span>
</span>
</td>
<!-- Draft Actions -->
<td class="px-space-sm py-2.5 text-right pr-space-md whitespace-nowrap">
<div class="inline-flex items-center gap-1">
<button class="p-1 px-2 rounded bg-primary-container text-on-primary-container font-headline-sm text-label-sm font-semibold hover:bg-primary-fixed shadow-[0_0_10px_rgba(0,245,160,0.3)] transition-all" title="Post now" type="button" data-collection-action="post-row">
                    Post
                  </button>
<button class="p-1 rounded hover:bg-surface-container text-error hover:text-error-container" title="Discard draft" type="button" data-collection-action="discard">
<span class="material-symbols-outlined text-[16px]">delete</span>
</button>
</div>
</td>
</tr>
<!-- ROW 4: INLINE ADD ROW ACTION -->
<tr class="hover:bg-surface-container-high/30 transition-colors cursor-pointer border-t border-surface-container-highest/30">
<td class="px-space-md py-2.5" colspan="13">
<button class="inline-flex items-center gap-2 text-primary-fixed font-headline-sm text-body-sm font-semibold hover:text-primary-container transition-colors" type="button" data-collection-action="add">
<span class="material-symbols-outlined text-[18px]">add_circle</span>
<span>Click to add another collection line (or press Enter)</span>
</button>
</td>
</tr>
</tbody>
</table>
</div>
</div>
</div>
<section id="collection-summary" class="collection-summary mx-gutter mt-space-md" aria-label="Collection totals">
<div><h2 class="font-headline-sm text-body-md">Collection summary</h2><p class="text-body-sm text-on-surface-variant" id="collection-counts"></p></div>
<div><span>Total received (demo)</span><strong id="collection-total"></strong></div><div><span>Applied to invoices (demo)</span><strong id="collection-applied"></strong></div><div><span>Unapplied / advance (demo)</span><strong id="collection-advance"></strong></div>
<p class="text-label-sm text-on-surface-variant">Tab: next field · Enter: next field / new row · Ctrl/⌘+K: search · Ctrl/⌘+P: simulate posting · Delete: void selected</p>
</section></div></div></main></div>
<button type="button" id="sales-menu-toggle" aria-label="Toggle workspace navigation" aria-controls="sales-sidebar" aria-expanded="false" class="sales-mobile-toggle"><span class="material-symbols-outlined">menu</span></button><button type="button" id="sales-backdrop" aria-label="Close workspace navigation" hidden></button>
<dialog id="collections-info" class="sales-dialog rounded-2xl border border-outline-variant bg-surface-container-low p-6 text-on-surface" aria-labelledby="collection-dialog-title"><form method="dialog" class="flex justify-end"><button type="submit" aria-label="Close collection dialog">×</button></form><h2 id="collection-dialog-title" class="font-headline-sm text-headline-sm"></h2><p id="collection-dialog-text" class="mt-3 text-body-md whitespace-pre-line"></p><div id="collection-dialog-actions" class="flex gap-3 mt-4"></div></dialog>
@include('collection-entry')
<script type="application/json" id="workspace-ledger-config" data-key="{{ $company['browser_key'] ?? hash('sha256', session()->getId().'|'.$company['company_name'].'|'.($company['tin'] ?? '')) }}">{"period":"{{ now('Asia/Manila')->format('Y-m') }}"}</script></body></html>
