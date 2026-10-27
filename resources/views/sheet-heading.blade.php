@php
    $sheetIcons = ['sales' => 'point_of_sale', 'collections' => 'account_balance_wallet', 'cwt' => 'verified_user', 'purchases' => 'shopping_cart', 'payments' => 'payments', 'journal' => 'menu_book', 'wtp' => 'assignment_turned_in', 'payroll' => 'badge'];
    $sheetTitles = ['sales' => 'Sales', 'collections' => 'Collections', 'cwt' => '2307-CWT', 'purchases' => 'Purchases', 'payments' => 'Payments', 'journal' => 'Journal', 'wtp' => '2307-WTP', 'payroll' => 'Payroll'];
@endphp
<div class="sheet-heading">
    <div class="sheet-heading-row">
        <div class="sheet-heading-context">
            <span class="sheet-heading-icon"><span class="material-symbols-outlined" aria-hidden="true">{{ $sheetIcons[$sheet] }}</span></span>
            <div>
                <div class="sheet-heading-title"><h1>{{ $sheetTitles[$sheet] }}</h1><span class="sheet-demo-badge">{{ $sheet === 'cwt' ? 'Received certificates' : ($sheet === 'wtp' ? 'Issued certificates' : 'Demo '.$sheet.' sheet') }}</span></div>
                <p class="sheet-company-line"><span>{{ $company['company_name'] }}</span><span aria-hidden="true">•</span><span class="sheet-company-tin">TIN: {{ \App\Tin::format($company['tin'] ?? '', $company['branch_code'] ?? '00000') ?: 'not set' }}</span><span aria-hidden="true">•</span><span>RDO {{ $company['rdo'] ?? 'not set' }}</span></p>
            </div>
        </div>
        <div class="sheet-period" role="group" aria-label="Working period">
            <div class="sheet-period-label"><span>Working Period</span><strong @if($sheet === 'sales') data-period-title @elseif($sheet === 'collections') data-collection-period @else id="{{ $sheet }}-period" @endif>{{ now('Asia/Manila')->format('F Y') }}</strong></div>
            <div class="sheet-period-controls">
                <button type="button" aria-label="Previous month" title="Previous month" @if($sheet === 'sales') data-period-step="-1" @elseif($sheet === 'collections') data-collection-action="previous" @else id="{{ $sheet }}-prev" @endif><span class="material-symbols-outlined" aria-hidden="true">chevron_left</span></button>
                <span class="sheet-period-range" @if($sheet === 'sales') data-period-range @elseif($sheet === 'collections') data-collection-range @else id="{{ $sheet }}-period-range" @endif></span>
                <button type="button" aria-label="Next month" title="Next month" @if($sheet === 'sales') data-period-step="1" @elseif($sheet === 'collections') data-collection-action="next" @else id="{{ $sheet }}-next" @endif><span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></button>
                @include('working-period-range')
            </div>
        </div>
    </div>
    <div class="sheet-utility-actions">
        @if(auth()->check() && isset($company['company_id']))<a class="sheet-document-inbox" href="{{ route('documents.index', ['companyId' => $company['company_id']]) }}"><span class="material-symbols-outlined" aria-hidden="true">folder_open</span><span>Documents</span></a>@endif
        @foreach(['ocr' => ['auto_awesome', 'Scan OCR'], 'import' => ['upload_file', 'Import'], 'shortcuts' => ['keyboard', 'Shortcuts (?)']] as $action => [$icon, $label])
            @continue($action === 'ocr')
            <button type="button" @if($action === 'import') data-sheet-import @endif @if($sheet === 'sales') data-sales-action="{{ $action }}" @elseif($sheet === 'collections') data-collection-action="{{ $action }}" @else data-{{ $sheet }}-info="{{ ['ocr' => 'OCR', 'import' => 'Import', 'shortcuts' => 'Shortcuts'][$action] }}" @endif><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span><span>{{ $label }}</span>@if($action === 'ocr')<span class="sheet-ai-badge">AI</span>@endif</button>
        @endforeach
    </div>
</div>
