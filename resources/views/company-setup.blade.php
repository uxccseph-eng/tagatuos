<!DOCTYPE html><html lang="en" class="dark"><head>@include('role-access-assets')

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>kontadurya.io — Set up your company</title>

  <!-- Google Fonts -->

  <link rel="preconnect" href="https://fonts.googleapis.com">

  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">

  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&amp;family=Plus+Jakarta+Sans:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->

  <script src="https://cdn.tailwindcss.com"></script>

  <script src="{{ asset('js/company-setup-config.js') }}"></script>

  <link rel="stylesheet" href="{{ asset('css/company-setup.css') }}?v={{ filemtime(public_path('css/company-setup.css')) }}"><script src="{{ asset('js/company-setup.js') }}?v={{ filemtime(public_path('js/company-setup.js')) }}" defer></script>

@include('working-period-assets')
<script src="{{ asset('js/draft-leave-guard.js') }}?v={{ filemtime(public_path('js/draft-leave-guard.js')) }}" defer></script></head>

<body class="min-h-screen cosmic-mesh grid-lines relative selection:bg-emerald-500/30 selection:text-emerald-200">



  <!-- TOP PERSISTENT NAVIGATION CHROME -->

  <header class="sticky top-0 z-50 flex h-16 shrink-0 items-center justify-between border-b border-slate-800/80 bg-[#030712]/80 px-4 sm:px-8 backdrop-blur-2xl">

    <div class="flex items-center gap-6">

      <a class="flex items-center gap-2.5 text-base font-bold tracking-tight text-white group" href="{{ route('home') }}">

        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 text-slate-950 font-black shadow-lg shadow-emerald-500/20 group-hover:scale-105 transition-transform">

          ₱

        </span>

        <span class="text-lg tracking-tight">kontadurya<span class="text-emerald-400">.io</span></span>

      </a>

      <div class="hidden sm:flex items-center gap-2 border-l border-slate-800 pl-4">

        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-950/60 border border-emerald-500/30 px-2.5 py-0.5 text-xs font-mono font-medium text-emerald-400">

          <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>

          Company Setup Wizard

        </span>

      </div>

    </div>



    <div class="flex items-center gap-3">

      <!-- 100% Free Forever badge -->

      <span class="hidden md:inline-flex items-center gap-1.5 rounded-full bg-slate-900 border border-slate-700/60 px-3 py-1 text-xs font-mono text-slate-300">

        <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">

          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>

        </svg>

        100% Free Forever Core

      </span>



      @auth
      <a href="{{ route('account.dashboard') }}" class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300">Company books</a>
      <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit" class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300">Sign out</button></form>
      @else
      <form action="{{ route('preview.exit') }}" method="POST">@csrf<button type="submit" class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-300">Exit demo</button></form>
      @endauth

      <div class="flex items-center gap-2 pl-1">

        <span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-700 to-slate-800 border border-slate-600 text-xs font-bold text-white shadow-inner">

          DM

          <span class="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full bg-emerald-400 border-2 border-slate-950"></span>

        </span>

      </div>

    </div>

  </header>



  <!-- MAIN CONTAINER -->

  <main class="flex-1 px-4 py-8 sm:px-6 sm:py-12">

    <div class="mx-auto max-w-3xl space-y-7 pb-20">



      <!-- HEADER TITLE & STEP PROGRESS -->

      <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 border-b border-slate-800/80 pb-6">

        <div>

          <div class="flex items-center gap-2 text-xs font-mono text-emerald-400 uppercase tracking-widest mb-1.5">

            <span>Philippine BIR Engine</span>

            <span>•</span>

            <span>Initialization Phase</span>

          </div>

          <h1 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">

            Set up your company

          </h1>

          <p class="mt-2 text-sm sm:text-base text-slate-400 max-w-xl">

            Set your company preferences and explore the onboarding flow — editable anytime in <span class="text-slate-300 font-medium">Settings</span>.

          </p>

        </div>



        <div class="flex items-center gap-2 bg-slate-900/80 border border-slate-800 px-3.5 py-2 rounded-xl shrink-0">

          <span class="flex h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span>

          <span class="text-xs font-mono text-slate-300">{{ auth()->check() ? 'Company registration' : 'Company setup preview' }}</span>

        </div>

      </div>



      <div class="rounded-xl border border-cyan-400/25 bg-cyan-400/5 p-4 text-xs leading-relaxed text-cyan-100">Demo mode · No identity has been verified. Use sample details. Settings are saved only in your local session.</div>

@if (session('status'))<div role="status" class="rounded-xl border border-emerald-400/30 bg-emerald-400/10 p-4 text-sm text-emerald-200">{{ session('status') }}</div>@endif

@if ($errors->any())

<div role="alert" tabindex="-1" id="setup-errors" class="rounded-xl border border-rose-400/40 bg-rose-400/10 p-4 text-sm text-rose-200"><p class="font-bold">Please check the following:</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>

@endif

<form action="{{ route('company.store') }}" method="POST" class="space-y-7" data-company-form>

@csrf

<!-- CARD 1: YOUR DETAILS -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-5">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">01</span>

              Your details

            </h2>

            <span class="text-xs font-mono text-emerald-400 bg-emerald-950/40 border border-emerald-500/20 px-2 py-0.5 rounded">No Credit Card Required</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            Enter a name for this demo. kontadurya.io is <strong class="text-slate-200">100% Free Forever Core</strong> — no mandatory card, no surprise paywalls, and your books always remain yours.

          </p>

        </div>



        <div class="space-y-4">

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="setup-full-name">

              Full Name <span class="text-rose-400">*</span>

            </label>

            <div class="relative">

              <input id="setup-full-name" name="full_name" maxlength="255" value="{{ old('full_name', $company['full_name'] ?? '') }}" type="text" required="" placeholder="Juan dela Cruz" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 text-sm text-white placeholder-slate-500 shadow-inner transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

              

            </div>

          </div>



          <!-- Terms acceptance -->

          <div class="flex items-start gap-3 rounded-xl border border-slate-800/80 bg-slate-950/40 p-3.5">

            <input id="setup-terms" type="checkbox" name="preview_ack" value="1" required @checked(old('preview_ack', $company['preview_ack'] ?? false)) class="mt-1 h-4 w-4 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-400">

            <label for="setup-terms" class="text-xs sm:text-sm text-slate-400 leading-relaxed">{{ auth()->check() ? 'I confirm these registration details belong to the selected company book. Saving does not submit a tax filing.' : 'I understand this is a local demo. My sample settings are saved only in this session; no account, tax filing, or accounting books are created.' }}</label>

          </div>

        </div>

      </div>



      <!-- CARD 2: TAX TYPE & REGISTRATION -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-6">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">02</span>

              Tax type &amp; registration

            </h2>

            <span class="text-xs font-mono text-slate-400">BIR Tax Profile Preset</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            {{ auth()->check() ? 'Select the company’s registered tax profile.' : 'Choose a sample tax profile. No tax calculation engine or filing service is connected in this preview.' }}

          </p>

        </div>



        <!-- Question 1: Are you VAT-registered? -->

        <div class="space-y-3">

          <label class="block text-sm font-semibold text-white">Are you VAT-registered?</label>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="group-vat">

            

            <!-- Option: VAT-registered -->

            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="vat_type" value="vat" @checked(old('vat_type', $company['vat_type'] ?? 'non_vat') === 'vat')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">VAT-registered</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1.5 text-xs text-slate-400 leading-normal">

                You charge 12% Output VAT, claim Input VAT, and file Form 2550Q quarterly.

              </span>

            </label>



            <!-- Option: Non-VAT (Default Selected) -->

            <label class="group relative flex flex-col p-4 rounded-xl border text-left transition-all choice-card cursor-pointer"><input type="radio" class="sr-only" name="vat_type" value="non_vat" @checked(old('vat_type', $company['vat_type'] ?? 'non_vat') === 'non_vat')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-white flex items-center gap-1.5">

                  Non-VAT

                  

                </span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1.5 text-xs text-slate-300 leading-normal">

                Subject to 3% percentage tax under Section 116. Generates Form 2551Q quarterly.

              </span>

            </label>

          </div>

        </div>



        <!-- Question 2: How are you registered? -->

        <div class="space-y-3">

          <label class="block text-sm font-semibold text-white">How are you registered with the BIR?</label>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="group-entity">



            <!-- Option: Corporation / Partnership (Default Selected) -->

            <label class="group relative flex flex-col p-4 rounded-xl border text-left transition-all choice-card cursor-pointer"><input type="radio" class="sr-only" name="entity_type" value="corporation" @checked(old('entity_type', $company['entity_type'] ?? 'corporation') === 'corporation')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-white">Corporation / Partnership</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1.5 text-xs text-slate-300 leading-normal">

                A registered SEC company, OPC, or general partnership. Files 1702Q / 1702-RT.

              </span>

            </label>



            <!-- Option: Individual -->

            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="entity_type" value="individual" @checked(old('entity_type', $company['entity_type'] ?? 'corporation') === 'individual')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">Individual</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1.5 text-xs text-slate-400 leading-normal">

                Sole proprietor, DTI-registered venture, or self-employed professional (1701Q).

              </span>

            </label>

          </div>

        </div>

      </div>



      <!-- CARD 3: COMPANY DETAILS -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-5">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">03</span>

              Company details

            </h2>

            <span class="text-xs font-mono text-slate-400">Official BIR Header Info</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            The company name and contact number are required — you can refine the legal entity and branch codes anytime in Settings.

          </p>

        </div>



        <div class="space-y-4">

          <!-- Company Name -->

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="company-name">

              Company Name <span class="text-rose-400">*</span>

            </label>

            <input id="company-name" name="company_name" maxlength="255" value="{{ old('company_name', $company['company_name'] ?? '') }}" type="text" required="" placeholder="e.g. Acme Philippines Tech Solutions Inc." class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>



          <!-- Registered Name & Trade Name -->

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div class="space-y-1.5">

              <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="registered-name">

                Registered / Legal Name

              </label>

              <input id="registered-name" name="registered_name" maxlength="255" value="{{ old('registered_name', $company['registered_name'] ?? '') }}" type="text" placeholder="As stated in BIR Form 2303" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

            </div>

            <div class="space-y-1.5">

              <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="trade-name">

                Trade Name (DBA)

              </label>

              <input id="trade-name" name="trade_name" maxlength="255" value="{{ old('trade_name', $company['trade_name'] ?? '') }}" type="text" placeholder="Optional commercial brand name" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

            </div>

          </div>



          <!-- TIN & Branch Code -->

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div class="space-y-1.5">

              <div class="flex items-center justify-between">

                <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="tin-field">

                  Tax Identification Number (TIN) *

                </label>

                <span class="text-[11px] font-mono text-slate-400">9 digits</span>

              </div>

              <input id="tin-field" name="tin" maxlength="11" required inputmode="numeric" pattern="[0-9]{3}-[0-9]{3}-[0-9]{3}" value="{{ substr(\App\Tin::format(old('tin', $company['tin'] ?? '')), 0, 11) }}" type="text" placeholder="000-000-000" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 font-mono text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

            </div>

            <div class="space-y-1.5">

              <div class="flex items-center justify-between">

                <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="branch-code">

                  Branch Code

                </label>

                <span class="text-[11px] font-mono text-slate-400">5 digits · Head Office: 00000</span>

              </div>

              <input id="branch-code" name="branch_code" required inputmode="numeric" maxlength="5" pattern="[0-9]{5}" value="{{ old('branch_code', $company['branch_code'] ?? '00000') }}" type="text" placeholder="00000" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 font-mono text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

            </div>

          </div>



          <!-- Contact Number -->

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <div class="space-y-1.5">

              <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="contact-number">

                Contact Number <span class="text-rose-400">*</span>

              </label>

              <input id="contact-number" name="contact_number" maxlength="30" value="{{ old('contact_number', $company['contact_number'] ?? '') }}" type="tel" required="" placeholder="0917 123 4567" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 font-mono text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

            </div>

          </div>

        </div>

      </div>



      <!-- CARD 4: BIR REGISTRATION -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-5">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">04</span>

              BIR registration

            </h2>

            <span class="text-xs font-mono text-slate-400">RDO &amp; Fiscal Cycle</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            Specify your assigned Revenue District Office (RDO) and your corporate accounting year.

          </p>

        </div>



        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-start">

          <!-- RDO Dropdown -->

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="rdo-select" id="rdo-field-label">

              Revenue District Office (RDO Code)

            </label>

            <div class="relative">

              <button type="button" id="rdo-trigger" class="rdo-trigger" aria-haspopup="dialog" aria-expanded="false" aria-controls="rdo-picker" aria-labelledby="rdo-field-label rdo-selection" hidden><span id="rdo-selection">Select your Revenue District Office</span><span aria-hidden="true">⌄</span></button>
              <div id="rdo-picker" class="rdo-picker" role="dialog" aria-label="Choose Revenue District Office" hidden>
                <input id="rdo-search" type="search" role="combobox" aria-label="Search Revenue District Offices" aria-autocomplete="list" aria-expanded="false" aria-controls="rdo-options" placeholder="Search code or location…" autocomplete="off">
                <div id="rdo-options" role="listbox" aria-label="Revenue District Offices"></div>
                <p id="rdo-result-count" role="status">{{ count(\App\RevenueDistrictOffices::all()) }} Revenue District Offices</p>
              </div>
              <select id="rdo-select" name="rdo" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-4 py-2.5 text-sm text-white">
                <option value="">Select your Revenue District Office</option>
                @foreach(\App\RevenueDistrictOffices::all() as $code => $office)
                  <option value="{{ $code }}" @selected(str_pad(strtoupper((string) old('rdo', $company['rdo'] ?? '')), 3, '0', STR_PAD_LEFT) === (string) $code)>{{ $code }} · {{ $office }}</option>
                @endforeach
              </select>




            </div>

            <p class="text-[11px] text-slate-500">Found on line 4 of your BIR Form 2303 Certificate of Registration.</p>

          </div>



          <!-- Accounting Period -->

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300">

              Accounting Period

            </label>

            <div class="flex items-center gap-4 h-10 px-2" id="group-period">

              <label class="flex items-center gap-2 cursor-pointer group">

                <input type="radio" name="accounting_period" value="calendar" @checked(old('accounting_period', $company['accounting_period'] ?? 'calendar') === 'calendar') class="h-4 w-4 border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-400">

                <span class="text-sm text-slate-200 group-hover:text-white font-medium">Calendar (Jan 1 - Dec 31)</span>

              </label>

              <label class="flex items-center gap-2 cursor-pointer group">

                <input type="radio" name="accounting_period" value="fiscal" @checked(old('accounting_period', $company['accounting_period'] ?? 'calendar') === 'fiscal') class="h-4 w-4 border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-400">

                <span class="text-sm text-slate-400 group-hover:text-slate-200">Fiscal</span>

              </label>

            </div>

            <p class="text-[11px] text-slate-500">Use the period on your registration documents.</p>

<div class="mt-3" data-fiscal-field><label for="fiscal-end-month" class="block text-xs text-slate-300">Fiscal year ending month (1–12)</label><input id="fiscal-end-month" name="fiscal_end_month" type="number" min="1" max="12" value="{{ old('fiscal_end_month', $company['fiscal_end_month'] ?? '') }}" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm text-white"></div>

          </div>

        </div>

      </div>



      <!-- CARD 5: REGISTERED / BUSINESS ADDRESS -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-5">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">05</span>

              Registered business address

            </h2>

            <span class="text-xs font-mono text-slate-400">Principal Place of Business</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            The principal place of business registered on file with the Bureau of Internal Revenue (BIR).

          </p>

        </div>



        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="addr-substreet">

              Building / Sub-street

            </label>

            <input id="addr-substreet" name="building" maxlength="255" value="{{ old('building', $company['building'] ?? '') }}" type="text" placeholder="Unit / Floor / Bldg" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="addr-street">

              Street

            </label>

            <input id="addr-street" name="street" maxlength="255" value="{{ old('street', $company['street'] ?? '') }}" type="text" placeholder="e.g. Ayala Avenue" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="addr-barangay">

              Barangay

            </label>

            <input id="addr-barangay" name="barangay" maxlength="255" value="{{ old('barangay', $company['barangay'] ?? '') }}" type="text" placeholder="e.g. San Lorenzo" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="addr-district">

              City / Municipality

            </label>

            <input id="addr-district" name="city" maxlength="255" value="{{ old('city', $company['city'] ?? '') }}" type="text" placeholder="e.g. Makati City" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="addr-city">

              Province / Region

            </label>

            <input id="addr-city" name="province" maxlength="255" value="{{ old('province', $company['province'] ?? '') }}" type="text" placeholder="e.g. Metro Manila" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>

          <div class="space-y-1.5">

            <label class="block text-xs font-medium uppercase tracking-wider text-slate-300" for="addr-zip">

              ZIP Code

            </label>

            <input id="addr-zip" name="zip_code" maxlength="4" value="{{ old('zip_code', $company['zip_code'] ?? '') }}" type="text" placeholder="1223" class="w-full rounded-xl border border-slate-700/80 bg-slate-950/80 px-3.5 py-2.5 font-mono text-sm text-white placeholder-slate-500 transition-colors focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400">

          </div>

        </div>

      </div>



      <!-- CARD 6: YOUR SHEETS CONFIGURATION -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-6">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">06</span>

              Your sheets

            </h2>

            <span class="text-xs font-mono text-slate-400">Workspace Streamlining</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            We'll customize your sidebar to display only the sheets your workflow requires. You can activate any sheet later in <span class="text-slate-300">Settings → Sheets</span>.

          </p>

        </div>



        <!-- Field 1: Do you run payroll? -->

        <div class="space-y-3">

          <div class="flex items-center gap-2">

            <span class="text-sm font-semibold text-white">Do you run payroll for employees?</span>

            <span class="group relative cursor-pointer text-slate-500 hover:text-slate-300" title="Includes SSS, PhilHealth, Pag-IBIG &amp; BIR Form 1601-C">

              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>

            </span>

          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="group-payroll">

            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="payroll" value="yes" @checked(old('payroll', $company['payroll'] ?? 'no') === 'yes')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">Yes</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-400">We pay salaried staff and file BIR 1601-C monthly.</span>

            </label>



            <label class="group relative flex flex-col p-4 rounded-xl border text-left transition-all choice-card cursor-pointer"><input type="radio" class="sr-only" name="payroll" value="no" @checked(old('payroll', $company['payroll'] ?? 'no') === 'no')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-white">No</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-300">Skip the Payroll &amp; Compensation sheet for now.</span>

            </label>

          </div>

        </div>



        <!-- Field 2: Withhold tax from suppliers? -->

        <div class="space-y-3">

          <div class="flex items-center gap-2">

            <span class="text-sm font-semibold text-white">Do you withhold tax from your suppliers?</span>

            <span class="group relative cursor-pointer text-slate-500 hover:text-slate-300" title="You issue BIR Form 2307 to suppliers">

              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>

            </span>

          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="group-supplier-tax">

            

            <label class="group relative flex flex-col p-4 rounded-xl border text-left transition-all choice-card cursor-pointer"><input type="radio" class="sr-only" name="supplier_tax" value="none" @checked(old('supplier_tax', $company['supplier_tax'] ?? 'none') === 'none')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-white">No, we don't</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-300">Vendors &amp; suppliers are paid in full amount.</span>

            </label>



            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="supplier_tax" value="income" @checked(old('supplier_tax', $company['supplier_tax'] ?? 'none') === 'income')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">Income tax only</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-400">Expanded withholding (EWT 1%, 2%, 5%, 10%).</span>

            </label>



            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="supplier_tax" value="vat" @checked(old('supplier_tax', $company['supplier_tax'] ?? 'none') === 'vat')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">VAT only</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-400">Withholding VAT (e.g. government contractor).</span>

            </label>



            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="supplier_tax" value="both" @checked(old('supplier_tax', $company['supplier_tax'] ?? 'none') === 'both')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">Both IT &amp; VAT</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-400">Expanded tax and withholding VAT simultaneously.</span>

            </label>



          </div>

        </div>



        <!-- Field 3: Do customers withhold tax from you? -->

        <div class="space-y-3">

          <div class="flex items-center gap-2">

            <span class="text-sm font-semibold text-white">Do your customers withhold tax from you?</span>

            <span class="group relative cursor-pointer text-slate-500 hover:text-slate-300" title="You receive Form 2307 CWT certificates from clients">

              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>

            </span>

          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="group-customer-tax">

            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="customer_tax" value="yes" @checked(old('customer_tax', $company['customer_tax'] ?? 'no') === 'yes')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">Yes</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-400">We receive 2307 certificates (Creditable Withholding Tax).</span>

            </label>



            <label class="group relative flex flex-col p-4 rounded-xl border text-left transition-all choice-card cursor-pointer"><input type="radio" class="sr-only" name="customer_tax" value="no" @checked(old('customer_tax', $company['customer_tax'] ?? 'no') === 'no')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-white">No</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-300">Customers pay our billings in full amount.</span>

            </label>

          </div>

        </div>

      </div>



      <!-- CARD 7: FIXED ASSETS (PPE) -->

      <div class="glass-card rounded-2xl p-6 sm:p-7 space-y-5">

        <div class="border-b border-slate-800/70 pb-4">

          <div class="flex items-center justify-between">

            <h2 class="text-lg font-bold text-white flex items-center gap-2">

              <span class="flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-400 font-mono text-xs font-bold border border-emerald-500/30">07</span>

              Fixed assets (Property, Plant &amp; Equipment)

            </h2>

            <span class="text-xs font-mono text-slate-400">Asset Depreciation</span>

          </div>

          <p class="mt-1 text-xs sm:text-sm text-slate-400">

            Track equipment, machinery, and office furniture item-by-item with monthly straight-line depreciation runs.

          </p>

        </div>



        <div class="space-y-3">

          <label class="block text-sm font-semibold text-white">Do you maintain PPE per item?</label>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" id="group-ppe">

            <label class="group relative flex flex-col p-4 rounded-xl border border-slate-800 bg-slate-950/40 text-left transition-all hover:border-slate-700 hover:bg-slate-900/60 choice-card cursor-pointer"><input type="radio" class="sr-only" name="ppe" value="yes" @checked(old('ppe', $company['ppe'] ?? 'no') === 'yes')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-slate-200 group-hover:text-white">Yes</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-400">Register each capital asset and run 1-click monthly depreciation.</span>

            </label>



            <label class="group relative flex flex-col p-4 rounded-xl border text-left transition-all choice-card cursor-pointer"><input type="radio" class="sr-only" name="ppe" value="no" @checked(old('ppe', $company['ppe'] ?? 'no') === 'no')>

              <div class="flex items-center justify-between w-full">

                <span class="font-bold text-sm text-white">No</span>

                <span aria-hidden="true" class="radio-indicator flex h-4 w-4 items-center justify-center rounded-full border border-slate-600 bg-slate-900"></span>

              </div>

              <span class="mt-1 text-xs text-slate-300">Record purchases as standard expense/asset without schedule.</span>

            </label>

          </div>

        </div>

      </div>



      <!-- BOTTOM SUBMISSION ACTION -->

      <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-slate-800/80">

        <div class="flex items-center gap-2 text-xs text-slate-400">

          <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">

            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>

          </svg>

          <span>All settings can be updated anytime in your company settings console.</span>

        </div>



        <button type="submit" id="btn-create" class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 rounded-xl bg-gradient-to-r from-emerald-400 via-teal-400 to-emerald-400 bg-[length:200%_auto] px-8 py-3.5 text-sm font-bold text-slate-950 shadow-xl shadow-emerald-500/25 transition-all hover:bg-[right_center] hover:shadow-emerald-500/35 hover:scale-[1.02] active:scale-[0.98] cursor-pointer">

          <span>Save company setup</span>

          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">

            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>

          </svg>

        </button>

      </div>



</form>

    </div>

  </main>

  





<dialog id="preview-info" aria-labelledby="preview-title" class="rounded-2xl border border-slate-700 bg-slate-900 p-7 text-slate-200 shadow-2xl">

    <h2 id="preview-title" class="text-xl font-bold text-white">Company setup preview</h2>

    <p class="mt-4 text-sm leading-relaxed">Your sample settings are stored in the local session. This is not a signed-in account. No tax forms or accounting books are generated. Exit demo clears the session data.</p>

    <form method="dialog"><button type="submit" class="mt-5 rounded-lg bg-emerald-400 px-5 py-2 text-sm font-bold text-slate-950">Got it</button></form>

</dialog>

</body></html>

