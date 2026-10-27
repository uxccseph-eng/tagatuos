@if(in_array($schedule, ['relief-sales', 'relief-purchases', 'sawt', 'qap-ewt']))
<link rel="stylesheet" href="{{ asset('css/tax-preflight.css') }}?v={{ filemtime(public_path('css/tax-preflight.css')) }}">
<section id="tax-preflight" class="tax-preflight" aria-label="Pre-filing checklist">
    <header><div><h2>Pre-filing checklist</h2><p data-preflight-status role="status">Not checked · BIR acceptance not verified</p></div><button type="button" data-preflight-run>Run checks</button></header>
    <p>Checks use saved company records and Books. Review the full schedule before filing. QAP checks cover Schedule 1 issued income-withholding certificates. Export history records generated files; it does not confirm a completed download or submission.</p>
    <div data-preflight-summary></div>
    <details data-preflight-details><summary>Findings and source corrections</summary><ul data-preflight-issues></ul></details>
    <label>Reviewer note<textarea data-preflight-note rows="2" maxlength="2000" placeholder="What was checked and which issues remain?"></textarea></label>
    <button type="button" data-preflight-save disabled>Record review for this snapshot</button>
    <details><summary>Review and DAT generation history</summary><ul data-preflight-history></ul></details>
    <p>Reference layouts: <a href="https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2025-2024%20Annex%20A.pdf" target="_blank" rel="noopener">QAP Annex A</a> · <a href="https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2015-2025%20Annex%20A.pdf" target="_blank" rel="noopener">SAWT Annex A</a> · <a href="https://www.bir.gov.ph/downloadables" target="_blank" rel="noopener">BIR validators</a></p>
</section>
@php
    $preflightConfig = ['preview' => $previewMode ?? false, 'companyId' => request()->attributes->get('active_company')?->id, 'check' => route('tax-preflight.show'), 'save' => route('tax-preflight.store'), 'csrf' => csrf_token(), 'targets' => ['sales' => route('sheets.sales'), 'purchases' => route('sheets.purchases'), 'cwt' => route('sheets.cwt'), 'wtp' => route('sheets.wtp'), 'parties' => route('masters.registry', ['registry' => 'parties']), 'accounts' => route('masters.accounts'), 'settings' => route('settings.section', ['section' => 'bir'])]];
@endphp
<script type="application/json" id="tax-preflight-config">@json($preflightConfig)</script>
<script src="{{ asset('js/tax-preflight.js') }}?v={{ filemtime(public_path('js/tax-preflight.js')) }}"></script>
@endif
