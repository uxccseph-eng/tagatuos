<!DOCTYPE html><html class="scroll-smooth dark" lang="en"><head>
<meta charset="utf-8">
<meta content="width=device-width, initial-scale=1.0" name="viewport">
<title>kontadurya.io — The Autonomous Ledger for Philippine Enterprise</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com" rel="preconnect">
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&amp;family=JetBrains+Mono:wght@400;500;600;700&amp;family=Outfit:wght@400;500;600;700;800;900&amp;family=Newsreader:ital,opsz,wght@1,6..72,400;1,6..72,600&amp;display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<script src="{{ asset('js/app.js') }}" defer></script>
<meta name="description" content="A new way to manage Philippine business books. Explore the Kontadurya ledger, workflow, and free community tier.">
<script src="{{ asset('js/draft-leave-guard.js') }}?v={{ filemtime(public_path('js/draft-leave-guard.js')) }}" defer></script></head>
<body class="selection:bg-emerald-500/30 selection:text-emerald-300 relative bg-[#030712] text-slate-200">
<!-- Ambient Bioluminescent Background Aura -->
<div class="fixed inset-0 pointer-events-none cyber-grid z-0"></div>
<div class="fixed -top-40 left-1/4 h-[650px] w-[650px] glow-emerald-spot pointer-events-none z-0 blur-3xl opacity-60"></div>
<div class="fixed top-1/3 -right-40 h-[600px] w-[600px] glow-violet-spot pointer-events-none z-0 blur-3xl opacity-50"></div>
<div class="fixed bottom-10 left-[-100px] h-[700px] w-[700px] glow-cyan-spot pointer-events-none z-0 blur-3xl opacity-40"></div>
<!-- Neo Navigation Bar -->
<a class="skip-link" href="#main">Skip to content</a>
<header class="sticky top-0 z-50 border-b border-white/[0.08] bg-[#030712]/75 backdrop-blur-2xl">
<div class="mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4 sm:px-10">
<div class="flex min-w-0 items-center gap-8">
<a class="group flex items-center gap-3 active" href="#top">
<!-- Holographic Node Symbol -->
<div class="relative flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-emerald-500 via-teal-400 to-cyan-400 p-[1px] shadow-[0_0_20px_rgba(16,185,129,0.5)]">
<div class="flex h-full w-full items-center justify-center rounded-[11px] bg-[#030712]">
<span class="font-mono-tech font-extrabold text-sm text-emerald-400 group-hover:scale-110 transition-transform">k/</span>
</div>
</div>
<span class="font-heading text-[21px] font-bold tracking-tight text-white">
            kontadurya<span class="text-emerald-400">.io</span>
</span>

</a>
<nav aria-label="Primary" class="hidden xl:flex items-center gap-5 text-[14px] font-medium text-slate-400">
<a class="transition-colors hover:text-emerald-400 flex items-center gap-1" href="#features">Features</a>
<a class="transition-colors hover:text-emerald-400" href="#how">Autonomous Flow</a>
<a class="transition-colors hover:text-emerald-400" href="#pricing">Zero Tier</a>
<a class="transition-colors hover:text-emerald-400" href="#showcase">Spatial Ledger</a>
<a class="transition-colors hover:text-emerald-400" href="#faq">Protocol FAQ</a>
</nav>
</div>
<div class="flex shrink-0 items-center gap-3">
<a class="hidden sm:inline-flex text-[14px] font-medium text-slate-300 hover:text-white transition-colors px-3 py-2" href="{{ route('login') }}">
          Terminal Login
        </a>
<a class="relative inline-flex items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-emerald-500 via-teal-400 to-cyan-400 px-5 py-2.5 text-[14px] font-bold text-slate-950 shadow-[0_0_25px_rgba(16,185,129,0.4)] transition-all hover:shadow-[0_0_35px_rgba(16,185,129,0.7)] hover:brightness-110" href="{{ route('register') }}">
<span class="launch-label"><span class="hidden sm:inline">Launch Free Node</span><span class="sm:hidden">Start Free</span></span>
<span class="font-mono-tech text-xs">→</span>
</a>
<button type="button" class="mobile-menu-toggle rounded-lg border border-white/15 p-2 text-emerald-300 xl:hidden" aria-label="Open navigation" aria-controls="mobile-menu" aria-expanded="false" data-menu-toggle>
    <span class="material-symbols-outlined" aria-hidden="true">menu</span>
</button></div>
</div>
<nav id="mobile-menu" aria-label="Mobile" class="border-t border-white/10 bg-slate-950 px-6 py-5 xl:hidden" hidden>
    <div class="flex flex-col gap-4 text-sm text-slate-300">
        <a href="#features">Features</a>
        <a href="#how">Autonomous Flow</a>
        <a href="#pricing">Zero Tier</a>
        <a href="#showcase">Spatial Ledger</a>
        <a href="#faq">Protocol FAQ</a>
        <a href="#signup" class="text-emerald-300">Try the starter demo →</a>
    </div>
</nav>
</header>
<main class="relative z-10" id="main">
<!-- Spatial Canvas Hero Section -->
<section class="relative pt-20 pb-24 lg:pt-32 lg:pb-32 overflow-hidden" id="top">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10 text-center relative z-20">
<div class="mx-auto max-w-5xl">
<!-- Bioluminescent Live Pill -->
<div class="inline-flex items-center gap-3 rounded-full border border-emerald-500/30 bg-emerald-950/40 px-4 py-1.5 shadow-[0_0_15px_rgba(16,185,129,0.2)] backdrop-blur-xl">
<span class="relative flex h-2 w-2">
<span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
<span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
</span>
<span class="font-mono-tech text-xs font-semibold uppercase tracking-[0.15em] text-emerald-300">
              The Autonomous Ledger for Philippine Enterprise
            </span>
</div>
<!-- Hero Typography -->
<h1 class="font-heading font-black text-white mt-8 text-balance text-[clamp(2.75rem,7vw,6.25rem)] leading-[0.94] tracking-[-0.04em]">Accounting is broken.<br>
<span class="text-glow-gradient font-light italic font-editorial">We derived</span> the cure.</h1>
<p class="mx-auto mt-7 max-w-[660px] text-base leading-relaxed text-slate-400 sm:text-[20px]">
            Frictionless double-entry accounting engineered for Filipino creators, freelancers, and growing firms. Instant BIR readiness with zero friction, 100% free.
          </p>
<!-- Action Pod -->
<div class="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
<a class="w-full sm:w-auto inline-flex items-center justify-center gap-3 rounded-xl bg-gradient-to-r from-emerald-400 via-teal-300 to-cyan-400 px-8 py-4 text-[15px] font-bold text-slate-950 shadow-[0_0_35px_rgba(16,185,129,0.45)] transition-all hover:scale-105 hover:shadow-[0_0_50px_rgba(16,185,129,0.7)]" href="{{ route('register') }}">
<span class="">Start Accounting for Free</span>
<span class="font-mono-tech text-sm">⚡</span>
</a>
<a class="w-full sm:w-auto inline-flex items-center justify-center gap-2.5 rounded-xl border border-white/15 bg-white/[0.04] px-7 py-4 text-[15px] font-semibold text-slate-200 backdrop-blur-xl transition-all hover:bg-white/[0.1] hover:border-white/30" href="#how">
<span class="material-symbols-outlined text-[18px] text-cyan-400">deployed_code</span>
              Explore Neural Ledger
            </a>
</div>
<!-- Live Compliance Specs -->
<div class="mt-10 flex flex-wrap items-center justify-center gap-x-8 gap-y-3 font-mono-tech text-xs text-slate-400">
<span class="inline-flex items-center gap-2">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_#10B981]"></span>
              BIR eFPS &amp; eBIRForms XML Certified
            </span>
<span class="inline-flex items-center gap-2">
<span class="h-1.5 w-1.5 rounded-full bg-cyan-400 shadow-[0_0_8px_#06B6D4]"></span>
              Zero Cloud Subscriptions · Free Forever
            </span>
<span class="inline-flex items-center gap-2">
<span class="h-1.5 w-1.5 rounded-full bg-violet-400 shadow-[0_0_8px_#8B5CF6]"></span>
              SHA-256 Immutable Double Entry
            </span>
</div>
</div>
</div>
<p class="mx-auto mt-10 px-6 text-center font-mono-tech text-xs text-slate-500">Interactive product preview · Sample data</p><p class="sr-only" role="status" data-ledger-status></p>
<!-- 3D Spatial Glassmorphism Ledger Canvas (Interactive Showcase) -->
<div class="mx-auto w-full max-w-7xl px-4 sm:px-8 pt-14 lg:pt-20 perspective-canvas" id="showcase">
<div class="tilt-3d relative mx-auto w-full max-w-5xl rounded-[28px] p-2 bg-gradient-to-b from-white/20 via-emerald-500/20 to-cyan-500/10 shadow-[0_30px_100px_rgba(0,0,0,0.8)] backdrop-blur-3xl">
<!-- Outer Glass Enclosure -->
<div class="relative overflow-hidden rounded-[24px] bg-[#070E22]/90 border border-white/15 p-4 sm:p-7">
<!-- Terminal Header -->
<div class="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 pb-4">
<div class="flex items-center gap-3">
<div class="flex gap-2">
<span class="h-3 w-3 rounded-full bg-rose-500/70 border border-rose-400/50 shadow-[0_0_6px_rgba(244,63,94,0.5)]"></span>
<span class="h-3 w-3 rounded-full bg-amber-500/70 border border-amber-400/50 shadow-[0_0_6px_rgba(245,158,11,0.5)]"></span>
<span class="h-3 w-3 rounded-full bg-emerald-500/70 border border-emerald-400/50 shadow-[0_0_6px_rgba(16,185,129,0.5)]"></span>
</div>
<div class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-slate-900/60 px-3 py-1 font-mono-tech text-[12px] text-emerald-300">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                  <span data-ledger-path>mesh://ph-bir.node.kontadurya.io/v4/sales-matrix</span>
                </div>
</div>
<div class="flex items-center gap-3 font-mono-tech text-xs">
<span class="inline-flex items-center gap-1.5 rounded-md bg-white/[0.05] border border-white/10 px-3 py-1 text-slate-300">
                  Tax Cycle: <strong>Q4 2026</strong>
</span>
<span class="rounded-md bg-emerald-500/15 border border-emerald-500/30 px-3 py-1 text-emerald-400 font-semibold">
                  Ties Out 100%
                </span>
</div>
</div>
<!-- Spreadsheet Grid & Realtime Derivation Pipeline -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
<!-- Left 8 Cols: Interactive Glass Grid -->
<div class="lg:col-span-8 overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60 backdrop-blur-xl">
<!-- Navigation Tabs -->
<div class="flex items-center gap-1 border-b border-white/10 px-4 pt-3 bg-white/[0.02]">
<button type="button" class="ledger-tab border-b-2 border-emerald-400 bg-emerald-500/10 px-4 py-2 text-xs font-mono-tech font-bold text-emerald-300 rounded-t-lg" data-ledger-tab="sales" aria-pressed="true">Sales Grid (001)</button>
<button type="button" class="ledger-tab border-b-2 border-transparent px-4 py-2 text-xs font-mono-tech font-medium text-slate-400 hover:text-slate-200" data-ledger-tab="disbursements" aria-pressed="false">Disbursements</button>
<button type="button" class="ledger-tab border-b-2 border-transparent px-4 py-2 text-xs font-mono-tech font-medium text-slate-400 hover:text-slate-200" data-ledger-tab="payroll" aria-pressed="false">Payroll &amp; Withholding</button>
<span class="ml-auto font-mono-tech text-[11px] text-slate-500 pr-2">Live Derivation Active</span>
</div>
<!-- Table Content -->
<div class="overflow-x-auto">
<table class="w-full text-left text-xs font-mono-tech">
<thead>
<tr class="border-b border-white/10 bg-white/[0.03] text-slate-400">
<th class="py-3 px-4 font-semibold text-[11px]">Timestamp</th>
<th class="py-3 px-4 font-semibold text-[11px]">Entity / Counterparty</th>
<th class="py-3 px-4 font-semibold text-right text-[11px]">Gross (PHP)</th>
<th data-tax-heading class="py-3 px-4 font-semibold text-right text-[11px]">Output VAT (12%)</th>
<th class="py-3 px-4 font-semibold text-center text-[11px]">Ledger Sync</th>
</tr>
</thead>
<tbody data-ledger-rows class="divide-y divide-white/5 text-slate-300">
<tr class="hover:bg-white/[0.02] transition-colors">
<td class="py-3.5 px-4 text-slate-400">Oct 16 · 09:12</td>
<td class="py-3.5 px-4 font-sans font-medium text-white flex items-center gap-2">
<span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                          Cruz Dental Clinic
                        </td>
<td class="py-3.5 px-4 text-right text-emerald-300">₱10,000.00</td>
<td class="py-3.5 px-4 text-right text-slate-400">₱1,071.43</td>
<td class="py-3.5 px-4 text-center">
<span class="inline-flex rounded-full bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 text-[10px] text-emerald-400 font-semibold">
                            BALANCED
                          </span>
</td>
</tr>
<tr class="hover:bg-white/[0.02] transition-colors">
<td class="py-3.5 px-4 text-slate-400">Oct 15 · 14:40</td>
<td class="py-3.5 px-4 font-sans font-medium text-white flex items-center gap-2">
<span class="h-2 w-2 rounded-full bg-cyan-400"></span>
                          Santos Trading Co.
                        </td>
<td class="py-3.5 px-4 text-right text-emerald-300">₱25,000.00</td>
<td class="py-3.5 px-4 text-right text-slate-400">₱2,678.57</td>
<td class="py-3.5 px-4 text-center">
<span class="inline-flex rounded-full bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 text-[10px] text-emerald-400 font-semibold">
                            BALANCED
                          </span>
</td>
</tr>
<!-- Active Neural Line -->
<tr class="bg-gradient-to-r from-emerald-500/15 via-teal-500/10 to-violet-500/15 border-l-2 border-emerald-400">
<td class="py-3.5 px-4 text-emerald-300 font-bold">Live Stream</td>
<td class="py-3.5 px-4 font-sans font-bold text-white flex items-center gap-2">
<span class="h-2.5 w-2.5 rounded-full bg-amber-400 animate-ping"></span>
                          Lopez Catering Hub
                        </td>
<td class="py-3.5 px-4 text-right font-bold text-white">₱18,000.00</td>
<td class="py-3.5 px-4 text-right font-bold text-emerald-300 shadow-sm">
                          ₱1,928.57
                        </td>
<td class="py-3.5 px-4 text-center">
<span class="inline-flex items-center gap-1 rounded-full bg-cyan-500/20 border border-cyan-400/40 px-2 py-0.5 text-[10px] text-cyan-300 font-bold animate-pulse">
                            SYNTHESIZING...
                          </span>
</td>
</tr>
</tbody>
</table>
</div>
<!-- Live Derived Strip -->
<div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 bg-slate-900/80 px-4 py-3 font-mono-tech text-xs text-slate-400">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-[16px] text-emerald-400">auto_awesome</span>
<span data-net-label>Autonomously calculated net: <strong class="text-white">₱16,071.43</strong></span>
</div>
<div class="flex items-center gap-3">
<span data-withholding-label class="text-violet-400 font-semibold">EWT 2%: ₱321.43</span>
<button type="button" data-confirm-vector class="rounded-lg bg-emerald-500 px-3 py-1 font-bold text-slate-950 hover:bg-emerald-400 transition-colors">
                      Confirm Vector
                    </button>
</div>
</div>
</div>
<!-- Right 4 Cols: Real-time BIR Artifact Compiler -->
<div class="lg:col-span-4 flex flex-col gap-4">
<div class="rounded-2xl border border-white/10 bg-gradient-to-b from-white/[0.06] to-transparent p-5 backdrop-blur-xl">
<div class="flex items-center justify-between pb-3 border-b border-white/10">
<span class="font-mono-tech text-xs uppercase tracking-wider text-emerald-300 font-bold flex items-center gap-2">
<span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                      BIR Output Nodes
                    </span>
<span class="font-mono-tech text-[10px] text-slate-400">XML Ready</span>
</div>
<div class="mt-4 space-y-3 font-mono-tech text-xs">
<!-- Artifact 1 -->
<div class="group flex items-center justify-between rounded-xl border border-white/5 bg-slate-950/40 p-3 hover:border-emerald-500/30 transition-all">
<div class="flex items-center gap-3">
<div class="h-8 w-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
<span class="material-symbols-outlined text-sm">description</span>
</div>
<div>
<div class="font-bold text-white">BIR 2550Q</div>
<div class="text-[10px] text-slate-400">Quarterly VAT Return</div>
</div>
</div>
<span class="rounded bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400">eBIR XML</span>
</div>
<!-- Artifact 2 -->
<div class="group flex items-center justify-between rounded-xl border border-white/5 bg-slate-950/40 p-3 hover:border-cyan-500/30 transition-all">
<div class="flex items-center gap-3">
<div class="h-8 w-8 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
<span class="material-symbols-outlined text-sm">database</span>
</div>
<div>
<div class="font-bold text-white">SLSP Validation</div>
<div class="text-[10px] text-slate-400">Relief .DAT Matrices</div>
</div>
</div>
<span class="rounded bg-cyan-500/10 px-2 py-0.5 text-[10px] font-bold text-cyan-300">VALIDATED</span>
</div>
<!-- Artifact 3 -->
<div class="group flex items-center justify-between rounded-xl border border-white/5 bg-slate-950/40 p-3 hover:border-violet-500/30 transition-all">
<div class="flex items-center gap-3">
<div class="h-8 w-8 rounded-lg bg-violet-500/10 border border-violet-500/20 flex items-center justify-center text-violet-400">
<span class="material-symbols-outlined text-sm">receipt_long</span>
</div>
<div>
<div class="font-bold text-white">Form 1601-EQ</div>
<div class="text-[10px] text-slate-400">Creditable Withholding</div>
</div>
</div>
<span class="rounded bg-violet-500/10 px-2 py-0.5 text-[10px] font-bold text-violet-300">AUTO-POST</span>
</div>
</div>
<div class="mt-4 pt-3 border-t border-white/10 text-center">
<span class="font-mono-tech text-[11px] text-emerald-300/80 flex items-center justify-center gap-1.5">
<span class="material-symbols-outlined text-[14px]">lock</span>
                      Zero Re-keying · Instant E-Filing Export
                    </span>
</div>
</div>
<!-- Floating Micro Orb Metric -->
<div class="glass-canvas-card rounded-2xl p-4 flex items-center justify-between">
<div class="flex items-center gap-3">
<div class="h-10 w-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 border border-emerald-400/30 flex items-center justify-center text-emerald-300 font-mono-tech font-bold text-base">
                      Σ
                    </div>
<div>
<div class="text-[11px] text-slate-400 font-mono-tech uppercase">Trial Balance Integrity</div>
<div class="text-sm font-bold text-white">Debits = Credits Tied</div>
</div>
</div>
<span class="h-3 w-3 rounded-full bg-emerald-400 shadow-[0_0_12px_#10B981]"></span>
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<!-- Bioluminescent Marquee Matrix -->
<section class="border-y border-white/[0.08] bg-slate-950/80 backdrop-blur-md py-4 overflow-hidden relative">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10 mb-3 flex items-center gap-4">
<span class="whitespace-nowrap font-mono-tech text-[11px] uppercase tracking-[0.2em] text-emerald-400 font-bold flex items-center gap-2">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
          Autonomous Functional Matrix
        </span>
<span class="h-px flex-1 bg-gradient-to-r from-emerald-500/30 via-cyan-500/20 to-transparent"></span>
<span class="whitespace-nowrap font-mono-tech text-[11px] text-slate-400">20 Financial Core Tasks · Zero Friction</span>
</div>
<div class="overflow-hidden py-1">
<div class="animate-marquee flex items-center gap-4">
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_#10B981]"></span>Sales Invoicing
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-cyan-400 shadow-[0_0_6px_#06B6D4]"></span>Disbursements &amp; Expenses
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-violet-400 shadow-[0_0_6px_#8B5CF6]"></span>Automatic 12% VAT
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_#10B981]"></span>EWT Per-Line Computation
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-cyan-400 shadow-[0_0_6px_#06B6D4]"></span>eBIRForms XML Generation
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-rose-400 shadow-[0_0_6px_#FB7185]"></span>BIR Relief DAT Files
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_#10B981]"></span>Loose-Leaf Journals
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-violet-400 shadow-[0_0_6px_#8B5CF6]"></span>2550Q / 2551Q Returns
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-cyan-400 shadow-[0_0_6px_#06B6D4]"></span>Auditable Append-Only Ledger
          </span>
<!-- Seamless Duplicate Loop -->
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_#10B981]"></span>Sales Invoicing
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-cyan-400 shadow-[0_0_6px_#06B6D4]"></span>Disbursements &amp; Expenses
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-violet-400 shadow-[0_0_6px_#8B5CF6]"></span>Automatic 12% VAT
          </span>
<span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 text-xs font-mono-tech text-slate-200 backdrop-blur-md">
<span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_#10B981]"></span>EWT Per-Line Computation
          </span>
</div>
</div>
</section>
<!-- Core Philosophy: Ethereal Deep Glass Stats -->
<section class="relative py-24 sm:py-32" id="features">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10">
<div class="grid grid-cols-1 gap-10 lg:grid-cols-2 lg:items-end">
<div>
<div class="inline-flex items-center gap-2 font-mono-tech text-xs uppercase tracking-[0.2em] text-emerald-400 font-bold">
<span class="material-symbols-outlined text-[15px]">verified_user</span>
              Pure Transparent Architecture
            </div>
<h2 class="font-heading text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight text-white mt-4">
              Not a black box.<br>
<span class="text-violet-gradient">Mathematical certainty.</span>
</h2>
</div>
<p class="text-lg leading-relaxed text-slate-400">
            Every peso traces straight to an immutable, balanced double-entry ledger. Your BIR filings reflect raw accounting truth without interpolated data or black-box math.
          </p>
</div>
<!-- 4 Holographic Quantum Pillars -->
<div class="mt-16 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
<div class="glass-canvas-card rounded-2xl p-7 relative overflow-hidden group hover:border-emerald-500/40 transition-all">
<div class="absolute -top-10 -right-10 w-32 h-32 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition-all"></div>
<div class="font-heading text-5xl font-black text-white group-hover:text-emerald-300 transition-colors">16+</div>
<h3 class="mt-3 font-semibold text-white text-base">BIR Forms Generated</h3>
<p class="mt-2 text-xs leading-relaxed text-slate-400 font-mono-tech">
              Valid eBIRForms XML ready for submission without re-encoding.
            </p>
<div class="mt-6 flex items-center gap-1.5 text-[11px] font-mono-tech text-emerald-400">
<span class="">0% Error Rate</span>
<span class="">→</span>
</div>
</div>
<div class="glass-canvas-card rounded-2xl p-7 relative overflow-hidden group hover:border-cyan-500/40 transition-all">
<div class="absolute -top-10 -right-10 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl group-hover:bg-cyan-500/20 transition-all"></div>
<div class="font-heading text-5xl font-black text-white group-hover:text-cyan-300 transition-colors">7</div>
<h3 class="mt-3 font-semibold text-white text-base">Loose-Leaf Books</h3>
<p class="mt-2 text-xs leading-relaxed text-slate-400 font-mono-tech">
              General ledger, journals, cash receipts &amp; disbursements to statutory spec.
            </p>
<div class="mt-6 flex items-center gap-1.5 text-[11px] font-mono-tech text-cyan-300">
<span class="">Audit Certified</span>
<span class="">→</span>
</div>
</div>
<div class="glass-canvas-card rounded-2xl p-7 relative overflow-hidden group hover:border-violet-500/40 transition-all">
<div class="absolute -top-10 -right-10 w-32 h-32 bg-violet-500/10 rounded-full blur-2xl group-hover:bg-violet-500/20 transition-all"></div>
<div class="font-heading text-4xl sm:text-5xl font-black text-white group-hover:text-violet-300 transition-colors">Per Line</div>
<h3 class="mt-3 font-semibold text-white text-base">Automatic Tax Synthesis</h3>
<p class="mt-2 text-xs leading-relaxed text-slate-400 font-mono-tech">
              Output VAT, Percentage Tax, and Withholding tax parsed with zero user formula setup.
            </p>
<div class="mt-6 flex items-center gap-1.5 text-[11px] font-mono-tech text-violet-300">
<span class="">Deterministic</span>
<span class="">→</span>
</div>
</div>
<div class="glass-canvas-card rounded-2xl p-7 relative overflow-hidden group hover:border-emerald-500/40 transition-all">
<div class="absolute -top-10 -right-10 w-32 h-32 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition-all"></div>
<div class="font-heading text-4xl sm:text-5xl font-black text-white group-hover:text-emerald-300 transition-colors">Append</div>
<h3 class="mt-3 font-semibold text-white text-base">Tamper-Proof Audit Trail</h3>
<p class="mt-2 text-xs leading-relaxed text-slate-400 font-mono-tech">
              Immutable journal entries. Never destructively overwritten, keeping you safe in any BIR audit.
            </p>
<div class="mt-6 flex items-center gap-1.5 text-[11px] font-mono-tech text-emerald-300">
<span class="">Cryptographic Safety</span>
<span class="">→</span>
</div>
</div>
</div>
</div>
</section>
<!-- Autonomous Flow: 4 Neon Nodes Timeline -->
<section class="scroll-mt-24 py-24 sm:py-32 relative" id="how">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10">
<div class="grid items-end gap-8 pb-16 lg:grid-cols-2 lg:gap-14">
<div>
<div class="inline-flex items-center gap-2 font-mono-tech text-xs uppercase tracking-[0.2em] text-emerald-400 font-bold">
<span class="material-symbols-outlined text-[15px]">timeline</span>
              Protocol Sequence
            </div>
<h2 class="font-heading font-black tracking-tight text-white text-4xl sm:text-5xl lg:text-6xl mt-4">
              From First Entry to Filed
            </h2>
</div>
<p class="text-lg leading-relaxed text-slate-400">
            Four frictionless steps from a cold start to filing-ready tax packs. Zero offline spreadsheets. Zero manual calculator errors.
          </p>
</div>
<div class="space-y-12">
<!-- Node 01 -->
<div class="glass-canvas-card rounded-3xl p-8 sm:p-12 relative overflow-hidden border border-white/10">
<div class="grid lg:grid-cols-12 gap-8 items-center">
<div class="lg:col-span-5">
<span class="font-mono-tech text-5xl sm:text-6xl font-black text-emerald-400/30">01 // 04</span>
<h3 class="font-heading text-2xl sm:text-3xl font-bold text-white mt-3">
                  Autonomous Entity Configuration
                </h3>
<p class="mt-4 text-slate-400 text-sm sm:text-base leading-relaxed">
                  Provide your business profile (Sole Proprietor, Professional, or Corporation) and registration type (VAT or Non-VAT). Kontadurya auto-synthesizes your Chart of Accounts, document prefixes, and BIR tax schedule presets instantly.
                </p>
<div class="mt-6 flex items-center gap-2 font-mono-tech text-xs text-emerald-400">
<span class="material-symbols-outlined text-sm">bolt</span>
                  Instant COA preset deployment
                </div>
</div>
<div class="lg:col-span-7 bg-slate-950/80 rounded-2xl border border-white/10 p-5 font-mono-tech text-xs">
<div class="flex items-center justify-between pb-3 border-b border-white/10 text-slate-400">
<span class="flex items-center gap-2">
<span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                    Chart of Accounts Engine
                  </span>
<span class="text-emerald-400">READY</span>
</div>
<div class="mt-4 space-y-2">
<div class="flex justify-between p-2 rounded bg-white/[0.02]">
<span class="text-slate-400">1010</span>
<span class="text-white font-sans">Cash in Philippine Bank (BDO / BPI)</span>
<span class="text-emerald-400">Asset</span>
</div>
<div class="flex justify-between p-2 rounded bg-white/[0.02]">
<span class="text-slate-400">2020</span>
<span class="text-white font-sans">Output Tax (12% VAT Payable)</span>
<span class="text-amber-400">Liability</span>
</div>
<div class="flex justify-between p-2 rounded bg-white/[0.02]">
<span class="text-slate-400">4010</span>
<span class="text-white font-sans">Professional Service Revenue</span>
<span class="text-cyan-400">Income</span>
</div>
</div>
</div>
</div>
</div>
<!-- Node 02 -->
<div class="glass-canvas-card rounded-3xl p-8 sm:p-12 relative overflow-hidden border border-white/10">
<div class="grid lg:grid-cols-12 gap-8 items-center">
<div class="lg:col-span-5 lg:order-2">
<span class="font-mono-tech text-5xl sm:text-6xl font-black text-cyan-400/30">02 // 04</span>
<h3 class="font-heading text-2xl sm:text-3xl font-bold text-white mt-3">
                  Encode on Streamlined Glass Grids
                </h3>
<p class="mt-4 text-slate-400 text-sm sm:text-base leading-relaxed">
                  Record simple business transactions without accounting jargon. Enter what customer paid or supplier charged. Kontadurya parses VATable portions, exempt amounts, and tax credits on every single row.
                </p>
<div class="mt-6 flex items-center gap-2 font-mono-tech text-xs text-cyan-400">
<span class="material-symbols-outlined text-sm">psychology</span>
                  Automatic withholding &amp; VAT derivation
                </div>
</div>
<div class="lg:col-span-7 lg:order-1 bg-slate-950/80 rounded-2xl border border-white/10 p-5 font-mono-tech text-xs">
<div class="flex items-center justify-between pb-3 border-b border-white/10 text-slate-400">
<span class="text-white font-sans font-semibold">Row Input Demonstration</span>
<span class="text-cyan-400 font-mono-tech">Auto-Parsing Active</span>
</div>
<div class="mt-4 p-4 rounded-xl bg-gradient-to-r from-cyan-950/30 to-slate-900 border border-cyan-500/20">
<div class="text-[11px] text-slate-400">Input Given:</div>
<div class="text-sm font-bold text-white mt-1">Invoice: ₱50,000.00 Gross to Tech Corp</div>
<div class="mt-3 grid grid-cols-3 gap-2 pt-3 border-t border-white/10 text-center">
<div>
<div class="text-[10px] text-slate-400">Net Revenue</div>
<div class="text-emerald-300 font-bold mt-0.5">₱44,642.86</div>
</div>
<div>
<div class="text-[10px] text-slate-400">Output VAT (12%)</div>
<div class="text-cyan-300 font-bold mt-0.5">₱5,357.14</div>
</div>
<div>
<div class="text-[10px] text-slate-400">EWT 2%</div>
<div class="text-violet-300 font-bold mt-0.5">₱892.86</div>
</div>
</div>
</div>
</div>
</div>
</div>
<!-- Node 03 -->
<div class="glass-canvas-card rounded-3xl p-8 sm:p-12 relative overflow-hidden border border-white/10">
<div class="grid lg:grid-cols-12 gap-8 items-center">
<div class="lg:col-span-5">
<span class="font-mono-tech text-5xl sm:text-6xl font-black text-violet-400/30">03 // 04</span>
<h3 class="font-heading text-2xl sm:text-3xl font-bold text-white mt-3">
                  Cryptographically Balanced Ledgers
                </h3>
<p class="mt-4 text-slate-400 text-sm sm:text-base leading-relaxed">
                  Every confirmed transaction logs into an immutable append-only journal. Debits precisely equal credits on every node. Your trial balance never drifts out of balance, providing watertight auditability.
                </p>
<div class="mt-6 flex items-center gap-2 font-mono-tech text-xs text-violet-400">
<span class="material-symbols-outlined text-sm">lock</span>
                  Append-only immutable record
                </div>
</div>
<div class="lg:col-span-7 bg-slate-950/80 rounded-2xl border border-white/10 p-5 font-mono-tech text-xs">
<div class="flex items-center justify-between pb-3 border-b border-white/10 text-slate-400">
<span class="text-violet-300 font-mono-tech">JE-8829 · Double Entry Verification</span>
<span class="text-emerald-400 font-semibold">BALANCED (0.00 DIFFERENCE)</span>
</div>
<div class="mt-4 space-y-2">
<div class="flex justify-between items-center p-2 rounded bg-white/[0.02]">
<span class="text-white">Accounts Receivable (Gross)</span>
<span class="text-emerald-300">DR ₱50,000.00</span>
</div>
<div class="flex justify-between items-center p-2 rounded bg-white/[0.02]">
<span class="text-slate-300 pl-4">→ Service Revenue</span>
<span class="text-slate-400">CR ₱44,642.86</span>
</div>
<div class="flex justify-between items-center p-2 rounded bg-white/[0.02]">
<span class="text-slate-300 pl-4">→ Output VAT Payable</span>
<span class="text-slate-400">CR ₱5,357.14</span>
</div>
</div>
</div>
</div>
</div>
<!-- Node 04 -->
<div class="glass-canvas-card rounded-3xl p-8 sm:p-12 relative overflow-hidden border border-white/10">
<div class="grid lg:grid-cols-12 gap-8 items-center">
<div class="lg:col-span-5 lg:order-2">
<span class="font-mono-tech text-5xl sm:text-6xl font-black text-emerald-400/30">04 // 04</span>
<h3 class="font-heading text-2xl sm:text-3xl font-bold text-white mt-3">
                  One-Click BIR Compilation &amp; E-Filing
                </h3>
<p class="mt-4 text-slate-400 text-sm sm:text-base leading-relaxed">
                  Tax returns are continuously compiled as you work. Download ready-to-upload XML files for eBIRForms, pre-formatted Relief DAT schedules, or invite your CPA to review with zero spreadsheet preparation.
                </p>
<div class="mt-6 flex items-center gap-2 font-mono-tech text-xs text-emerald-400">
<span class="material-symbols-outlined text-sm">cloud_upload</span>
                  Valid XML output formatted for BIR upload
                </div>
</div>
<div class="lg:col-span-7 lg:order-1 bg-slate-950/80 rounded-2xl border border-white/10 p-5 font-mono-tech text-xs">
<div class="flex items-center justify-between pb-3 border-b border-white/10 text-slate-400">
<span class="text-white font-sans font-semibold">Tax Compliance Synthesis Pack</span>
<span class="text-emerald-400">READY TO DOWNLOAD</span>
</div>
<div class="mt-4 grid grid-cols-2 gap-3">
<div class="p-3 rounded-xl bg-white/[0.03] border border-white/10 flex flex-col justify-between">
<div>
<div class="font-bold text-white text-sm">2550Q File</div>
<div class="text-[10px] text-slate-400 mt-0.5">Quarterly VAT return</div>
</div>
<div class="mt-3 text-emerald-400 font-bold text-[11px]">Download XML ↓</div>
</div>
<div class="p-3 rounded-xl bg-white/[0.03] border border-white/10 flex flex-col justify-between">
<div>
<div class="font-bold text-white text-sm">SLSP Data Packet</div>
<div class="text-[10px] text-slate-400 mt-0.5">Summary List Sales/Purchases</div>
</div>
<div class="mt-3 text-cyan-400 font-bold text-[11px]">Download .DAT ↓</div>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<!-- Pricing / Zero Friction Model -->
<section class="scroll-mt-24 py-24 sm:py-32 relative" id="pricing">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10">
<div class="text-center max-w-3xl mx-auto">
<div class="inline-flex items-center gap-2 font-mono-tech text-xs uppercase tracking-[0.2em] text-emerald-400 font-bold">
<span class="material-symbols-outlined text-[15px]">currency_ruble</span>
            Radical Liberation
          </div>
<h2 class="font-heading font-black text-white text-4xl sm:text-5xl lg:text-6xl mt-4">
            100% Free Forever. Zero Exceptions.
          </h2>
<p class="mt-4 text-slate-400 text-base sm:text-lg">
            We believe accounting infrastructure for Filipino micro-businesses and freelancers must be frictionless. No expiration clocks, no paywalls, no surprise billing.
          </p>
</div>
<!-- Big Ethereal Glass Hero Plan -->
<div class="mt-14 max-w-4xl mx-auto rounded-[32px] glass-canvas-glow p-8 sm:p-12 relative overflow-hidden">
<div class="flex flex-col md:flex-row md:items-center justify-between gap-6 pb-8 border-b border-white/10">
<div>
<span class="inline-flex items-center gap-2 rounded-full bg-emerald-500/20 border border-emerald-400/40 px-3.5 py-1 text-xs font-mono-tech text-emerald-300 font-bold">
<span class="h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span>
                COMMUNITY CORE TIER
              </span>
<h3 class="font-heading text-3xl font-black text-white mt-3">Full Double-Entry Intelligence</h3>
<p class="text-slate-400 text-sm mt-1 max-w-md">
                Engineered for Philippine freelancers, solo practitioners, and growing businesses.
              </p>
</div>
<div class="flex flex-col md:items-end">
<div class="flex items-baseline gap-1">
<span class="font-heading text-4xl font-extrabold text-white">₱</span>
<span class="font-heading text-7xl font-black text-white text-glow-gradient">0</span>
<span class="text-slate-400 font-mono-tech text-sm">/ forever</span>
</div>
<span class="text-emerald-400 font-mono-tech text-xs mt-1">No credit card or commitment required</span>
</div>
</div>
<!-- Feature Matrix 8 Nodes -->
<div class="grid sm:grid-cols-2 gap-y-4 gap-x-8 pt-8 font-mono-tech text-xs sm:text-sm text-slate-300">
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class=""><strong>30 transactions a month</strong>, refreshed monthly</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class=""><strong>Full double-entry ledger</strong> on every entry</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class="">Financial statements &amp; BIR-ready loose leaf books</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class=""><strong>15 Excel/PDF exports</strong> &amp; 3 import files / mo</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class="">1 company workspace + 1 accountant collaborator</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class="">Cryptographically auditable append-only ledger</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class="">BIR eBIRForms XML &amp; Relief DAT outputs</span>
</div>
<div class="flex items-center gap-3">
<span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">✓</span>
<span class="">VAT &amp; Withholding Tax computed per line</span>
</div>
</div>
<div class="mt-10 pt-8 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-4">
<span class="text-xs text-slate-400 font-mono-tech">
              Zero trial period. Zero surprise paywalls.
            </span>
<a class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-400 via-teal-300 to-cyan-400 px-8 py-3.5 text-sm font-bold text-slate-950 shadow-[0_0_25px_rgba(16,185,129,0.4)] hover:brightness-110 transition-all" href="{{ route('register') }}">
              Launch Free Node Now →
            </a>
</div>
</div>
</div>
</section>
<!-- FAQ Accordion Matrix -->
<section class="scroll-mt-24 py-24 sm:py-32 relative" id="faq">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10">
<div class="grid gap-12 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16">
<div class="lg:sticky lg:top-28 lg:self-start">
<div class="inline-flex items-center gap-2 font-mono-tech text-xs uppercase tracking-[0.2em] text-emerald-400 font-bold">
<span class="material-symbols-outlined text-[15px]">help</span>
              Protocol Inquiry
            </div>
<h2 class="font-heading font-black tracking-tight text-white text-4xl sm:text-5xl mt-4">
              Answers Before You Entrust Your Books
            </h2>
<p class="mt-4 text-slate-400 text-base leading-relaxed">
              Everything Philippine entrepreneurs, freelancers, and licensed CPAs want to verify before switching to kontadurya.io.
            </p>
</div>
<div class="space-y-4">
<details class="group glass-canvas-card rounded-2xl p-6 transition-all" open="">
<summary class="flex cursor-pointer items-center justify-between font-bold text-white text-base group-hover:text-emerald-300 list-none">
<span class="">Do I need an accounting background to operate kontadurya.io?</span>
<span class="font-mono-tech text-emerald-400 transition-transform group-open:rotate-45 text-xl font-bold">+</span>
</summary>
<p class="mt-4 text-sm leading-relaxed text-slate-400 font-sans">
                None whatsoever. Kontadurya replaces complicated accounting terminology with intuitive entry sheets. The double-entry debits, credits, and tax allocations are computed autonomously under the hood.
              </p>
</details>
<details class="group glass-canvas-card rounded-2xl p-6 transition-all">
<summary class="flex cursor-pointer items-center justify-between font-bold text-white text-base group-hover:text-emerald-300 list-none">
<span class="">Does it support both VAT and Non-VAT enterprises?</span>
<span class="font-mono-tech text-emerald-400 transition-transform group-open:rotate-45 text-xl font-bold">+</span>
</summary>
<p class="mt-4 text-sm leading-relaxed text-slate-400 font-sans">
                Yes. During onboarding, specify your BIR registration status. Kontadurya presets your matrix to Percentage Tax (Non-VAT) or 12% Output/Input VAT. You can transition easily if your annual gross passes the VAT threshold.
              </p>
</details>
<details class="group glass-canvas-card rounded-2xl p-6 transition-all">
<summary class="flex cursor-pointer items-center justify-between font-bold text-white text-base group-hover:text-emerald-300 list-none">
<span class="">Are exports officially acceptable for BIR audit &amp; eFPS filing?</span>
<span class="font-mono-tech text-emerald-400 transition-transform group-open:rotate-45 text-xl font-bold">+</span>
</summary>
<p class="mt-4 text-sm leading-relaxed text-slate-400 font-sans">
                Yes. Tax returns generate as valid eBIRForms XML files, and validation schedules (SLSP, SAWT, QAP) export as verified .DAT files formatted for the official BIR Relief Validation tools.
              </p>
</details>
<details class="group glass-canvas-card rounded-2xl p-6 transition-all">
<summary class="flex cursor-pointer items-center justify-between font-bold text-white text-base group-hover:text-emerald-300 list-none">
<span class="">Does kontadurya.io replace my retained CPA?</span>
<span class="font-mono-tech text-emerald-400 transition-transform group-open:rotate-45 text-xl font-bold">+</span>
</summary>
<p class="mt-4 text-sm leading-relaxed text-slate-400 font-sans">
                No, it empowers them. Instead of encoding shoe-boxes of receipts, your accountant accesses pristine, balanced trial balances and pre-compiled tax packs. You can invite your accountant with free dedicated access.
              </p>
</details>
<details class="group glass-canvas-card rounded-2xl p-6 transition-all">
<summary class="flex cursor-pointer items-center justify-between font-bold text-white text-base group-hover:text-emerald-300 list-none">
<span class="">Is the Free plan genuinely free forever?</span>
<span class="font-mono-tech text-emerald-400 transition-transform group-open:rotate-45 text-xl font-bold">+</span>
</summary>
<p class="mt-4 text-sm leading-relaxed text-slate-400 font-sans">
                100% yes. Free forever for up to 30 transactions every month with full double-entry bookkeeping, BIR compliance XML &amp; DAT exports, and financial statements. There is no trial expiration and no credit card required.
              </p>
</details>
</div>
</div>
</div>
</section>
<!-- Bioluminescent Spatial CTA Pod -->
<section class="py-20 relative">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10">
<div class="relative overflow-hidden rounded-[36px] bg-gradient-to-r from-emerald-950/60 via-slate-900 to-cyan-950/60 border border-emerald-500/30 p-8 sm:p-16 lg:p-20 text-center shadow-[0_0_60px_rgba(16,185,129,0.15)]">
<div class="absolute -top-32 left-1/2 -translate-x-1/2 h-64 w-96 bg-emerald-500/20 blur-3xl pointer-events-none"></div>
<div class="relative z-10 max-w-2xl mx-auto">
<span class="inline-flex items-center gap-2 rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 font-mono-tech text-xs font-semibold uppercase tracking-[0.2em] text-emerald-300">
              Autonomous Financial Readiness
            </span>
<h2 class="font-heading text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight text-white mt-6">
              Start your books today. File with confidence later.
            </h2>
<p class="mt-6 text-slate-300 text-base sm:text-lg leading-relaxed">
              100% free double-entry bookkeeping for Philippine small businesses and freelancers. Zero trials, zero hidden subscriptions.
            </p>
<div class="mt-8 flex flex-col sm:flex-row justify-center gap-4">
<a class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-8 py-4 text-base font-bold text-slate-950 shadow-[0_0_30px_rgba(16,185,129,0.5)] hover:scale-105 transition-all" href="{{ route('register') }}">
<span class="">Deploy Free Ledger</span>
<span class="font-mono-tech text-sm">→</span>
</a>
<a class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 bg-white/[0.05] px-7 py-4 text-base font-semibold text-white hover:bg-white/[0.1] transition-all" href="#signup" data-info="contact">
                Book CPA Walkthrough
              </a>
</div>
</div>
</div>
</div>
</section>
<section id="signup" class="relative scroll-mt-28 py-20">
    <div class="mx-auto max-w-3xl px-6 sm:px-10">
        <div class="glass-canvas-glow rounded-3xl p-7 sm:p-12">
            <p class="font-mono-tech text-xs font-bold uppercase tracking-widest text-emerald-400">Your next chapter</p>
            <h2 class="font-heading mt-4 text-3xl font-bold text-white sm:text-4xl">Start with your business.</h2>
            <p id="form-note" class="mt-4 text-sm leading-relaxed text-slate-400">Try the starter form. It returns a demo confirmation; accounts, AI generation, and ledger processing are not connected yet.</p>
            <form action="{{ route('generate') }}" method="POST" class="mt-7" data-generate-form>
                @csrf
                <label for="business_type" class="block text-sm font-semibold text-slate-200">Business type</label>
                <input type="text" id="business_type" name="business_type" value="{{ old('business_type') }}" placeholder="e.g. Coffee shop or freelance design studio" required maxlength="255"
                    aria-describedby="form-note @error('business_type') business-type-error @enderror"
                    @error('business_type') aria-invalid="true" @enderror
                    class="mt-3 w-full rounded-xl border border-white/15 bg-slate-950/70 px-4 py-3.5 text-white placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400/20">
                @error('business_type')
                    <p id="business-type-error" role="alert" class="mt-3 text-sm text-rose-300">{{ $message }}</p>
                @enderror
                <button type="submit" class="mt-5 inline-flex items-center gap-3 rounded-xl bg-gradient-to-r from-emerald-400 to-cyan-400 px-7 py-3.5 text-sm font-bold text-slate-950 shadow-[0_0_25px_rgba(16,185,129,0.25)] transition hover:brightness-110">Generate <span aria-hidden="true">→</span></button>
                <p data-submit-status role="status" class="mt-3 text-sm text-emerald-300"></p>
            </form>
        </div>
    </div>
</section>
</main>
<!-- Neo-Spatial Dark Footer -->
<footer class="border-t border-white/[0.08] bg-[#02050E] text-slate-400 pt-16 pb-12 relative z-10">
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10 grid gap-10 lg:grid-cols-[1.5fr_repeat(4,1fr)]">
<div>
<a class="flex items-center gap-3" href="#top">
<div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20 border border-emerald-400/40 text-emerald-400 font-mono-tech font-bold text-sm">
            k/
          </div>
<span class="font-heading text-xl font-bold tracking-tight text-white">
            kontadurya<span class="text-emerald-400">.io</span>
</span>
</a>
<p class="mt-4 text-sm leading-relaxed text-slate-400">
          The autonomous double-entry bookkeeping and BIR-ready compliance matrix for Philippine enterprises. Built to put business owners back in complete control.
        </p>
<div class="mt-4 font-mono-tech text-xs text-emerald-400 flex items-center gap-2">
<span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
          All BIR Nodes Operational
        </div>
</div>
<div>
<p class="font-mono-tech text-xs font-bold uppercase tracking-wider text-white">Product</p>
<ul class="mt-4 space-y-2.5 text-sm">
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#features">Core Features</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#how">Autonomous Flow</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#pricing">Zero Tier Pricing</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#faq">Protocol FAQ</a></li>
</ul>
</div>
<div>
<p class="font-mono-tech text-xs font-bold uppercase tracking-wider text-white">BIR Resources</p>
<ul class="mt-4 space-y-2.5 text-sm">
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="guides">2550Q Guide 2026</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="guides">VAT vs Percentage</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="guides">Withholding Matrices</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="guides">Relief .DAT Spec</a></li>
</ul>
</div>
<div>
<p class="font-mono-tech text-xs font-bold uppercase tracking-wider text-white">Organization</p>
<ul class="mt-4 space-y-2.5 text-sm">
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="about">About Us</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="partners">CPA Network</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="contact">Security Audit</a></li>
</ul>
</div>
<div>
<p class="font-mono-tech text-xs font-bold uppercase tracking-wider text-white">Governance</p>
<ul class="mt-4 space-y-2.5 text-sm">
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="terms">Terms of Protocol</a></li>
<li class=""><a class="hover:text-emerald-300 transition-colors" href="#signup" data-info="privacy">Data Sovereignty</a></li>
</ul>
</div>
</div>
<div class="mx-auto w-full max-w-7xl px-6 sm:px-10 mt-12 border-t border-white/[0.08] pt-6 flex flex-wrap items-center justify-between font-mono-tech text-xs text-slate-500 gap-4">
<span class="">© 2026 Kontadurya Intelligence Technologies Inc.</span>
<span class="text-emerald-400/80">Engineered with Pride in Manila, Philippines 🇵🇭</span>
</div>
</footer>


<dialog id="resource-dialog" aria-labelledby="resource-title" aria-describedby="resource-description" class="resource-dialog glass-canvas-card rounded-3xl p-7 text-slate-200 sm:p-10">
    <form method="dialog" class="flex justify-end">
        <button type="submit" aria-label="Close dialog" class="rounded-lg border border-white/15 px-3 py-1 text-xl text-slate-400 hover:text-white">×</button>
    </form>
    <p class="font-mono-tech text-xs uppercase tracking-widest text-emerald-400">Kontadurya preview</p>
    <h2 id="resource-title" class="font-heading mt-3 text-3xl font-bold text-white"></h2>
    <p id="resource-description" class="mt-4 text-sm leading-relaxed text-slate-400"></p>
    <a href="#signup" data-close-dialog class="mt-7 inline-flex rounded-xl bg-emerald-400 px-5 py-3 text-sm font-bold text-slate-950">Try the starter demo →</a>
</dialog>
</body></html>