<article class="fs-page fs-notes"><header><h2>{{ $statement->company_name }}</h2><h3>Notes to Financial Statements</h3><p>For the Year Ended December 31, {{ $year }}</p><p class="fs-unit">(Amounts in Philippine Peso)</p></header>
<h4>1. Reporting Entity</h4>
<p><strong>{{ $statement->company_name }}</strong> (the “Entity”) is {{ ($profile['entity_type'] ?? '') === 'individual' ? 'an individual business' : 'a corporation / non-individual entity' }} with Tax Identification Number (TIN) <strong>{{ $statement->tin }}</strong>. Tax registration: {{ ($profile['vat_type'] ?? '') === 'vat' ? 'VAT registered' : (($profile['vat_type'] ?? '') === 'non_vat' ? 'Non-VAT registered' : 'Not specified') }}.</p>
<p>Principal business activity: {{ $details['business_activity'] ?: 'Not provided by management.' }} Principal place of business: {{ $package['address'] ?: 'Not provided in client settings.' }}</p>
@if(($profile['entity_type'] ?? '') !== 'individual')<p>SEC registration number: {{ $profile['sec_registration_number'] ?? 'Not provided' }}. Date of incorporation: {{ $profile['registration_date'] ?? 'Not provided' }}.</p>@endif
<p>The authorization for issue is disclosed in Note 20.</p>
<h4>2. Basis of Preparation</h4>
<p><strong>Statement of Compliance.</strong> {{ $details['reporting_framework'] ?: 'The applicable reporting framework has not been specified by management.' }}</p>
<p><strong>Basis of Measurement.</strong> {{ $details['measurement_basis'] ?: 'Not provided by management.' }}</p>
<p><strong>Functional and Presentation Currency.</strong> The financial statements are presented in Philippine Peso (PHP). Figures are displayed to two decimal places, and parentheses indicate negative amounts.</p>
<p><strong>Use of Estimates and Judgments.</strong> {{ $details['estimates'] ?: 'Not provided by management.' }}</p>
<p><strong>Going Concern.</strong> {{ $details['going_concern_statement'] }}</p>
<h4>3. Summary of Significant Accounting Policies</h4>
@foreach(['cash_policy' => '3.1 Cash and Cash Equivalents', 'receivables_policy' => '3.2 Trade and Other Receivables', 'ppe_policy' => '3.3 Property, Plant and Equipment', 'payables_policy' => '3.4 Trade and Other Payables', 'revenue_policy' => '3.5 Revenue Recognition', 'expense_policy' => '3.6 Expense Recognition', 'income_tax_policy' => '3.7 Income Tax', 'events_policy' => '3.8 Events After the Reporting Period'] as $key => $label)<p><strong>{{ $label }}.</strong> <span class="fs-prose">{{ $details[$key] ?: 'Not provided by management.' }}</span></p>@endforeach
</article>
<article class="fs-page fs-notes fs-schedules"><header><h2>{{ $statement->company_name }}</h2><h3>Notes to Financial Statements</h3><p>For the Year Ended December 31, {{ $year }}</p></header>
@foreach(['cash' => '4. Cash and Cash Equivalents', 'current_assets' => '7. Prepaid Expenses and Other Current Assets', 'noncurrent_assets' => '8. Non-Current Assets', 'current_liabilities' => '9. Trade and Other Payables', 'noncurrent_liabilities' => '10. Non-Current Liabilities'] as $key => $label)
@if(in_array($key, ['cash', 'current_assets', 'current_liabilities']) || isset($statement->balance_sheet['schedules'][$key]))<h4>{{ $label }}</h4><p>This account consists of the following:</p>@include('financial-statement-note-table', ['scheduleRows' => $statement->balance_sheet['schedules'][$key] ?? []])@endif
@endforeach
<h4>12. Shareholders’ Equity / Owner’s Equity</h4><p>Movements in equity are presented in the Statement of Changes in Equity. The composition of equity at year-end is:</p>
@php
    $eq = $statement->equity;
    $equityRows = [
        ['label' => 'Share / owner capital, ending', 'current' => $eq['current'][4]['capital'], 'previous' => $eq['previous'][4]['capital']],
        ['label' => 'Retained earnings, beginning', 'current' => $eq['current'][0]['retained'], 'previous' => $eq['previous'][0]['retained']],
        ['label' => 'Net income for the year', 'current' => $eq['current'][2]['retained'], 'previous' => $eq['previous'][2]['retained']],
        ['label' => 'Other retained earnings movements / (distributions)', 'current' => $eq['current'][3]['retained'], 'previous' => $eq['previous'][3]['retained']],
    ];
@endphp
@include('financial-statement-note-table', ['scheduleRows' => $equityRows, 'scheduleTotal' => 'Equity, ending'])
@if(($profile['entity_type'] ?? '') !== 'individual')<p>Authorized shares: {{ isset($profile['authorized_shares']) ? number_format($profile['authorized_shares']) : 'Not provided' }}. Par value per share: {{ isset($profile['par_value_per_share']) && $profile['par_value_per_share'] !== '' ? $format($profile['par_value_per_share']) : 'No par value / not provided' }}. Issued and outstanding shares: {{ isset($profile['issued_outstanding_shares']) ? number_format($profile['issued_outstanding_shares']) : 'Not provided' }}.</p><p>Share classes, rights and restrictions: {{ $details['share_rights'] ?: 'Not provided by management.' }}</p>@endif
<h4>13. Revenue</h4><p>This account consists of the following:</p>@include('financial-statement-note-table', ['scheduleRows' => $statement->balance_sheet['schedules']['revenue'] ?? []])
</article>
<article class="fs-page fs-notes fs-schedules"><header><h2>{{ $statement->company_name }}</h2><h3>Notes to Financial Statements</h3><p>For the Year Ended December 31, {{ $year }}</p></header>
@foreach(['cost' => '14. Cost of Sales', 'expenses' => '15. Operating Expenses'] as $key => $label)<h4>{{ $label }}</h4><p>This account consists of the following:</p>@include('financial-statement-note-table', ['scheduleRows' => $statement->balance_sheet['schedules'][$key] ?? []])@endforeach
<h4>17. Income Tax</h4><p class="fs-prose">{{ $details['income_tax_policy'] ?: 'Not provided by management.' }}</p>
@php $incomeRows = array_values(array_filter($statement->income_statement['rows'], fn ($item) => in_array($item['label'], ['Net Income Before Income Tax', 'Income Tax Expense']))); @endphp
<table><thead><tr><th>Particulars</th><th>{{ $year }}</th><th>{{ $year - 1 }}</th></tr></thead><tbody>@foreach($incomeRows as $item)<tr><td>{{ $item['label'] }}</td><td>{{ $format($item['label'] === 'Income Tax Expense' ? -$item['current'] : $item['current']) }}</td><td>{{ $format($item['label'] === 'Income Tax Expense' ? -$item['previous'] : $item['previous']) }}</td></tr>@endforeach
@if(count($incomeRows) === 1)<tr><td>Income Tax Expense recorded</td><td>-</td><td>-</td></tr>@endif</tbody></table>
<p>Tax expense and tax payable are separate amounts. This package reports book figures; the taxable-income reconciliation, deductions, applicable rates, current/deferred tax and return figures require a supporting tax computation.</p>
@php $taxPayables = array_values(array_filter($statement->balance_sheet['schedules']['current_liabilities'] ?? [], fn ($item) => preg_match('/income tax.*payable/i', $item['label']))); @endphp
@include('financial-statement-note-table', ['scheduleRows' => $taxPayables, 'scheduleTotal' => 'Income tax payable recorded, ending'])
</article>
