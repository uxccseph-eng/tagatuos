<button type="button" data-masters-import><span class="material-symbols-outlined" aria-hidden="true">upload_file</span>Import</button>
@php
    $mastersImportConfig = ['registry' => $importRegistry, 'columns' => \App\MasterImport::columns($importRegistry), 'previewUrl' => route('masters.import.preview', ['registry' => $importRegistry]), 'storeUrl' => route('masters.registry.import', ['registry' => $importRegistry])];
@endphp
<script type="application/json" id="masters-import-config">@json($mastersImportConfig)</script>
<link rel="stylesheet" href="{{ asset('css/sheet-import.css') }}?v={{ filemtime(public_path('css/sheet-import.css')) }}">
<script src="{{ asset('js/sheet-import.js') }}?v={{ filemtime(public_path('js/sheet-import.js')) }}" defer></script>
<script src="{{ asset('js/masters-import.js') }}?v={{ filemtime(public_path('js/masters-import.js')) }}" defer></script>
