<!DOCTYPE html>
<html lang="en" class="dark">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Guided import preview · kontadurya.io</title>
<link rel="stylesheet" href="{{ asset('css/sheet-import.css') }}?v={{ filemtime(public_path('css/sheet-import.css')) }}">
<style>body{background:#0c1420;color:#e1e8f2;font:15px/1.6 Inter,Arial,sans-serif;margin:0;padding:6vw}main{max-width:850px;margin:auto}h1{font-size:36px;line-height:1.2}p{color:#a5b6c7}button,a{display:inline-block;background:#00e4a5;color:#0c1420;padding:12px 18px;border:0;border-radius:8px;text-decoration:none;cursor:pointer;font-weight:600}pre{padding:20px;background:#151c28;border-radius:10px;overflow:auto;font-size:12px}.preview-badge{color:#00e4a5;letter-spacing:2px;font-size:11px}</style></head>
<body><main><span class="preview-badge">SAMPLE WORKSPACE · POSTING DISABLED</span><h1>From spreadsheet to reviewed entries.</h1><p>This uses the Sales import screen with fictional Masters. Copy the sample cells, open Import Sales, choose Paste from Excel, and press Ctrl+V. You can also download and upload the sample CSV. No company records are created. Excel uploads and saved mappings are available inside an assigned company book.</p>
<div data-sheet-toolbar="sales"><button type="button" data-sheet-import>Import Sales</button><button type="button" id="sample-copy">Copy sample cells</button><button type="button" id="sample-download">Download sample CSV</button></div><p id="sample-copy-status" role="status"></p>
<p>The sample includes a ready entry, a missing tax type and an unknown customer. Try selecting rows and applying a tax type together. Available customer: Sample Customer; revenue account: 4020; tax: VAT.</p>
<pre id="sample-csv">Invoice date,Customer name,Total amount,Account code,VAT,Invoice no
2026-10-19,Sample Customer,1120.00,4020,VAT,SAMPLE-001
2026-10-20,Sample Customer,2240.00,4020,,SAMPLE-002
2026-10-21,Unknown Customer,560.00,4020,VAT,SAMPLE-003</pre>
<p><a href="{{ route('workspace.preview', ['page' => 'my-work']) }}">Back to My work preview</a></p></main>
<script id="workspace-ledger-config" data-key="guided-import-presentation" type="application/json">{"period":"2026-10"}</script>
<script id="sheet-import-service-config" type="application/json">{}</script>
<script>
window.WorkspaceAccounts=[{id:1,code:'1010',name:'Cash on Hand',type:'Asset',is_cash_bank:true},{id:2,code:'4020',name:'Sales - Services',type:'Income'}];
window.WorkspaceMasters={parties:[{id:1,code:'CUS001',name:'Sample Customer',classification:'Customer',tin:'000-000-000-00000',address:'Sample address'}],taxCodes:[{code:'VAT',name:'VAT',kind:'VAT',rate:12}],banks:[],atcCodes:[]};
window.CompanyLedger={state:()=>({sales:[],purchases:[],collections:[],payments:[],cwtCertificates:[],wtpCertificates:[],journalEntries:[],nextCollectionId:1}),flush:async()=>{},saveState:()=>{throw Error('This is a preview. Open your company book to post.');}};
document.getElementById('sample-download').addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([document.getElementById('sample-csv').textContent],{type:'text/csv'})),link=document.createElement('a');link.href=url;link.download='sample-sales-import.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
document.getElementById('sample-copy').addEventListener('click',async()=>{try{const cells=window.SheetImport.parseCsv(document.getElementById('sample-csv').textContent).map(row=>row.join('\t')).join('\r\n');await navigator.clipboard.writeText(cells);document.getElementById('sample-copy-status').textContent='Sample cells copied. Open Import Sales → Paste from Excel.';}catch{document.getElementById('sample-copy-status').textContent='Clipboard access is unavailable. Download the sample CSV instead.';}});
</script>
@foreach(['sheet-masters', 'workspace-ledger', 'sheet-import', 'guided-sheet-import', 'sheet-import-ui'] as $script)
<script src="{{ asset('js/'.$script.'.js') }}?v={{ filemtime(public_path('js/'.$script.'.js')) }}" defer></script>
@endforeach
</body></html>
