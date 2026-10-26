<!DOCTYPE html><html class="h-full" lang="en" style=""><head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>Sign In or Register · kontadurya.io</title>
<!-- Fonts & Icons -->
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400..700;1,6..72,400..700&amp;family=Plus+Jakarta+Sans:wght@400;500;600;700;800&amp;family=JetBrains+Mono:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script src="{{ asset('js/auth-config.js') }}"></script>
<link rel="stylesheet" href="{{ asset('css/auth.css') }}">
<script src="{{ asset('js/auth.js') }}" defer></script>
<script src="{{ asset('js/draft-leave-guard.js') }}?v={{ filemtime(public_path('js/draft-leave-guard.js')) }}" defer></script></head>
<body class="min-h-full spatial-bg flex flex-col justify-between selection:bg-[#00F5A0]/30 selection:text-white text-slate-100 antialiased">
<!-- Top Minimal Focused Navigation Bar -->
<header class="w-full border-b border-white/10 bg-[#030712]/90 backdrop-blur-xl sticky top-0 z-50">
<div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
<a class="flex items-center gap-3 group" href="{{ route('home') }}">
<div class="w-9 h-9 rounded-xl bg-gradient-to-br from-[#00F5A0] to-[#00D8F6] text-[#030712] flex items-center justify-center font-black shadow-[0_0_20px_rgba(0,245,160,0.4)] transition-transform group-hover:scale-105">
<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
<path d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>
</div>
<div class="flex items-baseline gap-1">
<span class="font-bold text-lg tracking-tight text-white">kontadurya</span>
<span class="text-xs font-mono font-semibold text-[#00F5A0]">.io</span>
</div>
</a>
<div class="flex items-center gap-4">
<div class="hidden sm:inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/35 text-emerald-300 text-xs font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse shadow-[0_0_8px_#34d399]"></span>
<span class="">100% Free Forever Core</span>
</div>
<a class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-200 hover:text-white transition-colors py-1.5 px-3.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 hover:border-white/20" href="{{ route('home') }}">
<span class="material-symbols-outlined text-[18px] text-[#00F5A0]">arrow_back</span>
<span class="hidden sm:inline">Back to kontadurya.io</span><span class="sm:hidden">Home</span>
</a>
</div>
</div>
</header>
<!-- Main Split / Focus Section -->
<main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-10 lg:py-14 flex items-center justify-center"><div class="w-full grid lg:grid-cols-12 gap-10 lg:gap-14 items-center">
<!-- Left Column: Neo-Spatial Value Prop & Editorial Trust -->
<div class="hidden lg:flex lg:col-span-6 flex-col justify-center space-y-8 pr-4">
<div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#00F5A0]/15 border border-[#00F5A0]/40 w-fit backdrop-blur-md">
<span class="w-2 h-2 rounded-full bg-[#00F5A0] shadow-[0_0_10px_#00F5A0] animate-pulse"></span>
<span class="text-xs font-bold text-[#00F5A0] uppercase tracking-wider font-mono-code">Philippine Compliance &amp; Modern Accounting</span>
</div>
<div>
<h1 class="text-4xl xl:text-5xl font-extrabold tracking-tight text-[#FFFFFF] leading-[1.18]">
        Double-entry ledger precision <span class="bg-gradient-to-r from-[#00FFCC] via-[#00F5A0] to-[#00D8F6] bg-clip-text text-transparent glow-header-text">made effortless.</span>
</h1>
<p class="mt-4 text-base xl:text-lg text-[#E2E8F0] font-medium leading-relaxed">
        Fast, zero-compromise bookkeeping built for growing businesses, accounting firms, and independent practitioners in the Philippines.
      </p>
</div>
<!-- Pillar Trust Points in Glass Panels -->
<div class="grid grid-cols-1 gap-4 pt-1">
<div class="flex items-start gap-4 p-[18px] rounded-2xl bg-[#090d1a]/85 border border-white/15 hover:border-[#00F5A0]/50 transition-all duration-300 shadow-lg shadow-black/40 group backdrop-blur-xl">
<div class="w-11 h-11 rounded-xl bg-[#00F5A0]/15 border border-[#00F5A0]/30 text-[#00F5A0] flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform shadow-[0_0_12px_rgba(0,245,160,0.15)]">
<span class="material-symbols-outlined text-[24px]">verified</span>
</div>
<div>
<p class="font-bold text-[15px] text-[#FFFFFF] tracking-tight group-hover:text-[#00F5A0] transition-colors">BIR-Ready Tax Frameworks</p>
<p class="text-[13px] text-[#CBD5E1] mt-1.5 leading-relaxed">Automate 2551Q, 1701Q, 2550Q, and VAT schedules with one-click export into compliant forms.</p>
</div>
</div>
<div class="flex items-start gap-4 p-[18px] rounded-2xl bg-[#090d1a]/85 border border-white/15 hover:border-cyan-400/50 transition-all duration-300 shadow-lg shadow-black/40 group backdrop-blur-xl">
<div class="w-11 h-11 rounded-xl bg-cyan-400/15 border border-cyan-400/30 text-cyan-300 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform shadow-[0_0_12px_rgba(34,211,238,0.15)]">
<span class="material-symbols-outlined text-[24px]">lock</span>
</div>
<div>
<p class="font-bold text-[15px] text-[#FFFFFF] tracking-tight group-hover:text-cyan-300 transition-colors">Append-Only Audit Trail</p>
<p class="text-[13px] text-[#CBD5E1] mt-1.5 leading-relaxed">Every journal entry and adjustment is timestamped and cryptographically immutable for zero-tamper integrity.</p>
</div>
</div>
<div class="flex items-start gap-4 p-[18px] rounded-2xl bg-[#090d1a]/85 border border-white/15 hover:border-emerald-400/50 transition-all duration-300 shadow-lg shadow-black/40 group backdrop-blur-xl">
<div class="w-11 h-11 rounded-xl bg-emerald-400/15 border border-emerald-400/30 text-emerald-300 flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform shadow-[0_0_12px_rgba(52,211,153,0.15)]">
<span class="material-symbols-outlined text-[24px]">savings</span>
</div>
<div>
<p class="font-bold text-[15px] text-[#FFFFFF] tracking-tight group-hover:text-emerald-300 transition-colors">100% Free Forever Core</p>
<p class="text-[13px] text-[#CBD5E1] mt-1.5 leading-relaxed">No mandatory credit card. Generous unlimited invoicing, ledgers, and reconciliations for independent operators.</p>
</div>
</div>
</div>
<!-- Mini Testimonial Quote -->
<div class="pt-4 border-t border-white/15 flex items-center gap-3.5">

<div>


</div>
</div>
</div>
<!-- Right Column: Neo-Spatial Authentication Card -->
<div class="lg:col-span-6 w-full max-w-lg mx-auto">
<div class="rounded-3xl p-7 sm:p-9 relative overflow-hidden bg-[#090d1a]/90 border border-white/15 backdrop-blur-2xl shadow-[0_20px_50px_rgba(0,0,0,0.6)]">
<!-- Ambient Controlled Background Glow Inside Card -->
<div class="absolute -top-24 -right-24 w-48 h-48 bg-[#00F5A0]/10 rounded-full blur-3xl pointer-events-none"></div>
<div class="absolute -bottom-24 -left-24 w-48 h-48 bg-[#00D8F6]/10 rounded-full blur-3xl pointer-events-none"></div>
<!-- Card Header -->
<div class="text-center sm:text-left mb-7 relative z-10">
<div class="lg:hidden inline-flex items-center gap-2 mb-4">
<div class="w-8 h-8 rounded-lg bg-gradient-to-br from-[#00F5A0] to-[#00D8F6] text-[#030712] flex items-center justify-center shadow-[0_0_15px_rgba(0,245,160,0.3)]">
<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
<path d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>
</div>
<span class="font-bold text-base tracking-tight text-white">kontadurya.io</span>
</div>
<a href="{{ route('login') }}" class="text-sm font-semibold text-[#00F5A0]">Sign in to a real account →</a>
<h2 id="auth-heading" class="text-[28px] font-bold text-[#FFFFFF] tracking-tight leading-snug">
          Continue to kontadurya.io
        </h2>
<p class="text-sm font-medium text-[#CBD5E1] mt-1.5">
          Sign in or sign up — it's the same quick step.
        </p>
</div>
@if ($errors->any())
<div role="alert" class="mb-4 rounded-xl border border-rose-400/40 bg-rose-400/10 p-3 text-sm text-rose-200">{{ $errors->first() }}</div>
@endif
<!-- Primary SSO Action: Google -->
<div class="space-y-4 relative z-10">
<form method="POST" action="{{ route('preview.start') }}">@csrf<input type="hidden" name="method" value="google"><button data-google-signin class="w-full flex items-center justify-center gap-3 px-5 py-3.5 border border-white/20 rounded-xl bg-white hover:bg-slate-100 text-[#030712] font-bold text-sm shadow-[0_4px_20px_rgba(0,0,0,0.35)] transition-all duration-150 hover:shadow-[0_0_25px_rgba(255,255,255,0.3)] active:scale-[0.99] group focus:outline-none focus:ring-2 focus:ring-[#00F5A0]" type="submit">
<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24">
<path d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.8-2.4 3.65v3.03h3.88c2.27-2.09 3.665-5.17 3.665-9.12Z" fill="#4285F4"></path>
<path d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.03c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.13C3.26 21.36 7.33 24 12 24Z" fill="#34A853"></path>
<path d="M5.28 14.29c-.25-.72-.38-1.49-.38-2.29s.13-1.57.38-2.29V6.57H1.25C.45 8.15 0 9.97 0 12s.45 3.85 1.25 5.43l4.03-3.14Z" fill="#FBBC05"></path>
<path d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.57l4.03 3.14c.95-2.83 3.6-4.96 6.72-4.96Z" fill="#EA4335"></path>
</svg>
<span class="text-slate-900 font-bold">Continue with Google</span>
</button></form>
<!-- Security Callout Box -->
<div class="rounded-xl border border-[#00F5A0]/35 bg-[#031512]/80 p-3.5 flex items-start gap-3 backdrop-blur-md shadow-inner">
<span class="material-symbols-outlined text-[#00F5A0] text-[20px] mt-0.5 shrink-0">shield</span>
<div class="text-xs text-[#E2E8F0] leading-snug">
<span class="font-bold text-[#00F5A0]">Google sign-in preview.</span> <span class="text-[#E2E8F0]">Opens the local setup demo. Google authentication is not connected.</span>
</div>
</div>
</div>
<!-- Divider -->
<div class="relative my-6 flex items-center justify-center">
<div class="border-t border-white/15 w-full"></div>
<span class="bg-[#0b1324] border border-[#00D8F6]/30 px-3.5 py-1 rounded-md text-[11px] font-mono-code font-bold uppercase tracking-wider text-cyan-300 shadow-[0_0_10px_rgba(0,216,246,0.15)] absolute">or direct access</span>
</div>
<!-- Secondary Flow: Direct Workspaces & Email -->
<div class="space-y-4 relative z-10">
<!-- Company / Workspace Login Section -->
<div class="space-y-2.5">
<label class="block text-xs font-semibold text-[#E2E8F0] tracking-tight" for="workspace-input">
            Company or Practice Workspace
          </label>
<form method="POST" action="{{ route('preview.start') }}" class="flex rounded-xl shadow-inner border border-white/20 focus-within:border-[#00F5A0] focus-within:ring-2 focus-within:ring-[#00F5A0]/40 bg-[#040814] overflow-hidden transition-all">
<span class="inline-flex items-center px-3.5 text-xs font-mono-code font-medium text-slate-300 bg-white/[0.07] border-r border-white/15 select-none">
              kontadurya.io/
            </span>
<input class="flex-1 min-w-0 block w-full px-3 py-2.5 text-xs sm:text-sm text-white placeholder:text-[#94A3B8] focus:outline-none border-0 bg-transparent font-mono-code" name="workspace" value="{{ old('workspace') }}" id="workspace-input" placeholder="firm-or-team" type="text" autocomplete="off" maxlength="63" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required aria-describedby="workspace-help">
<button data-workspace-signin class="px-4 py-2 bg-gradient-to-r from-[#00F5A0] to-[#00D8F6] hover:brightness-110 text-xs font-bold text-slate-950 border-l border-white/15 transition-all flex items-center gap-1 group shadow-[0_0_15px_rgba(0,245,160,0.3)] shrink-0" type="submit">
<span class="">Go</span>
<span class="material-symbols-outlined text-[15px] font-bold group-hover:translate-x-0.5 transition-transform">arrow_forward</span>
</button>
@csrf<input type="hidden" name="method" value="workspace"></form>
<p id="workspace-help" class="text-[11px] text-[#94A3B8] font-normal leading-normal">
            Use a sample workspace slug, such as firm-or-team, to preview company setup.
          </p>
</div>
<!-- Alternative: Direct Email Magic Link / Firm Account -->
<div class="pt-1">
<details @if($errors->has('identifier')) open @endif class="group rounded-xl border border-white/15 bg-white/[0.05] hover:border-white/25 transition-colors">
<summary class="flex cursor-pointer items-center justify-between p-3.5 text-xs font-semibold text-[#E2E8F0] hover:text-white">
<span class="flex items-center gap-2">
<span class="material-symbols-outlined text-[18px] text-[#00F5A0]">mail</span>
                Continue with Work Email or Username
              </span>
<span class="material-symbols-outlined text-[18px] text-slate-300 transition-transform duration-200 group-open:rotate-180">expand_more</span>
</summary>
<form method="POST" action="{{ route('preview.start') }}" data-email-form class="p-3.5 pt-1 border-t border-white/15 space-y-3 mt-1">@csrf<input type="hidden" name="method" value="email">
<div>
<label for="email-input" class="block text-[11px] font-semibold text-[#E2E8F0] mb-1.5">Work Email or Username</label>
<input class="w-full text-xs rounded-lg border border-white/20 bg-slate-950/90 px-3 py-2.5 text-white placeholder:text-[#94A3B8] focus:border-[#00F5A0] focus:ring-1 focus:ring-[#00F5A0] focus:outline-none font-mono-code" name="identifier" value="{{ old('identifier') }}" id="email-input" placeholder="name@company.ph" type="text" autocomplete="username" maxlength="254" required>
</div>
<button data-email-signin class="w-full py-2.5 px-3 bg-gradient-to-r from-[#00F5A0] to-[#00D8F6] hover:brightness-105 text-[#030712] text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-1.5 shadow-[0_0_20px_rgba(0,245,160,0.3)] active:scale-[0.99]" type="submit">
<span class="">Continue to setup</span>
<span class="material-symbols-outlined text-[15px] font-bold">send</span>
</button></form>
</details>
</div>
</div>
<p class="mt-5 text-center text-[11px] leading-relaxed text-slate-400">Demo access · These options open company setup without verifying your identity. No account is created.</p>
<!-- Card Footer & Legal Notes -->
<div class="mt-8 pt-5 border-t border-white/15 text-center relative z-10">
<p class="text-[11px] text-[#CBD5E1] leading-relaxed">
          Before account registration opens, review our
          <a class="underline text-white font-medium hover:text-[#00F5A0] transition-colors" href="#auth-help" data-auth-info="terms">Terms of Service</a> 
          and 
          <a class="underline text-white font-medium hover:text-[#00F5A0] transition-colors" href="#auth-help" data-auth-info="privacy">Privacy Policy</a>.
        </p>
<div class="mt-3.5 flex items-center justify-center gap-3 text-[11px] font-mono-code">
<span class="inline-flex items-center gap-1.5 text-[#00F5A0] font-semibold">
<span class="w-1.5 h-1.5 rounded-full bg-[#00F5A0] shadow-[0_0_8px_#00F5A0]"></span>
            Local Preview
          </span>
<span class="text-slate-500">•</span>
<span class="text-[#CBD5E1]">Manila, Philippines</span>
</div>
</div>
</div>
<!-- Help & Enterprise Contact Prompt -->
<div class="mt-5 text-center">
<p class="text-xs text-[#E2E8F0]">
        Need assisted onboarding for a certified audit firm?
        <a class="font-bold text-[#00FFCC] hover:text-[#00F5A0] hover:underline ml-1 transition-colors" href="#auth-help" data-auth-info="support">Contact Enterprise Support</a>
</p>
</div>
</div>
</div></main>
<!-- Global Footer -->
<footer class="w-full border-t border-white/10 bg-[#030712]/95 backdrop-blur-xl py-5 px-6">
<div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-[#94A3B8]">
<div class="flex items-center gap-2">
<span class="font-bold text-white">kontadurya<span class="text-[#00F5A0]">.io</span></span>
<span class="text-slate-600">|</span>
<span class="text-[#94A3B8]">© {{ date('Y') }}. Built for Philippine CPAs, Bookkeepers &amp; Businesses.</span>
</div>
<div class="flex items-center gap-6">
<a class="hover:text-white transition-colors" href="#auth-help" data-auth-info="security">Security Architecture</a>
<a class="hover:text-white transition-colors" href="#auth-help" data-auth-info="privacy">Data Sovereignty (DPA 2012)</a>
<a class="hover:text-white transition-colors" href="#auth-help" data-auth-info="support">Help Center</a>
</div>
</div>
</footer>


<dialog id="auth-help" aria-labelledby="auth-help-title" aria-describedby="auth-help-description" class="auth-dialog rounded-2xl border border-white/15 bg-[#090d1a] p-7 text-slate-100 shadow-2xl">
    <form method="dialog" class="flex justify-end">
        <button type="submit" aria-label="Close dialog" class="rounded-lg border border-white/15 px-3 py-1 text-xl text-slate-300 hover:text-white">×</button>
    </form>
    <p class="mt-2 font-mono-code text-[11px] uppercase tracking-widest text-[#00F5A0]">Kontadurya preview</p>
    <h2 id="auth-help-title" class="mt-3 text-2xl font-bold"></h2>
    <p id="auth-help-description" class="mt-4 text-sm leading-relaxed text-slate-300"></p>
    <form method="dialog"><button type="submit" class="mt-6 rounded-xl bg-gradient-to-r from-[#00F5A0] to-[#00D8F6] px-5 py-2.5 text-sm font-bold text-slate-950">Got it</button></form>
</dialog>
</body></html>
