@php
    $usesActions = in_array($sheet, ['sales', 'collections']);
    $actionPrefix = $sheet === 'sales' ? 'sales' : 'collection';
    $addLabels = ['sales' => 'Add sale', 'collections' => 'Add collection', 'cwt' => 'Add certificate', 'purchases' => 'Add bill', 'payments' => 'Add payment', 'journal' => 'Add entry', 'wtp' => 'Add certificate'];
    $searchId = $sheet === 'sales' ? 'sales-filter' : ($sheet === 'collections' ? 'collection-search' : $sheet.'-search');
@endphp
<div class="sheet-toolbar" data-sheet-toolbar="{{ $sheet }}" @if(request()->attributes->get('active_company')) data-sheet-follow-up-url="{{ route('follow-ups.index', ['companyId' => request()->attributes->get('active_company')->id]) }}" @endif data-sheet-bulk-user="{{ $company['full_name'] ?? 'Demo user' }}" role="region" aria-label="{{ ucfirst($sheet) }} sheet controls">
    <div class="sheet-toolbar-row">
        <div class="sheet-toolbar-add">
            <button type="button" @if($usesActions) data-{{ $actionPrefix }}-action="add" @else id="{{ $sheet }}-add" @endif><span class="material-symbols-outlined" aria-hidden="true">add</span>{{ $addLabels[$sheet] }}</button>
            <button type="button" aria-label="{{ $addLabels[$sheet] }}" @if($usesActions) data-{{ $actionPrefix }}-action="add" @else data-{{ $sheet }}-add-row @endif><span class="material-symbols-outlined" aria-hidden="true">arrow_drop_down</span></button>
        </div>
        <span class="sheet-toolbar-divider" aria-hidden="true"></span>
        <button type="button" class="sheet-tool" @if($usesActions) data-{{ $actionPrefix }}-action="filter" @else data-sheet-filter-toggle aria-expanded="false" @endif><span class="material-symbols-outlined" aria-hidden="true">filter_list</span>Filter</button>
        @if(!$usesActions)
            <button id="{{ $sheet }}-filter" type="button" hidden aria-pressed="false"><span id="{{ $sheet }}-filter-label">All statuses</span></button>
        @endif
        <div class="sheet-toolbar-status" role="group" aria-label="Entry status">
            @foreach(['all' => 'All', 'draft' => 'Drafts', 'posted' => 'Posted'] as $status => $label)
                <button type="button" data-sheet-status="{{ $status }}" @if($sheet === 'collections') data-collection-action="{{ $status }}" @endif aria-pressed="{{ $status === 'all' ? 'true' : 'false' }}">{{ $label }} (<span data-sheet-status-count="{{ $status }}">0</span>)</button>
            @endforeach
        </div>
        @foreach(['sort' => ['sort', 'Sort entries'], 'group' => ['workspaces', 'Group rows'], 'columns' => ['view_column', 'Column visibility'], 'freeze' => ['ac_unit', 'Freeze columns'], 'wrap' => ['wrap_text', 'Wrap text']] as $action => [$icon, $label])
            <button type="button" class="sheet-tool sheet-tool-icon" title="{{ $label }}" aria-label="{{ $label }}" aria-pressed="false" @if($usesActions) data-{{ $actionPrefix }}-action="{{ $action }}" @else id="{{ $sheet }}-{{ $action }}" @endif><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span></button>
        @endforeach
        <span class="sheet-toolbar-divider" aria-hidden="true"></span>
        <button type="button" class="sheet-tool sheet-tool-about" @if($usesActions) data-{{ $actionPrefix }}-action="about" @else data-{{ $sheet }}-info="About" @endif><span class="material-symbols-outlined" aria-hidden="true">info</span>About</button>
    </div>
    <div class="sheet-toolbar-row">
        <label class="sheet-toolbar-search"><span class="material-symbols-outlined" aria-hidden="true">search</span><input id="{{ $searchId }}" type="search" aria-label="Search {{ $sheet }} rows" placeholder="Txn no., {{ in_array($sheet, ['sales', 'collections', 'cwt']) ? 'customer' : ($sheet === 'journal' ? 'reference' : 'supplier') }}, amount..."></label>
        <button type="button" class="sheet-tool sheet-tool-icon sheet-tool-summary" title="Totals and analytics" aria-label="Totals and analytics" @if($usesActions) data-{{ $actionPrefix }}-action="summary" @else id="{{ $sheet }}-stats" aria-pressed="true" @endif><span class="material-symbols-outlined" aria-hidden="true">analytics</span></button>
        <button type="button" class="sheet-tool" @if($usesActions) data-{{ $actionPrefix }}-action="export" @else id="{{ $sheet }}-export" @endif><span class="material-symbols-outlined" aria-hidden="true">file_download</span>Export<span class="material-symbols-outlined sheet-tool-chevron" aria-hidden="true">expand_more</span></button>
        <button type="button" class="sheet-tool sheet-tool-post" @if($usesActions) data-{{ $actionPrefix }}-action="post" @else id="{{ $sheet }}-post" @endif><span>Post <span @if($sheet === 'sales') data-post-count @elseif($sheet !== 'collections') id="{{ $sheet }}-post-count" @endif>(0)</span></span></button>
        <div class="sheet-toolbar-bulk">
            <button type="button" class="sheet-tool" data-sheet-bulk-toggle aria-expanded="false">Bulk<span class="material-symbols-outlined sheet-tool-chevron" aria-hidden="true">expand_more</span></button>
            <div class="sheet-toolbar-menu" data-sheet-bulk-menu hidden><button type="button" data-sheet-select-all>Select visible entries</button><button type="button" data-sheet-clear-selection>Clear selection</button><button type="button" data-sheet-post-selected>Post selected drafts</button><button type="button" data-sheet-bulk-action="void">Void selected entries</button><button type="button" data-sheet-bulk-action="delete-drafts">Delete selected drafts</button><button type="button" data-sheet-bulk-action="delete-voids">Delete selected void transactions</button></div>
        </div>
    </div>
    @if(!$usesActions)
        <div class="sheet-toolbar-filter" data-sheet-filter-panel hidden><label>Status <select data-sheet-status-select><option value="all">All</option><option value="draft">Drafts</option><option value="posted">Posted</option><option value="void">Voided</option></select></label></div>
    @endif
</div>
<script src="{{ asset('js/sheet-toolbar.js') }}?v={{ filemtime(public_path('js/sheet-toolbar.js')) }}" defer></script>
<link rel="stylesheet" href="{{ asset('css/sheet-import.css') }}?v={{ filemtime(public_path('css/sheet-import.css')) }}">
<script src="{{ asset('js/sheet-import.js') }}?v={{ filemtime(public_path('js/sheet-import.js')) }}" defer></script>
@if(in_array($sheet, ['sales', 'purchases']))
    @php
        $importServiceConfig = [
            'companyId' => request()->attributes->get('active_company')?->id,
            'csrf' => csrf_token(),
            'workbook' => route('sheets.import.workbook'),
            'lookup' => route('sheets.import.mapping'),
            'save' => route('sheets.import.mapping.save'),
        ];
    @endphp
    <script id="sheet-import-service-config" type="application/json">@json($importServiceConfig)</script>
    <script src="{{ asset('js/guided-sheet-import.js') }}?v={{ filemtime(public_path('js/guided-sheet-import.js')) }}" defer></script>
@endif
<script src="{{ asset('js/sheet-import-ui.js') }}?v={{ filemtime(public_path('js/sheet-import-ui.js')) }}" defer></script>
