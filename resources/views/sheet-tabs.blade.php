@php
    $sheetTabs = [
        'sales' => ['Sales', 'point_of_sale'],
        'collections' => ['Collections', 'account_balance_wallet'],
        'cwt' => ['CWT (2307 Received)', 'verified_user'],
        'purchases' => ['Purchases', 'shopping_cart'],
        'payments' => ['Payments', 'payments'],
        'wtp' => ['WTP (2307 Issued)', 'assignment_turned_in'],
        'payroll' => ['Payroll', 'badge'],
        'journal' => ['Journal', 'menu_book'],
    ];
@endphp
<nav class="sheet-tabs" aria-label="Sheet navigation">
    @foreach($sheetTabs as $tab => [$label, $icon])
        @continue(in_array($tab, ['cwt', 'wtp', 'payroll']) && !($company['sheet_settings'][$tab] ?? true))
        <a href="{{ route('sheets.'.$tab) }}" @if($sheet === $tab) aria-current="page" @endif><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span><span>{{ $label }}</span>@if($sheet === $tab && $tab !== 'cwt')<span class="sheet-tab-count" @if($tab === 'sales') data-tab-count @elseif($tab === 'collections') data-collection-tab-count @else id="{{ $tab }}-tab-count" @endif>0</span>@endif</a>
    @endforeach
    @if($company['sheet_settings']['ppe'] ?? true)<a href="{{ route('lapsing') }}" @if($sheet === 'lapsing') aria-current="page" @endif><span class="material-symbols-outlined" aria-hidden="true">domain</span><span>Lapsing (PPE)</span></a>@endif

</nav>
