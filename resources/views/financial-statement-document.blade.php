@php
    $format = static fn ($amount): string => abs((float) $amount) < 0.005 ? '-' : ((float) $amount < 0 ? '('.number_format(abs((float) $amount), 2).')' : number_format((float) $amount, 2));
    $year = $statement->reporting_year;
    $package = $statement->package ?? [];
    $details = $package['inputs'] ?? [];
    $profile = $package['profile'] ?? [];
    $warnings = [...($statement->cash_flows['warnings'] ?? []), ...($package['warnings'] ?? [])];
@endphp
<div class="fs-checks"><strong>Calculated draft — accountant review required</strong><p>Balance sheet difference: {{ $format($statement->balance_sheet['difference']) }} · Prior year: {{ $format($statement->balance_sheet['previous_difference']) }}. Cash-flow reconciliation difference: {{ $format($statement->cash_flows['difference']) }} · Prior year: {{ $format($statement->cash_flows['previous_difference']) }}.</p>@foreach($warnings as $warning)<p>{{ $warning }}</p>@endforeach<p>Ledger revision {{ $statement->ledger_revision }} · Generated {{ $statement->updated_at->timezone('Asia/Manila')->format('Y-m-d H:i') }} PHT. Zero differences are arithmetic checks, not an audit opinion.</p></div>
@if($package)@include('financial-statement-frontmatter')@else<p class="fs-checks">Generate again to add the complete notes and disclosures package.</p>@endif
@foreach(['balance_sheet' => 'Statement of Financial Position', 'income_statement' => 'Statement of Income', 'equity' => 'Statement of Changes in Equity', 'cash_flows' => 'Statement of Cash Flows'] as $matrix => $title)
<article class="fs-page"><header><h2>{{ $statement->company_name }}</h2><h3>{{ $title }}</h3><p>{{ $matrix === 'balance_sheet' ? 'As of' : 'For the Year Ended' }} December 31, {{ $year }}</p><p class="fs-unit">(Amounts in Philippine Peso)</p>@if($matrix === 'cash_flows')<p class="fs-unit">Indirect Method</p>@endif</header>
@if($matrix === 'equity')
@foreach(['current' => $year, 'previous' => $year - 1] as $period => $periodYear)<h4>{{ $periodYear }}</h4><table><thead><tr><th>Particulars</th><th>Share Capital</th><th>Retained Earnings</th><th>Total</th></tr></thead><tbody>@foreach($statement->equity[$period] as $row)<tr class="{{ $loop->last ? 'fs-grand' : '' }}"><td>{{ $row['label'] }}</td><td>{{ $format($row['capital']) }}</td><td>{{ $format($row['retained']) }}</td><td>{{ $format($row['total']) }}</td></tr>@endforeach</tbody></table>@endforeach
@else
<table><thead><tr><th>Particulars</th>@if($matrix !== 'cash_flows')<th>Note</th>@endif<th>{{ $year }}</th><th>{{ $year - 1 }}</th></tr></thead><tbody>@foreach($statement->{$matrix}['rows'] as $row)<tr class="fs-{{ $row['style'] }}"><td>{{ $row['label'] }}</td>@if($matrix !== 'cash_flows')<td>{{ $row['note'] }}</td>@endif<td>{{ $row['style'] === 'heading' ? '' : $format($row['current']) }}</td><td>{{ $row['style'] === 'heading' ? '' : $format($row['previous']) }}</td></tr>@endforeach</tbody></table>
@endif
<footer>TIN: {{ $statement->tin }} · Comparative figures for {{ $year - 1 }} · Calculated draft</footer></article>
@endforeach
@if($package)
@include('financial-statement-notes')
@include('financial-statement-tax-notes')
@endif
