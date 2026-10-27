(function(root){
    const editable = ['Date','Customer','Supplier','Gross Amount','Revenue Account','Account','Tax Type','Income Withholding ATC','VAT Withholding ATC','Invoice Reference','Terms','Supplier TIN','Classification','Deposit Account','Bank','Description','Particulars','Notes'];
    function applyBulk(rows, field, value, replace=false){
        for(const row of rows){if(row.selected&&!row.skip&&(replace||!row.values[field])){row.values[field]=value;row.duplicateReviewed=false;}}
    }
    function fillDown(rows,field,replace=false){
        const selected=rows.filter(row=>row.selected&&!row.skip).sort((a,b)=>a._row-b._row);
        if(selected.length<2)throw Error('Select at least two included rows to fill down.');
        const value=String(selected[0].values[field]??'').trim();
        if(!value)throw Error('Enter '+field+' in the first selected source row before filling down.');
        applyBulk(selected.slice(1),field,value,replace);
    }
    function canonicalBatch(sheet,records){
        return JSON.stringify({sheet,records:records.map(record=>Object.fromEntries(Object.entries(record).filter(([key])=>!key.startsWith('_')).sort(([a],[b])=>a.localeCompare(b)))).sort((a,b)=>JSON.stringify(a).localeCompare(JSON.stringify(b)))});
    }
    function mount({toolbar,engine,ledger,sheet}){
        const sales=sheet==='sales',partyField=sales?'Customer':'Supplier',accountField=sales?'Revenue Account':'Account';
        const config=JSON.parse(document.getElementById('sheet-import-service-config')?.textContent||'{}');
        const dialog=document.createElement('dialog');dialog.className='sheet-import-dialog guided-import';
        dialog.setAttribute('aria-labelledby','guided-import-title');
        dialog.innerHTML=`<header><div><span class="sheet-import-eyebrow">GUIDED BATCH IMPORT</span><h2 id="guided-import-title">Import ${sales?'Sales':'Purchases'}</h2></div><button type="button" data-close aria-label="Close import">✕</button></header>
        <div class="sheet-import-body"><p>Paste from Excel or upload a spreadsheet, match its columns, then correct and review the batch. Entries are added only when you click Post batch. Excel: values only, 2 MB. CSV or pasted cells: 5 MB. Up to 500 entries.</p>
        <div class="sheet-import-controls"><button type="button" data-template>Download template</button><button type="button" data-paste-toggle aria-expanded="false">Paste from Excel</button><label>Excel or CSV<input type="file" accept=".xlsx,.csv" data-file></label><label data-workbook hidden>Worksheet<select data-worksheet></select></label><label>Header row<input type="number" min="1" max="20" value="1" data-header></label><button type="button" data-map disabled>Read columns</button></div>
        <section data-paste-panel hidden class="guided-paste"><label for="guided-paste-cells">Copy cells in Excel or Google Sheets, then paste here (Ctrl+V)</label><textarea id="guided-paste-cells" data-paste-cells data-masters-ignore="true" rows="5" spellcheck="false" placeholder="Date&#9;Customer&#9;Amount"></textarea><div class="sheet-import-controls"><label class="guided-check"><input type="checkbox" data-paste-headers checked>First row contains column headings</label><button type="button" data-use-paste>Use pasted rows</button></div><p>Pasting reads cell values only. Nothing is posted until you review and confirm the batch.</p></section>
        <details data-mapping hidden open><summary>1 · Match columns</summary><p>Dates: YYYY-MM-DD or MM/DD/YYYY. Amounts include VAT. Blank account, VAT and ATC fields may use the party’s latest posted entry; review each suggestion. SI / OR reference is optional.</p><div class="guided-mapping"></div><div class="sheet-import-controls"><button type="button" data-save-map>Save column mapping</button><button type="button" data-review>Review rows</button></div></details>
        <section data-review-panel hidden><h3>2 · Correct and review</h3><div class="guided-bulk"><label>Apply to selected rows<select data-bulk-field aria-label="Apply to selected rows"></select></label><label>Value<input data-bulk-value aria-label="Bulk value" data-masters-ignore="true" list="guided-bulk-choices"></label><datalist id="guided-bulk-choices"></datalist><label class="guided-check"><input type="checkbox" data-replace>Replace existing values</label><button type="button" data-apply>Apply</button><button type="button" data-fill-down>Fill down from first selected row</button></div>
        <div class="sheet-import-controls"><button type="button" data-select>Select all included rows</button><button type="button" data-clear>Clear selection</button><label class="guided-check"><input type="checkbox" data-exceptions checked>Show issues first</label><button type="button" data-recheck>Check again</button></div><p>Unknown parties must be matched to Masters or added using “Add to Masters”. Possible duplicates require acknowledgement or skipping. Existing drafts are kept.</p><div data-choices></div><div class="sheet-import-table-wrap"><table><thead></thead><tbody></tbody></table></div><div class="sheet-import-controls"><button type="button" data-prev>Previous</button><span data-page></span><button type="button" data-next>Next</button></div></section>
        <div data-import-feedback role="status" aria-live="polite">Choose a file or paste cells to begin.</div></div><footer><span data-count>Nothing posted</span><div><button type="button" data-close>Close</button><button type="button" data-import-post disabled>Post batch</button></div></footer>`;
        document.body.append(dialog);
        const q=s=>dialog.querySelector(s),el=(tag,text)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;return node;};
        const fillGuide=el('p','Fill down copies the chosen field from the lowest selected source-row number. It fills blanks unless Replace existing values is checked. Skipped rows are excluded.');q('.guided-bulk').after(fillGuide);
        const context=()=>({ledger,masters:root.SheetMasters,accounts:root.WorkspaceAccounts||[]});
        async function confirmedLedger(){
            if(!root.CompanyLedger||Number(root.CompanyLedgerDatabase?.company_id)!==Number(config.companyId))throw Error('Open an assigned company book with database saving enabled.');
            await root.CompanyLedger.flush();
            if(root.CompanyLedger.pending())throw Error('Finish the pending company database transfer or recovery before importing.');
        }
        let matrix=[],headers=[],mapping={},rows=[],version=0,busy=false,locked=false,loadToken=0,page=0,sourceOffset=0;
        const message=(text,error=false)=>{q('[data-import-feedback]').textContent=text;q('[data-import-feedback]').dataset.state=error?'error':'';};
        async function request(url,body,method='POST'){
            const multipart=body instanceof FormData;
            const response=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':config.csrf||'','X-Company-ID':String(config.companyId||''),...(multipart?{}:{'Content-Type':'application/json'})},body:multipart?body:JSON.stringify(body)});
            const data=await response.json().catch(()=>({}));
            if(!response.ok)throw Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'The request failed. Reload the company book and try again.');return data;
        }
        function choices(field){
            const masters=root.SheetMasters;
            if(field===partyField)return masters?.parties(partyField).map(p=>p.name)||[];
            if(field===accountField)return (root.WorkspaceAccounts||[]).filter(a=>!a.archived&&(sales?a.type==='Income':['Asset','Expense'].includes(a.type))).map(a=>a.code+' · '+a.name);
            if(field==='Tax Type')return masters?.taxChoices(sales).map(t=>t.value)||[];
            if(field.includes('Withholding ATC'))return ['None',...(masters?.atcCodes(field.startsWith('VAT')?'VAT Withholding':'Income-tax CWT').map(a=>a.code)||[])];
            if(field==='Terms')return ['Cash','On Account'];
            if(field==='Classification')return ['Regular','Top Withholding Agent','Government','ZeroRated','PEZA'];
            if(['Bank','Deposit Account'].includes(field))return (root.WorkspaceAccounts||[]).filter(a=>!a.archived&&a.is_cash_bank).map(a=>a.code+' · '+a.name);
            return [];
        }
        function datalist(id,values){const list=el('datalist');list.id=id;values.forEach(value=>{const option=el('option');option.value=value;list.append(option);});return list;}
        function currentPreview(){return engine.previewMapped(sheet,rows,context());}
        function unreviewed(result){return result.warnings.filter(w=>!rows.find(r=>r._row===w.row)?.duplicateReviewed);}
        function render(){
            if(!rows.length)return;
            let result;try{result=currentPreview();}catch(error){message(error.message,true);q('[data-import-post]').disabled=true;return;}
            const warnings=unreviewed(result),issues=[...result.errors,...result.warnings];
            q('[data-count]').textContent=`${result.included} included · ${result.errors.length} errors · ${warnings.length} reviews · ₱${(result.totalGross/100).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})} validated gross`;
            q('[data-import-post]').disabled=!config.companyId||busy||locked||result.errors.length>0||warnings.length>0||!result.records.length;
            const fields=editable.filter(f=>engine.columns[sheet].includes(f)),head=el('tr');head.append(el('th','Select / Skip'),el('th','Source row / Review'),...fields.map(f=>el('th',f)));q('thead').replaceChildren(head);
            const lists=q('[data-choices]');lists.replaceChildren();fields.forEach((field,index)=>lists.append(datalist('guided-field-'+index,choices(field))));
            const body=q('tbody');body.replaceChildren();
            const ordered=[...rows];if(q('[data-exceptions]').checked)ordered.sort((a,b)=>Number(issues.some(i=>i.row===b._row))-Number(issues.some(i=>i.row===a._row))||a._row-b._row);
            page=Math.max(0,Math.min(page,Math.ceil(ordered.length/50)-1));q('[data-page]').textContent=`Rows ${page*50+1}–${Math.min((page+1)*50,ordered.length)} of ${ordered.length}`;q('[data-prev]').disabled=page===0;q('[data-next]').disabled=(page+1)*50>=ordered.length;
            for(const row of ordered.slice(page*50,(page+1)*50)){
                const tr=el('tr'),controls=el('td'),review=el('td'),record=result.records.find(r=>r._row===row._row),errors=result.errors.filter(i=>i.row===row._row),warnings=result.warnings.filter(i=>i.row===row._row);
                tr.dataset.invalid=String(!row.skip&&errors.length>0);if(row.skip)tr.classList.add('guided-skipped');
                for(const [label,key] of [['Select','selected'],['Skip','skip']]){const wrap=el('label',label+' '),input=el('input');input.type='checkbox';input.checked=!!row[key];input.addEventListener('change',()=>{row[key]=input.checked;if(key==='skip')render();});wrap.prepend(input);controls.append(wrap);}
                review.append(el('strong','Row '+row._row));
                [...errors,...warnings].forEach(i=>review.append(el('p',i.message)));
                if(warnings.length&&!row.skip){const label=el('label','I reviewed this possible duplicate'),input=el('input');input.type='checkbox';input.checked=row.duplicateReviewed;input.addEventListener('change',()=>{row.duplicateReviewed=input.checked;render();});label.prepend(input);review.append(label);}
                if(record?._suggestion)review.append(el('p','Suggested from '+record._suggestion.source+': '+record._suggestion.fields.join(', ')));
                if(!row.skip&&!errors.length&&!warnings.length)review.append(el('p','Ready'));
                tr.append(controls,review);
                fields.forEach((field,index)=>{
                    const td=el('td'),input=el('input');input.value=row.values[field]||'';input.dataset.mastersIgnore='true';input.setAttribute('aria-label',field+', source row '+row._row);input.disabled=row.skip;
                    if(field==='Date')input.placeholder='YYYY-MM-DD';
                    const suggested=record&&({'Revenue Account':record.account,Account:record.account,'Tax Type':record.tax,'Income Withholding ATC':sales?record.cwt:record.ewtCode,'VAT Withholding ATC':sales?record.vatCwt:record.wvatCode})[field];
                    if(suggested&&!input.value)input.placeholder=suggested;
                    const options=choices(field);if(options.length)input.setAttribute('list','guided-field-'+index);
                    let dirty=false;
                    input.addEventListener('input',()=>{row.values[field]=input.value.trim();row.duplicateReviewed=false;dirty=true;q('[data-import-post]').disabled=true;});
                    const commit=()=>{if(!dirty&&row.values[field]===input.value.trim())return;row.values[field]=input.value.trim();row.duplicateReviewed=false;dirty=false;render();};
                    input.addEventListener('change',commit);input.addEventListener('blur',commit);td.prepend(input);
                    const normalize=value=>String(value||'').toLowerCase().replace(/[^a-z0-9]/g,'');
                    if(field===partyField&&!row.skip&&!root.SheetMasters.parties(partyField).some(p=>[p.code,p.name].some(value=>normalize(value)===normalize(input.value)))){
                        const add=el('button','Add to Masters');add.type='button';add.disabled=!root.SheetMasters?.openCreator;add.addEventListener('click',()=>root.SheetMasters?.openCreator(input,'parties',input.value,partyField));td.append(add);
                    }
                    tr.append(td);
                });body.append(tr);
            }
            const global=result.errors.filter(i=>!i.row).map(i=>i.message).join(' ');
            message(global||((result.errors.length||warnings.length)?'Correct the highlighted rows, acknowledge possible duplicates, or skip rows before posting.':'All included entries passed validation. Review the details, then post the batch.'),result.errors.length>0);
        }
        async function readColumns(){
            const token=++loadToken;rows=[];q('[data-review-panel]').hidden=true;q('[data-import-post]').disabled=true;
            try{
                const headerRow=Number(q('[data-header]').value);if(!Number.isInteger(headerRow)||headerRow<1||headerRow>20||!matrix[headerRow-1])throw Error('Select a header row between 1 and 20.');
                const width=Math.max(...matrix.map(r=>r.length));if(width>100)throw Error('Use at most 100 columns.');
                headers=Array.from({length:width},(_,i)=>String(matrix[headerRow-1][i]||'').trim());mapping=engine.guessMapping(sheet,headers);version=0;
                if(config.companyId){const saved=await request(config.lookup,{sheet,headers});if(token!==loadToken)return;version=saved.version;if(saved.mapping)mapping=saved.mapping;}
                const grid=q('.guided-mapping');grid.replaceChildren();engine.columns[sheet].forEach(field=>{const label=el('label',field),select=el('select'),none=el('option','Not in file');none.value='';select.append(none);headers.forEach((header,index)=>{const option=el('option',String(index+1)+' · '+(header||'(blank header)'));option.value=index;select.append(option);});select.value=mapping[field]??'';select.addEventListener('change',()=>{mapping[field]=select.value===''?null:Number(select.value);rows=[];q('[data-review-panel]').hidden=true;q('[data-import-post]').disabled=true;});label.append(select);grid.append(label);});
                q('[data-mapping]').hidden=false;q('[data-mapping]').open=true;q('[data-save-map]').disabled=!config.companyId;message(version?'Saved company mapping loaded. Review its columns.':'Columns matched where possible. Check the mapping before reviewing.');
            }catch(error){if(token===loadToken)message(error.message,true);}
        }
        async function loadFile(worksheet=0){
            if(busy||locked)return;const file=q('[data-file]').files[0];if(!file)return;
            const token=++loadToken;rows=[];matrix=[];sourceOffset=0;q('[data-header]').disabled=false;q('[data-mapping]').hidden=true;q('[data-review-panel]').hidden=true;q('[data-import-post]').disabled=true;q('[data-map]').disabled=true;message('Reading file…');
            try{
                if(/\.csv$/i.test(file.name)){if(file.size>5*1024*1024)throw Error('CSV limit is 5 MB.');const text=await file.text();if(token!==loadToken)return;matrix=engine.parseCsv(text,true);q('[data-workbook]').hidden=true;}
                else if(/\.xlsx$/i.test(file.name)){
                    if(!config.companyId)throw Error('Open an assigned company book to import Excel files. CSV templates can be reviewed in this preview.');
                    if(file.size>2*1024*1024)throw Error('Excel limit is 2 MB.');const form=new FormData();form.append('file',file);form.append('worksheet',worksheet);
                    const data=await request(config.workbook,form);if(token!==loadToken)return;matrix=data.rows;
                    const select=q('[data-worksheet]');select.replaceChildren();data.worksheets.forEach((name,index)=>{const option=el('option',name);option.value=index;select.append(option);});select.value=data.selected;q('[data-workbook]').hidden=false;
                }else throw Error('Choose a .xlsx or .csv file.');
                if(token!==loadToken)return;if(matrix.length>521)throw Error('Use up to 500 data rows plus the header area.');q('[data-map]').disabled=false;await readColumns();
            }catch(error){if(token===loadToken)message(error.message,true);}
        }
        q('[data-file]').addEventListener('change',()=>loadFile());q('[data-worksheet]').addEventListener('change',()=>loadFile(Number(q('[data-worksheet]').value)));q('[data-map]').addEventListener('click',readColumns);
        q('[data-paste-toggle]').addEventListener('click',()=>{const panel=q('[data-paste-panel]');panel.hidden=!panel.hidden;q('[data-paste-toggle]').setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden)q('[data-paste-cells]').focus();});
        const invalidatePaste=()=>{++loadToken;rows=[];matrix=[];q('[data-review-panel]').hidden=true;q('[data-mapping]').hidden=true;q('[data-import-post]').disabled=true;q('[data-map]').disabled=true;q('[data-count]').textContent='Preview required before posting';message('Click Use pasted rows to read the edited cells.');};
        q('[data-paste-cells]').addEventListener('input',invalidatePaste);q('[data-paste-headers]').addEventListener('change',invalidatePaste);
        q('[data-use-paste]').addEventListener('click',async()=>{
            if(busy||locked)return;
            ++loadToken;rows=[];matrix=[];q('[data-review-panel]').hidden=true;q('[data-mapping]').hidden=true;q('[data-import-post]').disabled=true;q('[data-map]').disabled=true;
            try{
                const pasted=engine.parseClipboard(q('[data-paste-cells]').value),hasHeaders=q('[data-paste-headers]').checked;
                sourceOffset=hasHeaders?0:-1;matrix=hasHeaders?pasted:[Array.from({length:Math.max(...pasted.map(row=>row.length))},(_,index)=>'Column '+(index+1)),...pasted];
                q('[data-file]').value='';q('[data-workbook]').hidden=true;q('[data-header]').value=1;q('[data-header]').disabled=!hasHeaders;q('[data-map]').disabled=false;page=0;
                await readColumns();
            }catch(error){message(error.message,true);}
        });
        q('[data-header]').addEventListener('change',()=>{rows=[];q('[data-review-panel]').hidden=true;q('[data-mapping]').hidden=true;q('[data-import-post]').disabled=true;message('Click Read columns to apply this header row.');});
        q('[data-review]').addEventListener('click',async()=>{try{await root.CompanyLedger?.flush();rows=engine.mapRows(sheet,matrix,mapping,Number(q('[data-header]').value));rows.forEach(row=>row._row+=sourceOffset);q('[data-review-panel]').hidden=false;q('[data-mapping]').open=false;q('[data-paste-panel]').hidden=true;q('[data-paste-toggle]').setAttribute('aria-expanded','false');render();}catch(error){message(error.message,true);}});
        q('[data-save-map]').addEventListener('click',async()=>{const button=q('[data-save-map]'),token=loadToken;button.disabled=true;try{engine.mapRows(sheet,matrix,mapping,Number(q('[data-header]').value));const saved=await request(config.save,{sheet,headers,mapping,version},'PUT');if(token!==loadToken)return;version=saved.version;message('Column mapping saved for this company and sheet.');}catch(error){if(token===loadToken)message(error.message,true);}finally{button.disabled=false;}});
        const bulkFields=['Date',partyField,accountField,'Tax Type','Income Withholding ATC','VAT Withholding ATC','Terms',sales?'Deposit Account':'Bank','Description'];bulkFields.forEach(field=>{const option=el('option',field);option.value=field;q('[data-bulk-field]').append(option);});
        function bulkChoices(){q('#guided-bulk-choices').replaceWith(datalist('guided-bulk-choices',choices(q('[data-bulk-field]').value)));q('[data-bulk-value]').value='';}bulkChoices();q('[data-bulk-field]').addEventListener('change',bulkChoices);
        q('[data-apply]').addEventListener('click',()=>{applyBulk(rows,q('[data-bulk-field]').value,q('[data-bulk-value]').value.trim(),q('[data-replace]').checked);render();});
        q('[data-fill-down]').addEventListener('click',()=>{try{fillDown(rows,q('[data-bulk-field]').value,q('[data-replace]').checked);render();}catch(error){message(error.message,true);}});
        q('[data-select]').addEventListener('click',()=>{rows.forEach(r=>r.selected=!r.skip);render();});q('[data-clear]').addEventListener('click',()=>{rows.forEach(r=>r.selected=false);render();});
        q('[data-exceptions]').addEventListener('change',render);q('[data-recheck]').addEventListener('click',render);
        q('[data-prev]').addEventListener('click',()=>{page--;render();});q('[data-next]').addEventListener('click',()=>{page++;render();});
        q('[data-template]').addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([engine.template(sheet)],{type:'text/csv;charset=utf-8'})),link=el('a');link.href=url;link.download='tagatuos-'+sheet+'-import-template.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
        q('[data-import-post]').addEventListener('click',async()=>{
            if(busy||locked||q('[data-import-post]').disabled)return;busy=true;let committed=false;
            const controls=[...dialog.querySelectorAll('input,select,textarea,button')],disabled=controls.map(c=>c.disabled);controls.forEach(c=>c.disabled=true);
            try{
                if(!config.companyId)throw Error('Open an assigned company book to post entries.');
                await confirmedLedger();const fresh=currentPreview();if(fresh.errors.length||unreviewed(fresh).length)throw Error('The sheet changed. Review the batch again before posting.');
                const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(canonicalBatch(sheet,fresh.records))),hash=Array.from(new Uint8Array(bytes),v=>v.toString(16).padStart(2,'0')).join('');
                const posted=ledger.postImport(sheet,fresh.records,toolbar.dataset.sheetBulkUser||'Import',hash);committed=true;await confirmedLedger();
                rows=[];matrix=[];q('[data-file]').value='';q('[data-paste-cells]').value='';q('[data-review-panel]').hidden=true;q('[data-mapping]').hidden=true;q('[data-count]').textContent=posted.count+' entries posted';message(posted.count+' entries posted and saved to this company book. Your existing draft was kept.');root.SheetBulk?.refresh();
            }catch(error){if(committed){locked=true;message('The batch is awaiting database confirmation. Use the ledger save/recovery notice before importing again. '+error.message,true);}else{message(error.message,true);}}
            finally{busy=false;controls.forEach((c,i)=>c.disabled=disabled[i]);q('[data-import-post]').disabled=true;if(locked){dialog.querySelectorAll('input,select,textarea,button:not([data-close])').forEach(c=>c.disabled=true);}}
        });
        dialog.querySelectorAll('[data-close]').forEach(button=>button.addEventListener('click',()=>{if(!busy)dialog.close();}));dialog.addEventListener('cancel',event=>event.preventDefault());dialog.addEventListener('click',event=>{if(event.target===dialog)event.preventDefault();});
        document.addEventListener('click',event=>{if(event.target.closest('[data-sheet-import]')){event.preventDefault();event.stopImmediatePropagation();if(!dialog.open)dialog.showModal();}},true);
    }
    const api={mount,applyBulk,fillDown,canonicalBatch};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root)root.GuidedSheetImport=api;
})(typeof window==='undefined'?null:window);
