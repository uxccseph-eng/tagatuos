<x-account-layout title="Tax Summary" :company-scope="true" :company="$company">
<link rel="stylesheet" href="{{ asset('css/tax-summary.css') }}?v={{ filemtime(public_path('css/tax-summary.css')) }}">
@php
    $money = fn ($value) => $value === null ? '—' : ($value < 0 ? '('.number_format(abs($value) / 100, 2).')' : number_format($value / 100, 2));
    $quarters = $summary['quarters'];
    $year = $summary['year'];
    $workspace = $summary['workspace'];
    $rows = [
        'sales' => ['Vatable sales (net)', 'sales'], 'zero' => ['Zero-rated sales', 'sales'], 'exempt' => ['Exempt / non-VAT sales', 'sales'],
        'total_sales' => ['Total sales / receipts', 'sales'], 'output' => ['Output VAT', 'sales'],
        'purchases' => ['Vatable purchases (net)', 'purchases'], 'nonvat' => ['Non-vatable purchases (net)', 'purchases'],
        'input' => ['Input VAT this quarter', 'purchases'], 'noncreditable' => ['Less: Non-creditable input VAT', 'purchases'],
        'base' => ['Net VAT before carryover & credits', null], 'carryover' => ['Input VAT carried over', null],
        'net' => ['Net VAT payable / (excess input)', null], 'additional' => ['Additional tax · saved return', null], 'credits' => ['Less: Credits applied', null],
        'balance' => ['Tax payable / (credit balance)', null], 'penalties' => ['Penalties · saved return', null],
        'payable' => ['Computed amount payable', null], 'excess_input' => ['Excess input VAT', null],
    ];
@endphp
<section class="vat-summary tax-summary-workspace" data-tax-summary="vat">
@include('tax-summary-heading', ['currentTax' => 'vat', 'prepareForm' => '2550Q', 'summaryDescription' => 'VAT from posted books, compared with your saved returns.', 'yearInputId' => 'vat-year', 'minimumYear' => 2023, 'maximumYear' => 2199])
<p class="vat-period">{{ $quarters[1]['period']['from'] }} – {{ $quarters[4]['period']['to'] }} · PHP · Ledger revision {{ $summary['revision'] }}</p>
@if(!$summary['applicable'])<div class="vat-warning" role="alert">This company is not set as VAT-registered. Confirm its registration in Settings before using 2550Q.</div>@endif
@if(!$summary['initialized'])<div class="vat-warning" role="alert">No saved ledger is available for this company. Figures below are empty, not confirmed nil returns.</div>@endif
@if(array_filter($quarters, fn ($q) => $q['issues'] !== []))<div class="vat-warning" role="alert">Some posted sources need VAT mapping review. The totals are incomplete; open the quarter’s source issues below.</div>@endif
<div class="vat-cards">
@foreach(['due' => ['Tax due', 'After reviewed credits; excludes tagged payments'], 'paid' => ['Payments recorded', 'Includes tagged penalties; see payment history'], 'open' => ['Open balance', 'Tax due less recorded payments'], 'input' => ['Excess input VAT', 'Q4 ending balance; not the sum of quarters']] as $key => [$label, $hint])<article><span>{{ $label }}</span><strong>₱{{ $money($workspace['totals'][$key]) }}</strong><small>{{ $hint }}</small></article>@endforeach
</div>
<section class="vat-panel"><header><h2>Computation</h2><p>Underlined figures preview the source transactions for that quarter. Parentheses indicate a negative amount.</p></header><div class="vat-scroll" tabindex="0" role="region" aria-label="Quarterly VAT computation"><table><thead><tr><th scope="col">Computation</th>@foreach($quarters as $q => $quarter)<th scope="col">Q{{ $q }}<small>{{ substr($quarter['period']['from'], 5) }} – {{ substr($quarter['period']['to'], 5) }}</small></th>@endforeach<th scope="col">Annual activity</th></tr></thead><tbody>
@foreach($rows as $key => [$label, $sheet])<tr @class(['vat-total' => in_array($key, ['total_sales', 'output', 'base', 'payable'])])><th scope="row">{{ $label }}</th>@foreach($quarters as $q => $quarter)<td>@php $liveKey = ['carryover' => 'carryover', 'net' => 'net', 'balance' => 'balance', 'credits' => 'credits', 'payable' => 'due', 'excess_input' => 'excess_input'][$key] ?? null; $displayAmount = $liveKey ? $workspace['quarters'][$q][$liveKey] : $quarter['amounts'][$key]; @endphp @if($sheet)<a href="{{ route('sheets.'.$sheet, ['company_id' => $company['company_id'], 'from' => $quarter['period']['from'], 'to' => $quarter['period']['to']]) }}" data-vat-source="{{ $key }}" data-quarter="{{ $q }}" data-label="{{ $label }}" aria-label="{{ $label }}, quarter {{ $q }}, preview sources">{{ $money($displayAmount) }}</a>@else{{ $money($displayAmount) }}@endif</td>@endforeach<td>{{ $money($key === 'payable' ? $workspace['totals']['due'] : $summary['annual'][$key]) }}</td></tr>@endforeach
</tbody></table></div><p class="vat-note">— means not separately available or not additive. Live carryovers use recorded opening credits and previous-quarter balances; a saved return’s reviewed carryover and credits take precedence. Penalties come from saved reviews. Credits may combine VAT withheld (2306), payments and other credits; they are not proof of payment. No Receipt purchases are excluded from this VAT mapping. Unsupported sources are listed below.</p></section>
<section class="vat-panel"><header><h2>Reconciliation, credits &amp; filing</h2><p>Saved returns remain unchanged when the books change. Filing records do not confirm payment.</p></header><div class="vat-reconciliation">
@foreach($quarters as $q => $quarter)<article><div class="vat-quarter-heading"><h3>Q{{ $q }}</h3><span class="vat-badge">{{ $quarter['filed'] ? 'Filing recorded' : ($quarter['record'] ? 'Prepared' : 'Not generated') }}</span></div><p>{{ $quarter['period']['label'] }}</p><strong>{{ $quarter['reconciliation'] }}</strong><dl><dt>Saved amount payable</dt><dd>{{ $money($quarter['saved_payable']) }}</dd><dt>Tagged payments</dt><dd>{{ $money($workspace['quarters'][$q]['paid']) }}</dd></dl>@if($quarter['filing_reference'])<p>Filing reference: {{ $quarter['filing_reference'] }}</p>@endif
@if($quarter['issues'])<details class="vat-warning"><summary>Review {{ count($quarter['issues']) }} source {{ count($quarter['issues']) === 1 ? 'issue' : 'issues' }}</summary><ul>@foreach($quarter['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul><p>Figures are incomplete until these sources are reviewed.</p></details>@endif
<details><summary>{{ count($quarter['sources']) }} posted source records</summary><ul class="vat-sources">@forelse($quarter['sources'] as $source)<li>{{ $source['date'] }} · {{ $source['number'] }} · {{ str_starts_with($source['id'], 'sales:') ? 'Sales' : 'Purchases' }}</li>@empty<li>No posted sources in this period.</li>@endforelse</ul></details>
<a class="button secondary compact" href="{{ route('tax-returns.index', ['company_id' => $company['company_id'], 'frequency' => 'quarterly', 'year' => $year, 'period' => $q]) }}">{{ $quarter['record'] ? 'Review 2550Q' : 'Prepare 2550Q' }} →</a></article>@endforeach
</div></section>
@include('tax-summary-workspace')
<details class="vat-panel vat-help"><summary>How this is computed</summary><p>Only posted sales and purchases within the company’s calendar or fiscal quarter are included. Drafts and void entries are excluded. Standard domestic 12% VAT is separated from VAT-inclusive gross amounts using the same mapping as the 2550Q exporter.</p><p>Net VAT before carryover = output VAT − current input VAT + non-creditable input VAT. The saved return’s reviewed carryover is then deducted, followed by additional tax and its combined credits; penalties are added to the remaining balance. Unmapped adjustments and imports require review in Tax Returns. A blank carryover is not assumed to be zero. Annual amounts show activity totals, not a separate annual VAT return or an outstanding payment balance.</p><p><a href="https://bir-cdn.bir.gov.ph/BIR/pdf/2550Q%20%20April%202024%20ENCS_Final.pdf" target="_blank" rel="noopener">BIR Form 2550Q · April 2024</a></p></details>
</section>
<script src="{{ asset('js/tax-summary.js') }}?v={{ filemtime(public_path('js/tax-summary.js')) }}" defer></script>
</x-account-layout>
