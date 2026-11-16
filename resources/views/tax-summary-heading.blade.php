<link rel="stylesheet" href="{{ asset('css/tax-summary-workspace.css') }}?v={{ filemtime(public_path('css/tax-summary-workspace.css')) }}">
<header class="tax-summary-heading">
    <div><h1>Tax Summary</h1><p class="tax-summary-company">{{ $company['company_name'] }}</p><p class="tax-summary-description">{{ $summaryDescription }}</p></div>
    <a class="button primary compact" href="{{ route('tax-returns.index', ['company_id' => $company['company_id'], 'year' => $year, 'frequency' => 'quarterly', 'period' => 1]) }}">Prepare {{ $prepareForm }}</a>
</header>
<div class="tax-summary-controls">
    <nav aria-label="Tax type">
        @foreach(['vat' => 'VAT (2550Q)', 'ewt' => 'EWT (1601-EQ)', 'income' => 'Income tax'] as $taxKey => $taxLabel)
            <a href="{{ route('tax-summary.index', ['company_id' => $company['company_id'], 'year' => $year, 'tax' => $taxKey]) }}" @if($currentTax === $taxKey) aria-current="page" @endif>{{ $taxLabel }}</a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('tax-summary.index') }}">
        <input type="hidden" name="company_id" value="{{ $company['company_id'] }}">
        <input type="hidden" name="tax" value="{{ $currentTax }}">
        <label for="{{ $yearInputId }}">Reporting year</label>
        <input id="{{ $yearInputId }}" name="year" type="number" min="{{ $minimumYear }}" max="{{ $maximumYear }}" value="{{ $year }}" required>
        <button class="button secondary compact" type="submit">Apply</button>
    </form>
</div>
