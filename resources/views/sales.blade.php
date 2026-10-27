<!DOCTYPE html>

<html class="dark" lang="en"><head><script src="{{ asset('js/sheet-posting.js') }}?v={{ filemtime(public_path('js/sheet-posting.js')) }}" defer></script>@include('working-period-assets')<meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/><meta content="web_dashboard" name="shell-type"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&amp;family=Manrope:wght@400;500;600&amp;family=Space+Grotesk:wght@600;700&amp;display=swap" rel="stylesheet"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><script src="https://cdn.tailwindcss.com"></script><script src="{{ asset('js/sales-theme.js') }}"></script><script src="{{ asset('js/sales-config.js') }}"></script><link rel="stylesheet" href="{{ asset('css/sales.css') }}?v={{ filemtime(public_path('css/sales.css')) }}"><script src="{{ route('masters.accounts.catalog', ['_company_id' => $company['company_id'] ?? null]) }}"></script><script src="{{ asset('js/sheet-masters.js') }}?v={{ filemtime(public_path('js/sheet-masters.js')) }}" defer></script><script src="{{ asset('js/sheet-master-picker.js') }}?v={{ filemtime(public_path('js/sheet-master-picker.js')) }}" defer></script><script src="{{ asset('js/workspace-ledger.js') }}?v={{ filemtime(public_path('js/workspace-ledger.js')) }}" defer></script><script src="{{ asset('js/draft-leave-guard.js') }}?v={{ filemtime(public_path('js/draft-leave-guard.js')) }}" defer></script><script src="{{ asset('js/sales.js') }}?v={{ filemtime(public_path('js/sales.js')) }}" defer></script><title>Sales · {{ $company['company_name'] }} · kontadurya.io</title><link rel="stylesheet" href="{{ asset('css/sheet-navigation.css') }}?v={{ filemtime(public_path('css/sheet-navigation.css')) }}"></head><body class="bg-background dark:bg-background text-on-surface dark:text-on-surface antialiased font-body-md theme-transition selection:bg-primary-container selection:text-on-primary-fixed"><aside id="sales-sidebar" aria-label="Workspace navigation" class="fixed left-0 top-0 h-full w-72 bg-white dark:bg-surface-container-low/90 border-r border-slate-200 dark:border-surface-container-highest/40 backdrop-blur-2xl z-50 flex flex-col justify-between shadow-sm dark:shadow-[0_1px_8px_rgba(0,0,0,0.04)] theme-transition"><div class="flex flex-col flex-1 overflow-y-auto"><div class="h-16 px-space-lg flex items-center justify-between border-b border-slate-100 dark:border-transparent"><div class="flex items-center gap-space-sm"><div class="w-8 h-8 rounded-lg bg-emerald-500 dark:bg-primary-container flex items-center justify-center shadow-md dark:shadow-[0_0_16px_rgba(0,245,160,0.35)]"><span class="material-symbols-outlined text-white dark:text-on-primary-fixed text-[20px]">account_balance</span></div><div class="flex flex-col"><span class="font-headline-sm text-headline-sm text-slate-900 dark:text-on-surface tracking-tight leading-none">kontadurya<span class="text-emerald-600 dark:text-primary-container">.io</span></span><span class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant tracking-wider uppercase mt-1">Ledger Core</span></div></div></div><nav class="px-space-md py-space-sm space-y-space-xs" data-active-classes="bg-primary-container text-on-primary-container font-headline-sm rounded-lg"><div class="px-space-sm py-space-xs"><span class="font-label-sm text-label-sm uppercase tracking-wider text-slate-400 dark:text-on-surface-variant/70">Main Ledger</span></div><a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="home" href="{{ route('dashboard') }}"><span class="material-symbols-outlined text-[18px]">home</span><span>Home</span></a><a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="my-work" href="{{ route('my-work') }}"><span class="material-symbols-outlined text-[18px]">task_alt</span><span>My work</span></a><a aria-current="page" class="flex items-center gap-space-sm px-space-sm py-2 transition-all bg-emerald-500 text-white dark:bg-primary-container dark:text-on-primary-container font-headline-sm rounded-lg shadow-sm" data-path="sheets" href="{{ route('sheets.sales') }}"><span class="material-symbols-outlined text-[18px]">table_chart</span><span>Sheets</span></a><a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="books" href="{{ route('books') }}"><span class="material-symbols-outlined text-[18px]">menu_book</span><span>Books</span></a>@if($company['sheet_settings']['ppe'] ?? true)<a class="flex items-center gap-space-sm px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-md text-body-md" data-path="lapsing-ppe" href="{{ route('lapsing') }}"><span class="material-symbols-outlined text-[18px]">domain</span><span>Lapsing (PPE)</span></a>@endif<details class="group pt-space-xs" open=""><summary class="flex items-center justify-between px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface cursor-pointer transition-all list-none"><div class="flex items-center gap-space-sm font-label-sm text-label-sm uppercase tracking-wider"><span class="material-symbols-outlined text-[16px]">receipt_long</span><span>Accounting</span></div><span class="material-symbols-outlined text-[16px] transition-transform group-open:rotate-180">expand_more</span></summary><div class="pl-space-lg pr-space-xs py-space-xs space-y-1"><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="masters" href="{{ route('masters.accounts') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">account_tree</span><span>Masters</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="financial-reports" href="{{ route('reports') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">assessment</span><span>Financial Reports</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="tax-reports" href="{{ route('tax-reports') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">receipt_long</span><span>Tax Reports</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="tax-returns" href="#sales-dialog" data-sales-info="Tax Returns"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">assignment_turned_in</span><span>Tax Returns</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="tax-summary" href="#sales-dialog" data-sales-info="Tax Summary"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">query_stats</span><span>Tax Summary</span></a></div></details><details class="group pt-space-xs" open=""><summary class="flex items-center justify-between px-space-sm py-2 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface cursor-pointer transition-all list-none"><div class="flex items-center gap-space-sm font-label-sm text-label-sm uppercase tracking-wider"><span class="material-symbols-outlined text-[16px]">shield_person</span><span>Admin</span></div><span class="material-symbols-outlined text-[16px] transition-transform group-open:rotate-180">expand_more</span></summary><div class="pl-space-lg pr-space-xs py-space-xs space-y-1"><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="users-and-access" href="{{ route('team.entry') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">shield_person</span><span>Users &amp; access</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="settings" href="{{ route('settings') }}"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">settings</span><span>Settings</span></a><a class="flex items-center gap-space-sm px-space-sm py-1.5 rounded-lg text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all font-body-sm text-body-sm" data-path="clients" href="#sales-dialog" data-sales-info="Clients"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">storefront</span><span>Clients</span></a></div></details></nav></div><div class="p-space-md"><div class="bg-slate-100 dark:bg-surface-container-lowest/80 border border-slate-200 dark:border-transparent rounded-xl p-space-sm shadow-sm dark:shadow-[0_1px_8px_rgba(0,0,0,0.04)]"><div class="flex items-center gap-space-xs mb-1"><span class="w-2 h-2 rounded-full bg-emerald-500 dark:bg-primary-container shadow-[0_0_8px_rgba(0,245,160,0.8)]"></span><span class="font-label-sm text-label-sm text-emerald-600 dark:text-primary-container uppercase tracking-wide font-semibold">Local sales preview</span></div><p class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant">Sample data · No BIR connection</p></div></div></aside><div id="sales-shell" class="pl-72"><header id="sales-header" class="fixed top-0 left-72 right-0 h-16 bg-white/80 dark:bg-surface/80 backdrop-blur-xl border-b border-slate-200 dark:border-surface-container-highest/40 shadow-sm dark:shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-40 px-gutter flex items-center justify-between gap-space-md theme-transition"><div class="flex items-center gap-space-md flex-1"><button data-sales-action="add" class="flex items-center gap-space-xs bg-emerald-500 text-white dark:bg-primary-container dark:text-on-primary-fixed px-space-md py-2 rounded-lg font-label-md text-label-md uppercase tracking-wider hover:bg-emerald-600 dark:hover:bg-primary-fixed dark:hover:text-on-primary-fixed transition-all shadow-sm dark:shadow-[0_0_16px_rgba(0,245,160,0.25)] font-semibold" type="button"><span class="material-symbols-outlined text-[16px]">add</span><span>Create</span></button><div class="relative flex-1 max-w-md"><span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-on-surface-variant text-[18px]">search</span><input class="w-full bg-slate-100 dark:bg-surface-container-lowest/70 text-slate-900 dark:text-on-surface placeholder:text-slate-400 dark:placeholder:text-on-surface-variant pl-10 pr-12 py-2 rounded-lg font-body-sm text-body-sm outline-none focus:bg-white dark:focus:bg-surface-container-high border border-transparent focus:border-slate-300 dark:focus:border-transparent transition-all" id="sales-global-search" aria-label="Search sales" placeholder="Search this sales sheet..." type="text"/><span class="absolute right-2.5 top-1/2 -translate-y-1/2 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant px-1.5 py-0.5 rounded bg-slate-200 dark:bg-surface-container-high">⌘K</span></div><div class="flex items-center gap-space-xs bg-slate-100 dark:bg-surface-container-low border border-slate-200/80 dark:border-transparent px-space-sm py-1.5 rounded-lg cursor-pointer hover:bg-slate-200/60 dark:hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[18px] text-sky-600 dark:text-secondary">corporate_fare</span><span class="font-label-sm text-label-sm text-slate-800 dark:text-on-surface uppercase font-medium">{{ $company['company_name'] }}</span><span class="material-symbols-outlined text-[16px] text-slate-400 dark:text-on-surface-variant">arrow_drop_down</span></div></div><div class="flex items-center gap-space-md"><div class="hidden lg:flex items-center gap-space-xs px-2.5 py-1 rounded-full bg-slate-100 dark:bg-surface-container border border-slate-200 dark:border-transparent"><span class="w-1.5 h-1.5 rounded-full bg-sky-500 dark:bg-secondary-fixed-dim"></span><span class="font-label-sm text-label-sm text-sky-700 dark:text-secondary font-medium">Free Core · Unlimited</span></div><div class="flex items-center gap-space-xs"><button aria-label="Toggle dark/light mode" class="w-9 h-9 rounded-lg flex items-center justify-center text-slate-600 dark:text-on-surface-variant hover:bg-slate-100 dark:hover:bg-surface-container-high hover:text-slate-900 dark:hover:text-on-surface transition-all border border-slate-200 dark:border-surface-container-highest/60" id="theme-toggle-btn" title="Toggle Dark/Light Mode" type="button"><span class="material-symbols-outlined text-[20px] transition-transform duration-300" id="theme-icon">light_mode</span></button></div><div class="flex items-center gap-space-sm pl-space-xs border-l border-slate-200 dark:border-surface-container-highest/50"><div class="flex flex-col text-right hidden sm:flex"><span class="font-body-sm text-body-sm text-slate-900 dark:text-on-surface font-semibold leading-tight">{{ $company['full_name'] }}</span><span class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant">Demo workspace</span></div><div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-800 dark:bg-primary dark:text-on-primary flex items-center justify-center shadow-sm dark:shadow-[0_0_12px_rgba(0,245,160,0.2)] font-semibold"><span class="material-symbols-outlined text-[18px]">person</span></div></div></div></header><main id="sales-main" data-period="{{ now('Asia/Manila')->format('Y-m') }}" class="relative pt-16 w-full bg-slate-50 dark:bg-surface min-h-screen theme-transition"><div class="flex flex-col w-full">
<!-- Spatial Glow Underlays -->
<div class="sheet-sales-layout relative w-full overflow-hidden">
<div class="pointer-events-none absolute -top-24 -left-20 w-96 h-96 rounded-full bg-emerald-500/5 dark:bg-primary-container/5 blur-3xl"></div>
<div class="pointer-events-none absolute top-1/3 -right-24 w-[32rem] h-[32rem] rounded-full bg-sky-500/5 dark:bg-secondary-fixed-dim/5 blur-3xl"></div>
@include('sheet-heading', ['sheet' => 'sales'])
@include('sheet-tabs', ['sheet' => 'sales'])
<!-- Interactive Grid Action Toolbar -->
@include('sheet-toolbar', ['sheet' => 'sales'])
<div class="mt-4 rounded-xl border border-secondary/20 bg-secondary/5 p-3 text-body-sm text-on-surface-variant">Local sales preview · Records are shared with Collections in this browser tab and survive reloads. Posted cash sales create collections automatically; on-account sales remain unpaid. Nothing is posted to a real bank or BIR ledger.</div>
<p id="sales-status" role="status" aria-live="polite" class="mt-3 text-body-sm text-primary-container"></p>
<div id="sales-filter-panel" hidden class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-surface-container-low p-3">
<label for="sales-status-filter" class="text-body-sm">Status</label><select id="sales-status-filter" class="rounded-lg bg-surface-container-high px-3 py-2 text-body-sm"><option value="all">Drafts &amp; Posted</option><option value="draft">Drafts</option><option value="posted">Posted samples</option><option value="void">Voided</option></select>
</div>
<!-- Main Spreadsheet Grid Container -->
<div class="mt-space-sm bg-white dark:bg-surface-container-low/90 border border-slate-200 dark:border-transparent backdrop-blur-2xl rounded-2xl shadow-sm dark:shadow-2xl overflow-hidden flex flex-col theme-transition">
<!-- Scrollable Data Grid -->
<div id="sales-grid-scroll" tabindex="0" aria-label="Scrollable sales grid" class="overflow-x-auto overflow-y-auto max-h-[640px] relative">
<table data-cwt-url="{{ route('sheets.cwt') }}" id="sales-table" aria-label="Sales transactions" class="w-full text-left border-collapse select-text min-w-[1720px] no-freeze">
<!-- Grid Header with Sticky Behavior -->
<thead class="sticky top-0 z-30 bg-slate-100/95 dark:bg-surface-container-high/95 backdrop-blur-xl shadow-sm theme-transition">
<tr class="font-label-sm text-label-sm uppercase tracking-wider text-slate-600 dark:text-on-surface-variant/90 border-b border-slate-200 dark:border-surface-container-highest">
<!-- Sticky Checkbox Column -->
<th class="sticky left-0 z-40 bg-slate-100/95 dark:bg-surface-container-high/95 backdrop-blur-xl w-12 px-3 py-3 text-center border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<input class="w-4 h-4 rounded bg-white dark:bg-surface-container-lowest border-slate-300 dark:border-outline-variant text-emerald-600 dark:text-primary-container focus:ring-0 focus:ring-offset-0 cursor-pointer accent-emerald-600 dark:accent-primary-container" type="checkbox"/>
</th>
<!-- Sticky Status & Date Column -->
<th class="sticky left-12 z-40 bg-slate-100/95 dark:bg-surface-container-high/95 backdrop-blur-xl w-28 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<div class="flex items-center justify-between">
<span>Status</span>
<span class="material-symbols-outlined text-[12px] opacity-40">drag_handle</span>
</div>
</th>
<th class="w-32 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<div class="flex items-center justify-between">
<span>Date</span>
<span class="material-symbols-outlined text-[12px] opacity-40">unfold_more</span>
</div>
</th>
<th class="w-32 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<div class="flex items-center justify-between">
<span>Txn No.</span>
<span class="material-symbols-outlined text-[12px] opacity-40">unfold_more</span>
</div>
</th>
<th class="w-64 px-4 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<div class="flex items-center justify-between">
<span>Customer Name</span>
<span class="material-symbols-outlined text-[12px] opacity-40">drag_handle</span>
</div>
</th>
<th class="w-36 px-4 py-3 text-right border-r border-slate-200 dark:border-surface-container-highest bg-slate-200/30 dark:bg-surface-container-highest/20" scope="col">
<div class="flex items-center justify-end gap-1">
<span class="text-emerald-600 dark:text-primary-container">Gross Amount</span>
<span class="material-symbols-outlined text-[14px] text-emerald-600 dark:text-primary-container">arrow_downward</span>
</div>
</th>
<th class="w-28 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>Tax Type</span>
</th>
<th class="w-56 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>Revenue Account (COA)</span>
</th>
<th class="w-36 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>CWT IT (2307)</span>
</th>
<th class="w-28 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>CWT VT</span>
</th>
<th class="w-28 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>Terms</span>
</th>
<th class="w-32 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>SI / OR No.</span>
</th>
<th class="w-36 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>Classification</span>
</th>
<th class="w-40 px-3 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>Deposit Account</span>
</th>
<th class="w-72 px-4 py-3 border-r border-slate-200 dark:border-surface-container-highest" scope="col">
<span>Line Description</span>
</th>
<th class="sticky right-0 z-30 bg-slate-100/95 dark:bg-surface-container-high/95 backdrop-blur-xl w-28 px-3 py-3 text-center" scope="col">
<span>Actions</span>
</th>
</tr>
</thead>
<tbody id="sales-rows" class="font-body-md text-body-md text-slate-800 dark:text-on-surface divide-y divide-slate-200 dark:divide-surface-container-high/40 theme-transition">
<!-- Row 1: Posted Cash Sale -->
<tr class="hover:bg-slate-50 dark:hover:bg-surface-container/70 transition-colors group">
<!-- Sticky Checkbox -->
<td class="sticky left-0 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2.5 text-center border-r border-slate-200 dark:border-surface-container-highest/60">
<input class="w-4 h-4 rounded bg-white dark:bg-surface-container-lowest border-slate-300 dark:border-outline-variant text-emerald-600 dark:text-primary-container focus:ring-0 accent-emerald-600 dark:accent-primary-container" type="checkbox"/>
</td>
<!-- Sticky Status -->
<td class="sticky left-12 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/60">
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-primary-container/15 dark:text-primary-container font-label-sm text-label-sm font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-primary-container"></span>
                  Posted
                </span>
</td>
<td class="px-3 py-2.5 font-label-md text-label-md text-slate-600 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
                2026-10-16
              </td>
<td class="px-3 py-2.5 font-label-md text-label-md font-medium text-sky-700 dark:text-secondary border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
                SI-000001
              </td>
<td class="px-4 py-2.5 font-headline-sm text-headline-sm tracking-normal text-slate-900 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
<div class="flex items-center gap-2">
<span class="w-5 h-5 rounded-md bg-slate-200 dark:bg-surface-container-highest text-slate-700 dark:text-on-surface flex items-center justify-center font-label-sm text-[10px] font-bold">VC</span>
<span class="truncate">VARIOUS CUSTOMERS</span>
</div>
</td>
<td class="px-4 py-2.5 font-label-md text-label-md text-right text-emerald-700 dark:text-primary-container font-semibold border-r border-slate-200 dark:border-surface-container-highest/40 bg-emerald-50/40 dark:bg-primary-container/5 whitespace-nowrap">
                ₱500.00
              </td>
<td class="px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-surface-variant dark:text-secondary font-label-sm text-label-sm font-semibold border border-slate-200 dark:border-transparent">12% VAT</span>
</td>
<td class="px-3 py-2.5 font-body-sm text-body-sm border-r border-slate-200 dark:border-surface-container-highest/40">
<span class="text-slate-900 dark:text-on-surface font-medium">4020</span> · <span class="text-slate-500 dark:text-on-surface-variant">Sales - Services</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-surface-container-highest dark:text-on-surface border border-slate-200 dark:border-transparent">WC160 · 2%</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40">
                None
              </td>
<td class="px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-primary-container/10 dark:text-primary-container font-label-sm text-label-sm font-medium">Cash</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40">
                OR-99120
              </td>
<td class="px-3 py-2.5 font-body-sm text-body-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
                Regular
              </td>
<td class="px-3 py-2.5 font-body-sm text-body-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
                1010 · Cash on Hand
              </td>
<td class="px-4 py-2.5 font-body-sm text-body-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40 truncate max-w-xs">
                Walk-in retail diagnostic consultations
              </td>
<!-- Sticky Row Actions -->
<td class="sticky right-0 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2 text-center whitespace-nowrap shadow-[-4px_0_6px_rgba(0,0,0,0.03)] dark:shadow-[-8px_0_12px_rgba(0,0,0,0.2)]">
<div class="flex items-center justify-center gap-1 text-slate-500 dark:text-on-surface-variant">
<button data-sales-action="2307" class="p-1 rounded hover:text-emerald-600 hover:bg-slate-100 dark:hover:text-primary-container dark:hover:bg-surface-container-highest" title="Record 2307 Form" type="button">
<span class="material-symbols-outlined text-[16px]">description</span>
</button>
<button data-sales-action="duplicate" class="p-1 rounded hover:text-sky-600 hover:bg-slate-100 dark:hover:text-secondary dark:hover:bg-surface-container-highest" title="Duplicate row" type="button">
<span class="material-symbols-outlined text-[16px]">content_copy</span>
</button>
<button data-sales-action="more" class="p-1 rounded hover:text-slate-800 hover:bg-slate-100 dark:hover:text-on-surface dark:hover:bg-surface-container-highest" title="More options" type="button">
<span class="material-symbols-outlined text-[16px]">more_vert</span>
</button>
</div>
</td>
</tr>
<!-- Row 2: Posted VAT Credit Sale (Cruz Dental) with net breakdown -->
<tr class="hover:bg-slate-50 dark:hover:bg-surface-container/70 transition-colors group">
<!-- Sticky Checkbox -->
<td class="sticky left-0 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2.5 text-center border-r border-slate-200 dark:border-surface-container-highest/60">
<input class="w-4 h-4 rounded bg-white dark:bg-surface-container-lowest border-slate-300 dark:border-outline-variant text-emerald-600 dark:text-primary-container focus:ring-0 accent-emerald-600 dark:accent-primary-container" type="checkbox"/>
</td>
<!-- Sticky Status -->
<td class="sticky left-12 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/60">
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-primary-container/15 dark:text-primary-container font-label-sm text-label-sm font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-primary-container"></span>
                  Posted
                </span>
</td>
<td class="px-3 py-2.5 font-label-md text-label-md text-slate-600 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
                2026-10-18
              </td>
<td class="px-3 py-2.5 font-label-md text-label-md font-medium text-sky-700 dark:text-secondary border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
                SI-000002
              </td>
<td class="px-4 py-2.5 font-headline-sm text-headline-sm tracking-normal text-slate-900 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
<div class="flex items-center gap-2">
<span class="w-5 h-5 rounded-md bg-sky-100 text-sky-800 dark:bg-secondary/20 dark:text-secondary flex items-center justify-center font-label-sm text-[10px] font-bold">CD</span>
<span class="truncate font-semibold">Cruz Dental Clinic Corp.</span>
</div>
</td>
<td class="px-4 py-2.5 font-label-md text-label-md text-right text-emerald-700 dark:text-primary-container font-semibold border-r border-slate-200 dark:border-surface-container-highest/40 bg-emerald-50/40 dark:bg-primary-container/5 whitespace-nowrap">
<div>₱10,000.00</div>
<div class="font-label-sm text-[10px] text-slate-500 dark:text-on-surface-variant font-normal">Net: ₱8,928.57</div>
</td>
<td class="px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-surface-variant dark:text-secondary font-label-sm text-label-sm font-semibold border border-slate-200 dark:border-transparent">12% VAT</span>
<div class="font-label-sm text-[10px] text-sky-700 dark:text-secondary mt-0.5 font-medium">VAT: ₱1,071.43</div>
</td>
<td class="px-3 py-2.5 font-body-sm text-body-sm border-r border-slate-200 dark:border-surface-container-highest/40">
<span class="text-slate-900 dark:text-on-surface font-medium">4020</span> · <span class="text-slate-500 dark:text-on-surface-variant">Sales - Services</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-1.5 py-0.5 rounded bg-slate-100 text-sky-800 dark:bg-surface-container-highest dark:text-secondary font-medium border border-slate-200 dark:border-transparent">WC160 · 2% (₱178.57)</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40">
                None
              </td>
<td class="px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 dark:bg-surface-container-highest dark:text-on-surface font-label-sm text-label-sm font-medium">On Account</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40">
                INV-2026-089
              </td>
<td class="px-3 py-2.5 font-body-sm text-body-sm text-sky-700 dark:text-secondary border-r border-slate-200 dark:border-surface-container-highest/40 font-medium">
                Top Withholding Agent
              </td>
<td class="px-3 py-2.5 font-body-sm text-body-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
                1030 · BDO Checking
              </td>
<td class="px-4 py-2.5 font-body-sm text-body-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40 truncate max-w-xs">
                Monthly Brand Advisory Retainer &amp; SEO Management
              </td>
<!-- Sticky Row Actions -->
<td class="sticky right-0 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2 text-center whitespace-nowrap shadow-[-4px_0_6px_rgba(0,0,0,0.03)] dark:shadow-[-8px_0_12px_rgba(0,0,0,0.2)]">
<div class="flex items-center justify-center gap-1 text-slate-500 dark:text-on-surface-variant">
<button class="p-1 rounded text-emerald-600 bg-emerald-50 hover:bg-emerald-100 dark:text-primary-container dark:bg-primary-container/10 dark:hover:bg-primary-container/20" data-sales-action="2307" title="2307 Form Attached" type="button">
<span class="material-symbols-outlined text-[16px]">verified</span>
</button>
<button data-sales-action="duplicate" class="p-1 rounded hover:text-sky-600 hover:bg-slate-100 dark:hover:text-secondary dark:hover:bg-surface-container-highest" title="Duplicate row" type="button">
<span class="material-symbols-outlined text-[16px]">content_copy</span>
</button>
<button data-sales-action="more" class="p-1 rounded hover:text-slate-800 hover:bg-slate-100 dark:hover:text-on-surface dark:hover:bg-surface-container-highest" title="More options" type="button">
<span class="material-symbols-outlined text-[16px]">more_vert</span>
</button>
</div>
</td>
</tr>
<!-- Row 3: Posted High-Ticket Goods Sale (Santos Trading) -->
<tr class="hover:bg-slate-50 dark:hover:bg-surface-container/70 transition-colors group">
<!-- Sticky Checkbox -->
<td class="sticky left-0 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2.5 text-center border-r border-slate-200 dark:border-surface-container-highest/60">
<input class="w-4 h-4 rounded bg-white dark:bg-surface-container-lowest border-slate-300 dark:border-outline-variant text-emerald-600 dark:text-primary-container focus:ring-0 accent-emerald-600 dark:accent-primary-container" type="checkbox"/>
</td>
<!-- Sticky Status -->
<td class="sticky left-12 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/60">
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-primary-container/15 dark:text-primary-container font-label-sm text-label-sm font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-primary-container"></span>
                  Posted
                </span>
</td>
<td class="px-3 py-2.5 font-label-md text-label-md text-slate-600 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
                2026-10-21
              </td>
<td class="px-3 py-2.5 font-label-md text-label-md font-medium text-sky-700 dark:text-secondary border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
                SI-000003
              </td>
<td class="px-4 py-2.5 font-headline-sm text-headline-sm tracking-normal text-slate-900 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
<div class="flex items-center gap-2">
<span class="w-5 h-5 rounded-md bg-emerald-100 text-emerald-800 dark:bg-surface-container-highest dark:text-primary-container flex items-center justify-center font-label-sm text-[10px] font-bold">ST</span>
<span class="truncate font-semibold">Santos Trading Enterprises</span>
</div>
</td>
<td class="px-4 py-2.5 font-label-md text-label-md text-right text-emerald-700 dark:text-primary-container font-semibold border-r border-slate-200 dark:border-surface-container-highest/40 bg-emerald-50/40 dark:bg-primary-container/5 whitespace-nowrap">
<div>₱25,000.00</div>
<div class="font-label-sm text-[10px] text-slate-500 dark:text-on-surface-variant font-normal">Net: ₱22,321.43</div>
</td>
<td class="px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-surface-variant dark:text-secondary font-label-sm text-label-sm font-semibold border border-slate-200 dark:border-transparent">12% VAT</span>
<div class="font-label-sm text-[10px] text-sky-700 dark:text-secondary mt-0.5 font-medium">VAT: ₱2,678.57</div>
</td>
<td class="px-3 py-2.5 font-body-sm text-body-sm border-r border-slate-200 dark:border-surface-container-highest/40">
<span class="text-slate-900 dark:text-on-surface font-medium">4000</span> · <span class="text-slate-500 dark:text-on-surface-variant">Merchandise Sales</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-surface-container-highest dark:text-on-surface font-medium border border-slate-200 dark:border-transparent">WC100 · 1% (₱223.21)</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40">
                None
              </td>
<td class="px-3 py-2.5 border-r border-slate-200 dark:border-surface-container-highest/40 whitespace-nowrap">
<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-primary-container/10 dark:text-primary-container font-label-sm text-label-sm font-medium">Cash</span>
</td>
<td class="px-3 py-2.5 font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40">
                OR-99121
              </td>
<td class="px-3 py-2.5 font-body-sm text-body-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
                Regular
              </td>
<td class="px-3 py-2.5 font-body-sm text-body-sm text-slate-700 dark:text-on-surface border-r border-slate-200 dark:border-surface-container-highest/40">
                1010 · Cash on Hand
              </td>
<td class="px-4 py-2.5 font-body-sm text-body-sm text-slate-500 dark:text-on-surface-variant border-r border-slate-200 dark:border-surface-container-highest/40 truncate max-w-xs">
                Batch fulfillment of seasonal promotional collateral
              </td>
<!-- Sticky Row Actions -->
<td class="sticky right-0 z-20 bg-white dark:bg-surface-container-low group-hover:bg-slate-50 dark:group-hover:bg-surface-container transition-colors px-3 py-2 text-center whitespace-nowrap shadow-[-4px_0_6px_rgba(0,0,0,0.03)] dark:shadow-[-8px_0_12px_rgba(0,0,0,0.2)]">
<div class="flex items-center justify-center gap-1 text-slate-500 dark:text-on-surface-variant">
<button data-sales-action="2307" class="p-1 rounded hover:text-emerald-600 hover:bg-slate-100 dark:hover:text-primary-container dark:hover:bg-surface-container-highest" title="Record 2307 Form" type="button">
<span class="material-symbols-outlined text-[16px]">description</span>
</button>
<button data-sales-action="duplicate" class="p-1 rounded hover:text-sky-600 hover:bg-slate-100 dark:hover:text-secondary dark:hover:bg-surface-container-highest" title="Duplicate row" type="button">
<span class="material-symbols-outlined text-[16px]">content_copy</span>
</button>
<button data-sales-action="more" class="p-1 rounded hover:text-slate-800 hover:bg-slate-100 dark:hover:text-on-surface dark:hover:bg-surface-container-highest" title="More options" type="button">
<span class="material-symbols-outlined text-[16px]">more_vert</span>
</button>
</div>
</td>
</tr>
<!-- Row 4: ACTIVE INLINE EDITABLE / NEW DRAFT ROW -->
<tr id="sales-draft" hidden class="bg-sky-50/60 dark:bg-surface-container-high/60 transition-colors shadow-inner border-y border-sky-300 dark:border-secondary/30">
<!-- Sticky Checkbox -->
<td class="sticky left-0 z-20 bg-sky-50 dark:bg-surface-container-high px-3 py-2 text-center border-r border-slate-200 dark:border-surface-container-highest/60">
<input class="w-4 h-4 rounded bg-white dark:bg-surface-container-lowest opacity-40 cursor-not-allowed" disabled="" type="checkbox"/>
</td>
<!-- Sticky Status -->
<td class="sticky left-12 z-20 bg-sky-50 dark:bg-surface-container-high px-3 py-2 border-r border-slate-200 dark:border-surface-container-highest/60">
<span class="draft-status-badge">Drafting</span>
</td>
<!-- Date Input -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<input class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-label-md text-label-md border border-slate-200 dark:border-0 focus:ring-1 focus:ring-sky-500 dark:focus:ring-secondary outline-none" type="text" value="{{ now('Asia/Manila')->format('Y-m-d') }}"/>
</td>
<!-- Txn Auto No. -->
<td class="px-3 py-2 font-label-md text-label-md text-sky-700 dark:text-secondary-fixed-dim border-r border-slate-200 dark:border-surface-container-highest/40 italic font-medium">
                SI-000004*
              </td>
<!-- Customer Selector Dropdown -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<div class="relative">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-body-sm text-body-sm border border-slate-200 dark:border-0 focus:ring-1 focus:ring-sky-500 dark:focus:ring-secondary outline-none appearance-none pr-6">
<option selected="">Select customer or create...</option>
<option>Apex Media Dynamics</option>
<option>Prime Logistics Phils</option>
<option>+ Add New Customer</option>
</select>
<span class="material-symbols-outlined text-[14px] text-slate-400 dark:text-on-surface-variant absolute right-1.5 top-1/2 -translate-y-1/2 pointer-events-none">expand_more</span>
</div>
</td>
<!-- Gross Editable Input -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40 bg-white/70 dark:bg-surface-container-lowest/60">
<div class="relative">
<span class="absolute left-2 top-1/2 -translate-y-1/2 font-label-md text-label-md text-slate-400 dark:text-on-surface-variant">₱</span>
<input class="w-full text-right bg-white dark:bg-surface-container-lowest text-emerald-700 dark:text-primary-container px-2 pl-6 py-1 rounded font-label-md text-label-md font-semibold border border-slate-200 dark:border-0 focus:ring-1 focus:ring-emerald-500 dark:focus:ring-primary-container outline-none" placeholder="0.00" type="text"/>
</div>
</td>
<!-- Tax Type Dropdown -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-sky-700 dark:text-secondary px-2 py-1 rounded font-label-sm text-label-sm font-semibold border border-slate-200 dark:border-0 outline-none">
<option selected="">12% VAT</option>
<option>Zero-Rated (0%)</option>
<option>VAT Exempt</option>
<option>Non-VAT</option>
</select>
</td>
<!-- Account COA -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-body-sm text-body-sm border border-slate-200 dark:border-0 outline-none">
<option>4020 · Sales - Services</option>
<option>4000 · Merchandise Sales</option>
<option>4050 · Consulting Income</option>
</select>
</td>
<!-- CWT IT -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-label-sm text-label-sm border border-slate-200 dark:border-0 outline-none">


<option>None (0%)</option>
</select>
</td>
<!-- CWT VT -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-500 dark:text-on-surface-variant px-2 py-1 rounded font-label-sm text-label-sm border border-slate-200 dark:border-0 outline-none">
<option>None</option>

</select>
</td>
<!-- Terms -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-label-sm text-label-sm border border-slate-200 dark:border-0 outline-none">
<option>Cash</option>
<option>On Account</option>
</select>
</td>
<!-- SI Ref -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<input class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-label-sm text-label-sm border border-slate-200 dark:border-0 outline-none" placeholder="Auto / manual" type="text"/>
</td>
<!-- CTYPE -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-body-sm text-body-sm border border-slate-200 dark:border-0 outline-none">
<option>Regular</option>
<option>Top Withholding Agent</option>
</select>
</td>
<!-- Bank -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<select class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-body-sm text-body-sm border border-slate-200 dark:border-0 outline-none">
<option>1010 · Cash on Hand</option>
<option>1030 · BDO Checking</option>
<option>1040 · UnionBank Digital</option>
</select>
</td>
<!-- Memo -->
<td class="px-2 py-1.5 border-r border-slate-200 dark:border-surface-container-highest/40">
<input class="w-full bg-white dark:bg-surface-container-lowest text-slate-900 dark:text-on-surface px-2 py-1 rounded font-body-sm text-body-sm border border-slate-200 dark:border-0 outline-none" placeholder="Transaction purpose notes..." type="text"/>
</td>
<!-- Row Save / Dismiss Actions -->
<td class="sticky right-0 z-20 bg-sky-50 dark:bg-surface-container-high px-3 py-1.5 text-center whitespace-nowrap shadow-[-4px_0_6px_rgba(0,0,0,0.03)] dark:shadow-[-8px_0_12px_rgba(0,0,0,0.2)]">
<div class="flex items-center justify-center gap-1.5">
<button class="w-6 h-6 rounded bg-emerald-500 text-white dark:bg-primary-container dark:text-on-primary-fixed flex items-center justify-center hover:bg-emerald-600 dark:hover:bg-primary-fixed shadow-sm" data-save-draft aria-label="Save draft row" title="Save Row" type="button">
<span class="material-symbols-outlined text-[16px]">check</span>
</button>
<button class="w-6 h-6 rounded bg-slate-200 dark:bg-surface-container-highest text-slate-600 dark:text-on-surface-variant flex items-center justify-center hover:text-rose-600 hover:bg-slate-300 dark:hover:text-error dark:hover:bg-surface-container" data-discard-draft aria-label="Discard draft row" title="Discard" type="button">
<span class="material-symbols-outlined text-[16px]">close</span>
</button>
</div>
</td>
</tr>
</tbody>
</table>
</div>
<!-- Add Row Inline Bar -->
<div class="p-2 bg-slate-50 dark:bg-surface-container-low/70 border-t border-slate-200 dark:border-surface-container-high/60 flex items-center justify-between theme-transition">
<button data-sales-action="add" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-emerald-700 dark:text-primary-container hover:bg-emerald-50 dark:hover:bg-primary-container/10 font-label-md text-label-md transition-all font-semibold" type="button">
<span class="material-symbols-outlined text-[16px]">add</span>
<span>Click to add sales entry (Shift + N)</span>
</button>
<div class="flex items-center gap-space-sm text-slate-500 dark:text-on-surface-variant font-label-sm text-label-sm pr-2">
<span><span data-freeze-label>Columns unlocked</span></span>
<span>•</span>
<span>Illustrative tax breakdown</span>
</div>
</div>
<!-- Bottom Financial Reconciliation Strip (Ties Out) -->
<div class="bg-slate-50 dark:bg-surface-container-lowest/90 px-space-md py-space-sm border-t border-slate-200 dark:border-surface-container-high flex flex-wrap items-center justify-between gap-space-md theme-transition">
<!-- Status indicator -->
<div class="flex items-center gap-space-sm">
<div class="w-3 h-3 rounded-full bg-emerald-500 dark:bg-primary-container shadow-[0_0_10px_rgba(0,245,160,0.8)] animate-pulse"></div>
<div class="flex flex-col">
<span class="font-headline-sm text-[13px] text-slate-900 dark:text-on-surface leading-tight">Illustrative sales totals</span>
<span class="font-label-sm text-label-sm text-slate-500 dark:text-on-surface-variant">Sample arithmetic only · No ledger or tax return generated</span>
</div>
</div>
<!-- Metric aggregates -->
<div class="flex flex-wrap items-center gap-space-lg">
<div class="flex flex-col">
<span class="font-label-sm text-[10px] uppercase text-slate-500 dark:text-on-surface-variant tracking-wider font-semibold">Entries Count</span>
<span class="font-headline-sm text-headline-sm text-slate-900 dark:text-on-surface"><span data-total-count>3</span> <span class="font-label-sm text-[11px] text-slate-500 dark:text-on-surface-variant font-normal">Active</span></span>
</div>
<div class="h-8 w-px bg-slate-200 dark:bg-surface-container-highest hidden sm:block"></div>
<div class="flex flex-col">
<span class="font-label-sm text-[10px] uppercase text-slate-500 dark:text-on-surface-variant tracking-wider font-semibold">Net sales (demo)</span>
<span class="font-label-md text-label-md text-slate-800 dark:text-on-surface font-semibold"><span data-total-net>₱0.00</span></span>
</div>
<div class="h-8 w-px bg-slate-200 dark:bg-surface-container-highest hidden sm:block"></div>
<div class="flex flex-col">
<span class="font-label-sm text-[10px] uppercase text-sky-700 dark:text-secondary tracking-wider font-semibold">Output VAT (12%)</span>
<span class="font-label-md text-label-md text-sky-700 dark:text-secondary font-semibold"><span data-total-vat>₱0.00</span></span>
</div>
<div class="h-8 w-px bg-slate-200 dark:bg-surface-container-highest hidden sm:block"></div>
<div class="flex flex-col">
<span class="font-label-sm text-[10px] uppercase text-slate-500 dark:text-on-surface-variant tracking-wider font-semibold">Income CWT (demo)</span>
<span class="font-label-md text-label-md text-slate-800 dark:text-on-surface font-semibold"><span data-total-cwt>₱0.00</span></span>
</div>
<div class="h-8 w-px bg-slate-200 dark:bg-surface-container-highest hidden sm:block"></div>
<div class="flex flex-col bg-white dark:bg-surface-container-high/50 px-3 py-1.5 rounded-lg border border-emerald-300 dark:border-primary-container/20 shadow-sm">
<span class="font-label-sm text-[10px] uppercase text-emerald-700 dark:text-primary-container tracking-wider font-semibold">Total Gross Inflow</span>
<span class="font-headline-sm text-headline-sm text-emerald-600 dark:text-primary-container font-bold leading-none mt-0.5"><span data-total-gross>₱0.00</span></span>
</div>
</div>
</div>
</div>
<!-- Quick Keyboard Shortcuts Glass Banner -->
<div class="mt-space-md flex flex-wrap items-center justify-between gap-space-sm px-space-md py-space-xs rounded-xl bg-white/70 dark:bg-surface-container-lowest/60 border border-slate-200 dark:border-transparent text-slate-500 dark:text-on-surface-variant font-label-sm text-label-sm theme-transition">
<div class="flex items-center gap-space-md">
<span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-slate-100 dark:bg-surface-container rounded text-slate-800 dark:text-on-surface border border-slate-200 dark:border-transparent">Enter</kbd> Next cell</span>
<span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-slate-100 dark:bg-surface-container rounded text-slate-800 dark:text-on-surface border border-slate-200 dark:border-transparent">Tab</kbd> Next column</span>
<span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-slate-100 dark:bg-surface-container rounded text-slate-800 dark:text-on-surface border border-slate-200 dark:border-transparent">⌘ + P</kbd> Post selection</span>
<span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-slate-100 dark:bg-surface-container rounded text-slate-800 dark:text-on-surface border border-slate-200 dark:border-transparent">Del</kbd> Void selected demo rows</span>
</div>
<div class="flex items-center gap-2">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-500 dark:bg-primary-container"></span>
<span>Local preview · Saved in this tab</span>
</div>
</div>
</div>
</div></main></div><button type="button" id="sales-menu-toggle" aria-label="Toggle workspace navigation" aria-controls="sales-sidebar" aria-expanded="false" class="sales-mobile-toggle"><span class="material-symbols-outlined">menu</span></button>
<button type="button" id="sales-backdrop" aria-label="Close workspace navigation" hidden></button>
<dialog id="sales-dialog" aria-labelledby="sales-dialog-title" aria-describedby="sales-dialog-description" class="sales-dialog rounded-2xl border border-outline-variant bg-surface-container-low p-6 text-on-surface shadow-2xl">
<form method="dialog" class="flex justify-end"><button type="submit" aria-label="Close dialog" class="rounded-lg bg-surface-container-high px-3 py-1 text-xl">×</button></form>
<h2 id="sales-dialog-title" class="mt-3 font-headline-md text-headline-md"></h2><p id="sales-dialog-description" class="mt-4 text-body-md text-on-surface-variant"></p>
<div data-row-actions hidden class="mt-5 flex flex-wrap gap-3"><button type="button" data-row-duplicate class="rounded-lg bg-secondary/15 px-4 py-2 text-secondary">Duplicate as draft</button><button type="button" data-row-void class="rounded-lg bg-error/15 px-4 py-2 text-error">Void / restore</button></div>
</dialog><dialog id="sales-detail-drawer" aria-labelledby="drawer-title" aria-describedby="drawer-subtitle" class="sales-entry-drawer bg-surface-container-low text-on-surface"><div class="entry-drawer-content flex h-full flex-col">
<!-- Drawer Header -->
<div class="p-space-md bg-surface-container flex items-center justify-between shadow-sm">
<div class="flex items-center gap-space-xs">
<div class="w-7 h-7 rounded-lg bg-primary-container/20 flex items-center justify-center">
<span class="material-symbols-outlined text-primary-container text-[18px]">add_circle</span>
</div>
<div>
<div class="flex items-center gap-2">
<h2 id="drawer-title" class="font-headline-sm text-headline-sm text-on-surface font-bold">New entry</h2>
<span class="px-1.5 py-0.5 rounded bg-surface-container-high text-secondary font-label-sm text-[10px] uppercase tracking-wider font-semibold">
                <span id="entry-state-badge">Drafting</span>
              </span>
</div>
<p id="drawer-subtitle" class="font-label-sm text-label-sm text-on-surface-variant">Single-line demo entry · Saved only in this tab</p>
</div>
</div>
<button class="w-8 h-8 rounded-lg bg-surface-container-high hover:bg-surface-variant flex items-center justify-center text-on-surface-variant hover:text-on-surface transition-colors" id="close-drawer-btn" type="button" aria-label="Close entry popup" title="Close Drawer (Esc)">
<span class="material-symbols-outlined text-[18px]">close</span>
</button>
</div>
<!-- Drawer Scrollable Form Area -->
<div class="flex-1 overflow-y-auto p-space-md flex flex-col gap-space-lg custom-scrollbar">
<section id="void-audit-card" hidden class="void-audit-card">
<div class="flex items-center justify-between"><h3 class="font-label-sm uppercase text-error flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">gavel</span> Void audit details</h3><span id="void-audit-reference" class="font-label-sm text-error"></span></div>
<div><span class="font-label-sm text-on-surface-variant">Reason</span><p id="void-audit-reason" class="text-body-sm text-error"></p><textarea id="void-reason-editor" aria-label="Edit void reason" hidden rows="3" maxlength="1000" class="w-full bg-surface-container p-2 rounded-lg"></textarea></div>
<div class="grid grid-cols-2 gap-2 text-body-sm"><div><span class="font-label-sm text-on-surface-variant">Voided by</span><p id="void-audit-user"></p></div><div><span class="font-label-sm text-on-surface-variant">Timestamp (PHT)</span><p id="void-audit-time"></p></div></div>
<button type="button" id="save-void-reason" hidden class="entry-action">Save reason</button>
</section>
<section id="void-reversal-card" hidden class="void-reversal-card"><h3 class="font-label-sm uppercase">Reversal preview</h3><p class="text-body-sm text-on-surface-variant">Illustrative only · No reversal has been posted.</p><div id="void-reversal-lines" class="font-label-sm space-y-1"></div></section>
<!-- Section 1: Real-time Derived Telemetry Card -->
<div class="bg-surface-container p-space-sm rounded-xl flex flex-col gap-space-xs shadow-inner">
<div class="flex items-center justify-between pb-1">
<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Derived · Demo preview</span>
<span class="material-symbols-outlined text-secondary text-[14px]">calculate</span>
</div>
<div class="grid grid-cols-2 gap-space-xs font-label-md text-label-md pt-1">
<div class="flex flex-col bg-surface-container-lowest/80 p-space-xs rounded-lg">
<span class="text-on-surface-variant font-label-sm text-[10px]">Net Sales</span>
<span class="text-on-surface font-mono font-semibold" id="derived-net">0.00</span>
</div>
<div class="flex flex-col bg-surface-container-lowest/80 p-space-xs rounded-lg">
<span class="text-on-surface-variant font-label-sm text-[10px]">Output VAT (12%)</span>
<span class="text-secondary font-mono font-semibold" id="derived-vat">0.00</span>
</div>
<div class="flex flex-col bg-surface-container-lowest/80 p-space-xs rounded-lg">
<span class="text-on-surface-variant font-label-sm text-[10px]">CWT IT</span>
<span class="text-on-surface font-mono font-semibold" id="derived-cwt-it">0.00</span>
</div>
<div class="flex flex-col bg-surface-container-lowest/80 p-space-xs rounded-lg">
<span class="text-on-surface-variant font-label-sm text-[10px]">CWT VAT</span>
<span class="text-on-surface font-mono font-semibold" id="derived-cwt-vat">0.00</span>
</div>
</div>
<div class="mt-1 flex items-center justify-between bg-surface-container-lowest/50 px-space-xs py-1 rounded">
<span class="font-label-sm text-label-sm text-on-surface-variant">Settlement Type:</span>
<span class="font-label-sm text-label-sm text-primary-container font-semibold" data-entry-settlement>Cash</span>
</div>
</div>
<section class="entry-journal bg-surface-container p-space-sm rounded-xl flex flex-col gap-space-xs">
<div class="flex items-center justify-between"><span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Journal preview</span><span id="entry-balance-label" class="font-label-sm text-[10px] text-on-surface-variant font-mono">Balanced: ₱0.00</span></div>
<div id="journal-preview-placeholder" class="entry-journal-placeholder"><span class="material-symbols-outlined text-outline-variant text-[24px]">account_balance_wallet</span><p>Fill the required fields to preview debit / credit lines.</p></div>
<div class="hidden space-y-1 font-label-sm text-label-sm" id="journal-preview-table">
<div class="flex justify-between text-on-surface">
<span data-entry-debit-label>Dr. Cash / Bank</span>
<span class="font-mono text-primary-container" id="je-dr-cash">₱ 0.00</span>
</div>
<div class="flex justify-between text-on-surface">
<span>Dr. Creditable Withholding (CWT)</span>
<span class="font-mono text-secondary" id="je-dr-cwt">₱ 0.00</span>
</div>
<div class="flex justify-between text-on-surface pl-2">
<span data-entry-revenue-label>Cr. Service Revenue</span>
<span class="font-mono text-on-surface" id="je-cr-rev">₱ 0.00</span>
</div>
<div class="flex justify-between text-on-surface pl-2">
<span>Cr. Output VAT Payable</span>
<span class="font-mono text-on-surface" id="je-cr-vat">₱ 0.00</span>
</div>
</div>
</section><p class="text-body-sm text-on-surface-variant">Illustrative arithmetic only. No real ledger entry or payment is recorded.</p><p id="entry-validation" role="alert" class="text-body-sm text-error"></p>
<!-- Section 3: Inputs Form -->
<form class="flex flex-col gap-space-md" id="new-entry-form">
<div class="flex items-center justify-between pb-1">
<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">Transaction Inputs</span>
<span class="font-label-sm text-[10px] text-error font-medium">* Required</span>
</div>
<!-- Date -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface flex items-center justify-between">
<span>Date <span class="text-error">*</span></span>
<span class="font-label-sm text-[10px] text-on-surface-variant">YYYY-MM-DD</span>
</label>
<div class="relative">
<input data-entry-field="Date" aria-label="Entry Date" id="entry-date" class="w-full bg-surface-container text-on-surface font-label-md text-label-md px-space-sm py-2 rounded-lg focus:outline-none focus:ring-1 focus:ring-secondary transition-all" type="date" value="2026-10-16"/>
</div>
</div>
<!-- Customer Dropdown -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface flex items-center justify-between">
<span>Customer <span class="text-error">*</span></span>
<button type="button" id="entry-new-customer" aria-label="New client" class="text-secondary font-label-sm text-[10px] hover:underline" title="Enter a customer name for this demo entry">+ New Client</button>
</label>
<div class="relative">
<select data-entry-field="Customer" aria-label="Entry Customer" id="drawer-customer-select" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary transition-all cursor-pointer">
<option disabled="" selected="" value="">Select customer...</option>
<option value="cust_1">VARIOUS CUSTOMERS (Walk-in)</option>
<option value="cust_2">ACME CORP PHILIPPINES INC (TIN: 004-981-221)</option>
<option value="cust_3">NEXUS GLOBAL COMMERCE LTD</option>
<option value="cust_4">MAKATI INNOVATION LABS</option>
</select>
<span class="material-symbols-outlined absolute right-2.5 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
<!-- Gross Amount -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface flex items-center justify-between">
<span>Gross Amount (PHP) <span class="text-error">*</span></span>
<span id="entry-tax-hint" class="font-label-sm text-[10px] text-primary-container font-mono">Inc. 12% VAT</span>
</label>
<div class="relative">
<span class="absolute left-3 top-2 text-on-surface-variant font-mono font-semibold">₱</span>
<input data-entry-field="Gross amount" aria-label="Entry Gross amount" id="drawer-gross-input" class="w-full bg-surface-container text-right font-headline-sm text-headline-sm text-primary font-bold pl-8 pr-space-sm py-1.5 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-container transition-all" placeholder="0.00" step="0.01" type="number" min="0.01" required />
</div>
</div>
<!-- Type & Terms side-by-side -->
<div class="grid grid-cols-2 gap-space-sm">
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">Tax Type <span class="text-error">*</span></label>
<div class="relative">
<select data-entry-field="Tax type" aria-label="Entry Tax type" id="entry-tax-type" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option selected="" value="VAT">VAT (12%)</option>
<option value="NONVAT">Non-VAT (0%)</option>
<option value="EXEMPT">Exempt</option>
<option value="ZERO_RATED">Zero Rated</option>
</select>
<span class="material-symbols-outlined absolute right-2 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">Terms <span class="text-error">*</span></label>
<div class="relative">
<select data-entry-field="Terms" aria-label="Entry Terms" id="entry-terms" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option selected="" value="Cash">Cash</option>
<option value="On Account">On Account</option>
</select>
<span class="material-symbols-outlined absolute right-2 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
</div>
<!-- Revenue Account -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">Revenue Account <span class="text-error">*</span></label>
<div class="relative">
<select data-entry-field="Revenue account" aria-label="Entry Revenue account" id="entry-revenue-account" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option disabled="" selected="" value="">Select revenue account...</option>
<option value="4000-01">4000-01 · Sales - Services</option>
<option value="4000-02">4000-02 · Sales - Merchandise</option>
<option value="4010-01">4010-01 · Subscription Revenue</option>
<option value="4020-00">4020-00 · Professional Advisory Fees</option>
</select>
<span class="material-symbols-outlined absolute right-2.5 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
<!-- CWT IT & CWT VT Dropdowns -->
<div class="grid grid-cols-2 gap-space-sm">
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">CWT IT (Creditable)</label>
<div class="relative">
<select data-entry-field="Income withholding" aria-label="Entry Income withholding" id="drawer-cwt-it-select" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option selected="" value="NONE">None</option>




</select>
<span class="material-symbols-outlined absolute right-2 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">CWT VT</label>
<div class="relative">
<select data-entry-field="VAT withholding" aria-label="Entry VAT withholding" id="entry-vat-withholding" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option selected="" value="NONE">None</option>


</select>
<span class="material-symbols-outlined absolute right-2 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
</div>
<!-- SI / OR Number & CTYPE -->
<div class="grid grid-cols-2 gap-space-sm">
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">SI/OR No.</label>
<input data-entry-field="Invoice reference" aria-label="Entry Invoice reference" id="entry-invoice-reference" class="w-full bg-surface-container text-on-surface font-label-md text-label-md px-space-sm py-2 rounded-lg placeholder:text-on-surface-variant/50 focus:outline-none focus:ring-1 focus:ring-secondary" placeholder="e.g. 0001429" type="text"/>
</div>
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">CTYPE</label>
<div class="relative">
<select data-entry-field="Classification" aria-label="Entry Classification" id="entry-classification" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option selected="" value="Regular">Regular</option>
<option value="Government">Government</option>
<option value="ZeroRated">Zero Rated</option>
<option value="PEZA">PEZA Zone</option>
</select>
<span class="material-symbols-outlined absolute right-2 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
</div>
<!-- Cash / Bank Account -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">Cash/Bank Repository</label>
<div class="relative">
<select data-entry-field="Deposit account" aria-label="Entry Deposit account" id="entry-deposit-account" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg appearance-none focus:outline-none focus:ring-1 focus:ring-secondary cursor-pointer">
<option selected="" value="1010-01">1010-01 · Cash on Hand</option>
<option value="1020-01">1020-01 · BDO Unibank Current (...4820)</option>
<option value="1020-02">1020-02 · BPI Corporate Account (...1192)</option>
<option value="1030-00">1030-00 · Petty Cash Fund</option>
</select>
<span class="material-symbols-outlined absolute right-2.5 top-2.5 text-on-surface-variant pointer-events-none text-[18px]">expand_more</span>
</div>
</div>
<!-- Settle Now / Amount -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface flex items-center justify-between">
<span>Settle Now</span>
<button type="button" id="entry-match-gross" aria-label="Match gross" class="font-label-sm text-[10px] text-secondary hover:underline">Match Gross</button>
</label>
<input data-entry-field="Settlement amount" aria-label="Entry Settlement amount" id="entry-settlement-amount" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg placeholder:text-on-surface-variant/50 focus:outline-none focus:ring-1 focus:ring-secondary" placeholder="Full amount settlement..." type="number" step="0.01" min="0" />
</div>
<!-- Description -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface flex items-center justify-between">
<span>Description / Item (optional)</span>
<button type="button" data-entry-quick-picks aria-label="Recent descriptions" class="text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">schedule</span></button>
</label>
<input data-entry-field="Description" aria-label="Entry Description" id="entry-description" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg placeholder:text-on-surface-variant/50 focus:outline-none focus:ring-1 focus:ring-secondary" placeholder="What is being billed..." type="text"/>
</div>
<section class="entry-attachments" aria-labelledby="invoice-attachments-title">
<div class="flex items-center justify-between gap-2"><h3 id="invoice-attachments-title" class="font-label-sm text-label-sm">SALES INVOICE DOCUMENTS</h3><label id="invoice-upload-label" for="invoice-files" class="entry-attach-button"><span class="material-symbols-outlined">attach_file</span> Attach</label></div>
<input type="file" id="invoice-files" aria-label="Attach sales invoice documents" accept="application/pdf,image/jpeg,image/png,image/webp" multiple>
<p class="text-body-sm text-on-surface-variant">PDF, JPG, PNG or WebP · Up to 5 files, 10 MB each. Files stay in this tab and reset on reload. Save changes to keep them with this entry.</p>
<p id="invoice-file-error" role="alert" class="text-body-sm text-error"></p>
<ul id="invoice-file-list" class="entry-file-list"></ul>
</section>
<!-- Particulars / Notes -->
<div class="flex flex-col gap-1">
<label class="font-label-sm text-label-sm text-on-surface">Particulars &amp; Audit Notes</label>
<textarea data-entry-field="Particulars" aria-label="Entry Particulars" id="entry-particulars" class="w-full bg-surface-container text-on-surface font-body-sm text-body-sm px-space-sm py-2 rounded-lg placeholder:text-on-surface-variant/50 focus:outline-none focus:ring-1 focus:ring-secondary resize-none" placeholder="Internal BIR cross-reference, client PO, or project ID..." rows="2"></textarea>
</div>
</form>
</div>
<!-- DRAWER STICKY FOOTER -->
<div class="entry-footer p-space-sm bg-surface-container-high/90 border-t border-outline-variant/30">
<div class="entry-actions">
<button type="button" id="delete-voided-entry-btn" hidden class="entry-action text-error"><span class="material-symbols-outlined">delete</span> Delete</button>
<button type="button" id="edit-void-reason-btn" hidden class="entry-action"><span class="material-symbols-outlined">edit</span> Edit</button>
<button type="button" id="edit-entry-btn" class="entry-action"><span class="material-symbols-outlined">edit</span> Edit</button>
<button type="button" id="void-entry-btn" class="entry-action text-error"><span class="material-symbols-outlined">block</span> <span data-void-label>Void</span></button>
<button type="button" id="print-entry-btn" class="entry-action"><span class="material-symbols-outlined">print</span> Print</button>
</div>
<div id="entry-void-confirm" hidden class="entry-confirm" role="group" aria-label="Confirm void entry"><p>Void this demo entry? It will be excluded from active totals.</p><label for="void-entry-reason">Reason for voiding</label><textarea id="void-entry-reason" rows="2" maxlength="1000" class="w-full bg-surface-container p-2 rounded-lg" placeholder="Enter a reason" required></textarea><button type="button" id="confirm-void-entry">Confirm void</button><button type="button" id="cancel-void-entry">Cancel</button></div>
<div class="flex items-center justify-between gap-2 mt-2">
<button class="w-9 h-9 rounded-lg bg-surface-container hover:bg-error-container text-error hover:text-on-error-container flex items-center justify-center transition-colors" id="delete-entry-btn" aria-label="Discard entry" title="Discard entry">
<span class="material-symbols-outlined text-[20px]">delete</span>
</button>
<div class="flex items-center gap-2">
<button class="px-3 py-1.5 rounded-lg bg-surface-container hover:bg-surface-container-highest text-on-surface font-headline-sm text-body-md transition-colors shadow-sm" id="save-draft-btn">
              Save Draft
            </button>
<button class="flex items-center gap-1.5 px-4 py-1.5 rounded-lg bg-primary-container text-on-primary-container hover:bg-primary-fixed font-headline-sm text-body-md shadow-[0_0_20px_rgba(0,245,160,0.35)] transition-all" id="post-sale-btn">
<span class="material-symbols-outlined text-[18px]">verified</span>
<span class="font-bold">Post (demo)</span>
</button>
</div>
</div>
</div>
</div></dialog>
<script type="application/json" id="sales-company-data">@json($company)</script>
<dialog id="sales-print-dialog" class="sales-print-dialog" aria-labelledby="print-preview-title">
<div class="print-preview-toolbar"><h2 id="print-preview-title">Billing statement preview</h2><div><button type="button" id="print-statement-btn">Print / Save PDF</button><button type="button" id="close-print-btn">Close preview</button></div></div>
<div id="statement-preview"></div>
</dialog>
<div id="sales-print-output"></div>
<script type="application/json" id="workspace-ledger-config" data-key="{{ $company['browser_key'] ?? hash('sha256', session()->getId().'|'.$company['company_name'].'|'.($company['tin'] ?? '')) }}">{"period":"{{ now('Asia/Manila')->format('Y-m') }}"}</script></body></html>
