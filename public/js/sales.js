const grid = document.getElementById('sales-table');
const tbody = document.getElementById('sales-rows');
const draft = document.getElementById('sales-draft');
const dialog = document.getElementById('sales-dialog');
const statusMessage = document.getElementById('sales-status');
const filterInput = document.getElementById('sales-filter');
const globalSearch = document.getElementById('sales-global-search');
const statusFilter = document.getElementById('sales-status-filter');
const periodSeed = document.getElementById('sales-main').dataset.period;
let workingPeriod = window.WorkingPeriod.current().from.slice(0,7);
let nextNumber = 4;
let sortAscending = true;
let grouped = false;
let activeRow = null;
const entries = new Map();
const currency = (cents) => new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP'}).format(cents / 100);
const announce = (message) => { statusMessage.textContent = message; };
function amountToCents(text) {
    const normalized = String(text).replace(/[₱,\s]/g, '');
    if (!/^\d{1,10}(\.\d{1,2})?$/.test(normalized)) return null;
    const [whole, decimals = ''] = normalized.split('.');
    return Number(whole) * 100 + Number(decimals.padEnd(2, '0'));
}
function amounts(entry) {
    const net = window.KontaduryaLedger.taxAmounts(entry.gross,entry.tax).net;
    const rate = Number(String(entry.cwt || '').match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);
    return {net, vat:entry.gross-net, cwt:Math.round(net * rate / 100)};
}
const rowTemplate = tbody.querySelector('tr').cloneNode(true);
const editorFields = [...draft.querySelectorAll('input:not([type="checkbox"]), select')];
const fieldNames = ['Date', 'Customer', 'Gross amount', 'Tax type', 'Revenue account', 'Income withholding', 'VAT withholding', 'Terms', 'Invoice reference', 'Classification', 'Deposit account', 'Description'];
editorFields.forEach((field, index) => field.setAttribute('aria-label', `Draft ${fieldNames[index]}`));
editorFields[0].type = 'date';
editorFields[2].inputMode = 'decimal';
const customerInput = document.createElement('input');
customerInput.className = editorFields[1].className;
customerInput.setAttribute('aria-label','Draft Customer');
customerInput.setAttribute('list','sales-customers');
customerInput.placeholder = 'Customer name…';
customerInput.maxLength = 255;
editorFields[1].replaceWith(customerInput);
editorFields[1] = customerInput;
let inlineBaseline=JSON.stringify(editorFields.map(field=>field.value));
let inlineDraftStarted=false;
let popupBaseline='';
let autoSalesDraft=null;
const customerList = document.createElement('datalist');
customerList.id = 'sales-customers';
(window.SheetMasters?.hasCatalog?window.SheetMasters.parties('Customer').map(party=>party.name):['VARIOUS CUSTOMERS']).forEach((name) => {
    const option = document.createElement('option'); option.value = name; customerList.append(option);
});
document.body.append(customerList);
const selectAll = grid.querySelector('thead input[type="checkbox"]');
selectAll.setAttribute('aria-label','Select all visible sales rows');
function addBreakdown(cell, primary, secondary) {
    cell.replaceChildren();
    const main = document.createElement('div'); main.textContent = primary; cell.append(main);
    if (secondary) {
        const detail = document.createElement('div'); detail.className='font-label-sm text-[10px] text-on-surface-variant font-normal'; detail.textContent=secondary; cell.append(detail);
    }
}
function renderEntry(entry, existingRow) {
    const row = existingRow || rowTemplate.cloneNode(true);
    row.dataset.saleId = entry.id;
    const cells = row.cells;
    cells[cells.length-1].replaceChildren(rowTemplate.cells[rowTemplate.cells.length-1].firstElementChild.cloneNode(true));
    const checkbox = cells[0].querySelector('input');
    checkbox.checked = false;
    checkbox.setAttribute('aria-label',`Select ${entry.number}`);
    const values = [null, entry.status === 'posted' ? 'Posted (demo)' : entry.status === 'void' ? 'Voided' : 'Draft',entry.date,entry.number,entry.customer,null,null,entry.account,entry.cwt,entry.vatCwt,entry.terms,entry.reference,entry.classification,entry.bank,entry.memo];
    values.forEach((value,index) => { if (value !== null) cells[index].textContent = value; });
    cells[1].replaceChildren(Object.assign(document.createElement('span'),{className:entry.status==='draft'?'draft-status-badge':entry.status==='posted'?'posted-status-badge':'void-status-badge',textContent:entry.status==='draft'?'Drafting':entry.status==='posted'?'Posted':'Voided'}));
    const entryButton = document.createElement('button');
    entryButton.type = 'button';
    entryButton.dataset.openEntry = entry.id;
    entryButton.textContent = entry.number;
    entryButton.setAttribute('aria-label', `Open entry ${entry.number}`);
    cells[3].replaceChildren(entryButton);
    const totals = amounts(entry);
    addBreakdown(cells[5],currency(entry.gross),`Net: ${currency(totals.net)}`);
    addBreakdown(cells[6],window.SheetMasters.taxLabel(entry.tax),`VAT: ${currency(totals.vat)}`);
    if(entry.status==='draft'){
        const keys=['date','customer','gross','tax','account','cwt','vatCwt','terms','reference','classification','bank','memo'];
        keys.forEach((key,index)=>{
            const field=editorFields[index].cloneNode(true);field.removeAttribute('id');field.removeAttribute('list');field.removeAttribute('aria-controls');field.disabled=false;field.readOnly=false;field.value=key==='gross'?(entry.gross?(entry.gross/100).toFixed(2):''):entry[key] || '';
            field.setAttribute('aria-label',entry.number+' '+fieldNames[index]);
            if(field.tagName==='SELECT')window.SheetMasters.selectValue(field,entry[key]);
            const save=event=>{entry[key]=key==='gross'?amountToCents(field.value) || 0:field.value;if(key==='customer'&&event.type==='change'){const suggestion=window.SheetMasters.partyDefaults(entry,true);Object.assign(entry,suggestion.values);for(const [suggestedKey,value] of Object.entries(suggestion.values)){const target=row.querySelector('[aria-label="'+entry.number+' '+fieldNames[keys.indexOf(suggestedKey)]+'"]');if(target){window.SheetMasters.selectValue(target,value);target.dispatchEvent(new Event('masters:sync'));}}if(suggestion.sourceNumber)announce('Suggested account and tax settings from '+suggestion.sourceNumber+'. Review before posting.');}entry.draftTouched=true;entry.draftSaved=false;updateSheet();};
            field.addEventListener('input',save);field.addEventListener('change',save);cells[index===0?2:index+3].replaceChildren(field);
        });
    }
    row.classList.toggle('opacity-50', entry.status === 'void');
    row.querySelectorAll('[data-sales-action="duplicate"]').forEach(button=>button.setAttribute('aria-label',`Duplicate ${entry.number}`));
    row.querySelectorAll('[data-sales-action="more"]').forEach(button=>button.setAttribute('aria-label',`Options for ${entry.number}`));
    row.querySelectorAll('[data-sales-action="2307"]').forEach(button=>{
        const certificates=window.KontaduryaLedger.receivedCwtForSale(entry.id);button.setAttribute('aria-label',(certificates.length?'Recorded 2307 for ':'Record 2307 for ')+entry.number);button.title=certificates.length?'2307 recorded · '+certificates.map(record=>record.number).join(', '):'Record 2307 Form';button.classList.add('sales-cwt-action');button.querySelector('.sales-cwt-recorded')?.remove();if(certificates.length){const badge=document.createElement('span');badge.className='sales-cwt-recorded';badge.textContent='✓';badge.setAttribute('aria-hidden','true');button.append(badge);}
    });
    if (!existingRow) tbody.insertBefore(row,draft);
    entry.row = row;
    entries.set(entry.id,entry);
    if(entry.status==='draft')window.SheetPosting.draftActions(cells[cells.length-1],entry,{
        save:()=>{entry.draftSaved=true;updateSheet();announce(entry.number+' draft saved in this tab.');},
        post:()=>postSelection([entry]),delete:()=>deleteSalesDraft(entry),more:event=>handleAction('more',row,event.currentTarget)
    });
    return row;
}
[...tbody.querySelectorAll('tr')].filter(row=>row!==draft).forEach(row=>row.remove());
window.KontaduryaLedger.sales().forEach(entry=>renderEntry(entry));
nextNumber = Math.max(0,...entries.keys()) + 1;
function nextSaleNumber(){return autoSalesDraft?.number || 'SI-'+String(Math.max(0,...[...entries.values()].map(record=>Number(record.number.split('-').at(-1))))+1).padStart(6,'0');}
function persistSales() {
    const values=[...entries.values()].map(({row,attachments,...entry})=>entry);
    if(autoSalesDraft&&!values.some(entry=>entry.id===autoSalesDraft.id))values.push(autoSalesDraft);else if(autoSalesDraft)autoSalesDraft=null;
    try {window.KontaduryaLedger.saveSales(values);for(const saved of values){const entry=entries.get(saved.id);if(entry&&entry.number!==saved.number){entry.number=saved.number;renderEntry(entry,entry.row);}}if(autoSalesDraft){autoSalesDraft.number=nextSaleNumber();draft.cells[3].textContent=autoSalesDraft.number+'*';}}
    catch(error){announce(error.message);}
}
const emptyRow=document.createElement('tr');
const emptyCell=document.createElement('td'); emptyCell.colSpan=16; emptyCell.className='px-5 py-8 text-body-sm text-on-surface-variant'; emptyCell.textContent='No sales rows match this period and filter.'; emptyRow.append(emptyCell); tbody.insertBefore(emptyRow,draft);
function deleteSalesDraft(entry){window.DraftLeaveGuard.confirmDelete(()=>{entries.delete(entry.id);entry.row.remove();if(editingEntry?.id===entry.id)entryDrawer.close();updateSheet();announce('Draft deleted. Transaction numbers updated.');},entry.number);}
function selectedEntries() { return [...entries.values()].filter(entry=>!entry.row.hidden && entry.row.querySelector('input[type="checkbox"]').checked); }
function postingCandidates(){const visible=[...entries.values()].filter(entry=>!entry.row.hidden);if(autoSalesDraft&&!draft.hidden&&window.WorkingPeriod.matches(autoSalesDraft.date))visible.push(autoSalesDraft);return window.SheetPosting.candidates(visible,selectedEntries().map(entry=>entry.id));}
function refreshPostCount(){document.querySelector('[data-post-count]').textContent='('+postingCandidates().length+')';}
function updateSheet() {
    const query=filterInput.value.trim().toLowerCase();
    const ordered=[...entries.values()].sort((a,b)=>grouped?a.customer.localeCompare(b.customer):sortAscending?a.date.localeCompare(b.date):b.date.localeCompare(a.date));
    let net=0,vat=0,cwt=0,gross=0,count=0,visible=0;
    let nextRow=tbody.firstElementChild;
    ordered.forEach(entry=>{
        const matches=entry.status!=='archived' && window.WorkingPeriod.matches(entry.date) && (statusFilter.value==='all' || entry.status===statusFilter.value) && `${entry.customer} ${entry.number} ${currency(entry.gross)} ${entry.memo}`.toLowerCase().includes(query);
        entry.row.hidden=!matches;
        if (!matches) entry.row.querySelector('input').checked=false;
        if(entry.row!==nextRow)tbody.insertBefore(entry.row,nextRow);
        nextRow=entry.row.nextElementSibling;
        if (matches) visible++;
        if (matches && entry.status!=='void') { const total=amounts(entry); net+=total.net;vat+=total.vat;cwt+=total.cwt;gross+=entry.gross;count++; }
    });
    emptyRow.hidden=visible>0;
    document.querySelector('[data-total-count]').textContent=count;
    document.querySelector('[data-total-net]').textContent=currency(net);
    document.querySelector('[data-total-vat]').textContent=currency(vat);
    document.querySelector('[data-total-cwt]').textContent=currency(cwt);
    document.querySelector('[data-total-gross]').textContent=currency(gross);
    document.querySelector('[data-tab-count]')?.replaceChildren(document.createTextNode(String(count)));
    const selected=selectedEntries();
    refreshPostCount();
    selectAll.checked=visible>0 && selected.length===visible;
    selectAll.indeterminate=selected.length>0 && selected.length<visible;
    persistSales();
}
function updatePeriod() {
    window.WorkingPeriod.render();
    updateSheet();
}
function showInfo(title,description,row=null) {
    activeRow=row;
    document.getElementById('sales-dialog-title').textContent=title;
    document.getElementById('sales-dialog-description').textContent=description;
    document.querySelector('[data-row-actions]').hidden=!row;
    if (!dialog.open) dialog.showModal();
}
function openDraft(copy=null,confirmed=false) {
    const pending=window.DraftLeaveGuard.continueDraft(entries.values(),entry=>{window.WorkingPeriod.revealDate(entry.date);workingPeriod=window.WorkingPeriod.current().from.slice(0,7);statusFilter.value='all';filterInput.value='';globalSearch.value='';updatePeriod();entry.row.scrollIntoView({block:'nearest'});entry.row.querySelector('[aria-label="'+entry.number+' Customer"]')?.focus();announce('Continue '+entry.number+'. Only one draft is allowed on this page.');});if(pending)return pending;
    if(!draft.hidden){editorFields[1].focus();announce('Continue the current draft. Only one draft is allowed on this page.');return;}
    const date = copy?.date || window.WorkingPeriod.entryDate();
    const values = [date,copy?.customer || '',copy ? (copy.gross/100).toFixed(2) : '',copy?.tax || '',copy?.account || '',copy?.cwt || '',copy?.vatCwt || '',copy?.terms || 'Cash',copy?.reference || '',copy?.classification || 'Regular',copy?.bank || '1010 · Cash on Hand',copy?.memo || ''];
    editorFields.forEach((field,index)=>{if(field.tagName==='SELECT')window.SheetMasters.selectValue(field,values[index]);else field.value=values[index];field.setCustomValidity('');});
    inlineBaseline=JSON.stringify(editorFields.map(field=>field.value));
    inlineDraftStarted=true;
    draft.hidden = false;
    draft.cells[3].textContent = nextSaleNumber()+'*';
    draft.scrollIntoView({block:'nearest',inline:'nearest'});
    editorFields[1].focus({preventScroll:true});
}
function openInlineEntryPopup(field=null) {
    inlineDraftStarted=true;
    const values = editorFields.map(input=>input.value.trim());
    const inlineEntry = {date:values[0],customer:values[1],gross:amountToCents(values[2]) || 0,tax:values[3],account:values[4],cwt:values[5],vatCwt:values[6],terms:values[7],reference:values[8],classification:values[9],bank:values[10],memo:values[11]};
    openEntryDrawer(inlineEntry,true);
    entryFromInline = true;
    setEntryValue('Gross amount',values[2]);
    updateEntryPreview();
    popupBaseline=popupSnapshot();
    const index = editorFields.indexOf(field);
    if(index >= 0) entryFields[fieldNames[index]].focus();
}
function saveDraft() {
    const gross=amountToCents(editorFields[2].value);
    const date=editorFields[0].value;
    if(!date || !editorFields[0].checkValidity()) { editorFields[0].reportValidity(); return; }
    if(editorFields[2].value && gross===null) { editorFields[2].setCustomValidity('Enter a positive amount with at most two decimal places.');editorFields[2].reportValidity();return; }
    const values=editorFields.map(field=>field.value.trim());
    const entry={id:nextNumber,number:nextSaleNumber(),date,customer:values[1],gross:gross || 0,tax:values[3],account:values[4],cwt:values[5],vatCwt:values[6],terms:values[7],reference:values[8],classification:values[9],bank:values[10],memo:values[11],status:'draft'};
    renderEntry(entry);nextNumber++;draft.hidden=true;updateSheet();announce(`${entry.number} saved as a draft in this tab. No ledger entry was posted.`);
}
editorFields.forEach((field,index)=>{
    field.addEventListener('input',()=>{field.setCustomValidity('');autoSaveSalesForm(false);});
    field.addEventListener('change',()=>{if(index===1)suggestSalesFields(false);autoSaveSalesForm(false);});
    field.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();if(index<editorFields.length-1)editorFields[index+1].focus();else saveDraft();}});
});
draft.addEventListener('click',event=>{
    if(event.target.closest('button,input,select,textarea,label'))return;
    openInlineEntryPopup();
});
function postSelection(requested=null) {
    const candidates=requested || postingCandidates();
    if(!candidates.length){announce('Choose a draft row to post, or finish the current new entry.');return;}
    for(const entry of candidates){const error=window.SheetPosting.salesError(entry);if(error){announce(entry.number+': '+error);return;}}
    const ids=new Set(candidates.map(entry=>entry.id)),saved=window.KontaduryaLedger.sales(),postedAt=new Date().toISOString();
    const next=saved.map(entry=>ids.has(entry.id)?{...entry,status:'posted',postedAt}:entry);
    for(const entry of candidates)if(!next.some(item=>item.id===entry.id)){const {row,attachments,...record}=entry;next.push({...record,status:'posted',postedAt});}
    try{window.KontaduryaLedger.saveSales(next);}catch(error){announce(error.message);return;}
    if(autoSalesDraft&&ids.has(autoSalesDraft.id)){autoSalesDraft=null;draft.hidden=true;}
    window.SheetBulk.refresh();announce(candidates.length+' sales entry(s) posted.');
}
function voidEntries(selected) {
    if(!selected.length){announce('Select a row to void in the demo.');return;}
    selected.forEach(entry=>{
        if(entry.status==='void'){if((entry.previousStatus || 'draft')==='draft'&&(!draft.hidden||[...entries.values()].some(record=>record.id!==entry.id&&record.status==='draft'))){announce('Post or delete the existing draft before restoring another draft.');return;}entry.status=entry.previousStatus || 'draft';}
        else {entry.previousStatus=entry.status;entry.status='void';entry.voidAudit={reason:'Voided from the sales sheet.',user:companyDetails.full_name || 'Demo user',time:new Date().toISOString()};}
        renderEntry(entry,entry.row);
    });updateSheet();announce('Demo row status changed. Use row options to restore a voided row.');
}
function exportCsv() {
    const quote=value=>'"'+(/^[=+@\-\t\r]/.test(String(value))?"'":'')+String(value).replaceAll('"','""')+'"';
    const rows=[['Status','Date','Transaction','Customer','Gross','Tax type','Revenue account','Income withholding','VAT withholding','Terms','Reference','Classification','Deposit account','Description']];
    [...entries.values()].filter(entry=>!entry.row.hidden).forEach(entry=>rows.push([entry.status,entry.date,entry.number,entry.customer,(entry.gross/100).toFixed(2),window.SheetMasters.taxLabel(entry.tax),entry.account,entry.cwt,entry.vatCwt,entry.terms,entry.reference,entry.classification,entry.bank,entry.memo]));
    const url=URL.createObjectURL(new Blob(['\uFEFF'+rows.map(row=>row.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
    const anchor=document.createElement('a');anchor.href=url;anchor.download=`demo-sales-${workingPeriod}.csv`;anchor.click();setTimeout(()=>URL.revokeObjectURL(url),1000);announce('Exported the currently visible demo rows to CSV.');
}
function handleAction(action,row,trigger=null) {
    const entry=row?entries.get(Number(row.dataset.saleId)):null;
    if(action==='add')openDraft();
    else if(action==='duplicate' && entry){openDraft(entry);}
    else if(action==='more' && entry)window.SheetEntryActions.open(trigger || row.querySelector('[data-sales-action="more"]'),entry,{delete:()=>deleteSalesDraft(entry),edit:()=>openEntryDrawer(entry,false,true),duplicate:()=>openDraft(entry),print:()=>{openEntryDrawer(entry);document.getElementById('print-entry-btn').click();},void:()=>{openEntryDrawer(entry);document.getElementById('void-entry-btn').click();},view:()=>openEntryDrawer(entry)});
    else if(action==='2307'&&entry){if(entry.status!=='posted'){showInfo('Record Form 2307','Post the sale before recording a received certificate.');return;}const certificates=window.KontaduryaLedger.receivedCwtForSale(entry.id);location.assign(grid.dataset.cwtUrl+'?'+(certificates.length?'entry='+encodeURIComponent(certificates[0].number):'source='+encodeURIComponent(entry.number)));}
    else if(action==='filter'){const panel=document.getElementById('sales-filter-panel');panel.hidden=!panel.hidden;}
    else if(action==='status'){const options=['all','draft','posted','void'];statusFilter.value=options[(options.indexOf(statusFilter.value)+1)%options.length];document.getElementById('sales-filter-panel').hidden=false;updateSheet();}
    else if(action==='sort'){sortAscending=!sortAscending;grouped=false;updateSheet();announce(`Sorted by date ${sortAscending?'oldest':'newest'} first.`);}
    else if(action==='group'){grouped=!grouped;updateSheet();announce(grouped?'Rows ordered by customer.':'Customer grouping cleared.');}
    else if(action==='columns'){grid.classList.toggle('hide-extra');announce('Additional accounting columns toggled.');}
    else if(action==='freeze'){const frozen=!grid.classList.toggle('no-freeze');document.querySelector('[data-sales-action="freeze"]').setAttribute('aria-pressed',String(frozen));document.querySelector('[data-freeze-label]').textContent=grid.classList.contains('no-freeze')?'Columns unlocked':'Columns locked: 2';}
    else if(action==='wrap'){grid.classList.toggle('wrap-cells');}
    else if(action==='export')exportCsv();
    else if(action==='post')postSelection();
    else if(action==='summary')showInfo('Visible sales summary',`${document.querySelector('[data-total-count]').textContent} active rows · Gross ${document.querySelector('[data-total-gross]').textContent} · Net ${document.querySelector('[data-total-net]').textContent}. Illustrative arithmetic only.`);
    else if(action==='shortcuts')showInfo('Keyboard shortcuts','Shift+N: add draft. Enter: next draft field. Tab: next control. Ctrl/⌘+K: search rows. Ctrl/⌘+P: simulate posting selected drafts. Delete: void selected demo rows.');
    else if(action==='about')showInfo('About Sales','The supplied example rows are editable demonstrations. Saving a draft changes only this tab. Tax labels and arithmetic are illustrative; no accounts, tax certificates, or BIR returns are generated.');
    else if(action==='notifications')showInfo('Notifications','No sales notifications. External services are not connected.');
    else showInfo(action==='ocr'?'Scan OCR':'Import','This integration is not connected yet. No file has been uploaded or processed.');
}
document.addEventListener('click',event=>{
    const button=event.target.closest('[data-sales-action]');if(button)handleAction(button.dataset.salesAction,button.closest('tr[data-sale-id]'),button);
});
const deleteInlineDraft=()=>window.DraftLeaveGuard.confirmDelete(()=>{autoSalesDraft=null;draft.hidden=true;persistSales();announce('Draft deleted.');});
window.SheetPosting.draftActions(draft.cells[draft.cells.length-1],{number:'new sale'}, {
    save:saveDraft,post:()=>postSelection(),delete:deleteInlineDraft,
    more:event=>window.SheetEntryActions.open(event.currentTarget,autoSalesDraft || {number:nextSaleNumber(),status:'draft'},{post:()=>postSelection(),delete:deleteInlineDraft,edit:()=>openInlineEntryPopup()})
});
document.querySelector('[data-row-duplicate]').addEventListener('click',()=>{dialog.close();openDraft(activeRow);});
document.querySelector('[data-row-void]').addEventListener('click',()=>{dialog.close();voidEntries([activeRow]);});
selectAll.addEventListener('change',()=>{[...entries.values()].filter(entry=>!entry.row.hidden).forEach(entry=>entry.row.querySelector('input').checked=selectAll.checked);updateSheet();});
tbody.addEventListener('change',event=>{if(event.target.matches('input[type="checkbox"]'))updateSheet();});
filterInput.addEventListener('input',()=>{globalSearch.value=filterInput.value;updateSheet();});
globalSearch.addEventListener('input',()=>{filterInput.value=globalSearch.value;updateSheet();});
statusFilter.addEventListener('change',updateSheet);
document.querySelectorAll('[data-period-step]').forEach(button=>button.addEventListener('click',()=>{
    leaveGuard.confirm(()=>{
    discardSalesDrafts();
    const [year,month]=workingPeriod.split('-').map(Number);const next=new Date(year,month-1+Number(button.dataset.periodStep),1);
    workingPeriod=`${next.getFullYear()}-${String(next.getMonth()+1).padStart(2,'0')}`;draft.hidden=true;updatePeriod();
    });
}));
document.querySelectorAll('[data-sales-info]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();showInfo(link.textContent.trim().replace(/\s+/g,' '),'This workspace module is not connected yet. Use Home for the dashboard or Settings to edit your company.');}));
const mobileMenu=document.getElementById('sales-menu-toggle');
const menuBackdrop=document.getElementById('sales-backdrop');
function closeMenu(){document.body.classList.remove('sales-menu-open');mobileMenu.setAttribute('aria-expanded','false');menuBackdrop.hidden=true;}
mobileMenu.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');mobileMenu.setAttribute('aria-expanded',String(open));menuBackdrop.hidden=!open;});
menuBackdrop.addEventListener('click',closeMenu);
window.matchMedia('(min-width: 1024px)').addEventListener('change',closeMenu);
dialog.addEventListener('click',event=>{if(event.target!==dialog)return;const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();});
document.addEventListener('keydown',event=>{
    if(dialog.open || document.getElementById('sales-detail-drawer')?.open || document.getElementById('sales-print-dialog')?.open || document.querySelector('.draft-leave-dialog[open]'))return;
    const editing=event.target.matches('input,select,textarea');
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();filterInput.focus();}
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='p'){event.preventDefault();postSelection();}
    if(!editing && event.shiftKey && event.key.toLowerCase()==='n'){event.preventDefault();openDraft();}
    if(!editing && event.key==='Delete'){event.preventDefault();voidEntries(selectedEntries());}
    if(!editing && event.key==='?')handleAction('shortcuts');
    if(event.key==='Escape')closeMenu();
});
updatePeriod();

const entryDrawer = document.getElementById('sales-detail-drawer');
const entryFields = Object.fromEntries([...entryDrawer.querySelectorAll('[data-entry-field]')].map(field => [field.dataset.entryField, field]));
let editingEntry = null;
let entryFromInline = false;
let entryIsEditable = true;
let entryAttachments = [];
const entryCustomerInput = document.createElement('input');
entryCustomerInput.className = entryFields.Customer.className;
entryCustomerInput.setAttribute('aria-label', 'Entry Customer');
entryCustomerInput.setAttribute('list', 'sales-customers');
entryCustomerInput.placeholder = 'Select or enter customer…';
entryCustomerInput.maxLength = 255;
entryCustomerInput.required = true;
entryFields.Customer.replaceWith(entryCustomerInput);
entryFields.Customer = entryCustomerInput;
const popupSelectMappings = {'Tax type':3,'Revenue account':4,'Income withholding':5,'VAT withholding':6,'Terms':7,'Classification':9,'Deposit account':10};
Object.entries(popupSelectMappings).forEach(([name,index]) => {
    entryFields[name].replaceChildren(...[...editorFields[index].options].map(option => option.cloneNode(true)));
});
['Government','PEZA / Ecozone'].forEach(label => entryFields.Classification.add(new Option(label,label)));
entryFields.Date.required = true;
entryFields['Gross amount'].required = true;
entryFields['Gross amount'].min = '0.01';
entryFields.Description.required = false;
entryFields['Revenue account'].required = true;
const revenuePlaceholder = new Option('Select revenue account…','');
revenuePlaceholder.disabled = true;
entryFields['Revenue account'].prepend(revenuePlaceholder);
entryFields.Description.maxLength = 1000;
entryFields.Particulars.maxLength = 2000;
function setEntryValue(name,value) {
    const field = entryFields[name];
    if(field.tagName === 'SELECT') window.SheetMasters.selectValue(field,value);
    field.value = value;
    field.setCustomValidity('');
}
function updateEntryPreview() {
    const gross = amountToCents(entryFields['Gross amount'].value) || 0;
    const preview = {gross,tax:entryFields['Tax type'].value,cwt:entryFields['Income withholding'].value};
    const totals = amounts(preview);
    const vatRate = Number(entryFields['VAT withholding'].value.match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);
    const vatCwt = Math.round(totals.net * vatRate / 100);
    const totalCwt = totals.cwt + vatCwt;
    document.getElementById('derived-net').textContent = currency(totals.net);
    document.getElementById('derived-vat').textContent = currency(totals.vat);
    document.getElementById('derived-cwt-it').textContent = currency(totals.cwt);
    document.getElementById('derived-cwt-vat').textContent = currency(vatCwt);
    const previewReady = gross > 0 && entryFields.Customer.value.trim() && entryFields['Revenue account'].value;
    document.getElementById('journal-preview-placeholder').hidden = Boolean(previewReady);
    const previewTable = document.getElementById('journal-preview-table');
    previewTable.classList.remove('hidden');
    previewTable.hidden = !previewReady;
    document.getElementById('je-dr-cash').textContent = currency(gross-totalCwt);
    document.getElementById('je-dr-cwt').textContent = currency(totalCwt);
    document.getElementById('je-cr-rev').textContent = currency(totals.net);
    document.getElementById('je-cr-vat').textContent = currency(totals.vat);
    entryDrawer.querySelector('[data-entry-settlement]').textContent = entryFields.Terms.value === 'Cash' ? entryFields['Deposit account'].value : entryFields.Terms.value;
    entryDrawer.querySelector('[data-entry-debit-label]').textContent = entryFields.Terms.value === 'Cash' ? 'Dr. Cash / Bank' : 'Dr. Receivable';
    entryDrawer.querySelector('[data-entry-revenue-label]').textContent = `Cr. ${entryFields['Revenue account'].value}`;
    document.getElementById('entry-tax-hint').textContent = window.SheetMasters.taxLabel(entryFields['Tax type'].value);
    document.getElementById('entry-balance-label').textContent = `Balanced: ${currency(0)}`;
    entryFields['Settlement amount'].max = (gross/100).toFixed(2);
}
function openEntryDrawer(entry=null,asNew=false,enableEdit=false) {
    entryFromInline = false;
    if(dialog.open) dialog.close();
    editingEntry = asNew ? null : entry;
    const locked = Boolean(editingEntry && editingEntry.status!=='draft' && !enableEdit);
    entryIsEditable = !locked;
    entryAttachments = asNew ? [] : [...(entry?.attachments || [])];
    document.getElementById('drawer-title').textContent = editingEntry?.status==='draft' || !editingEntry ? 'New entry' : editingEntry.number;
    const badgeStatus = editingEntry?.status || 'draft';
    const statusBadge = document.getElementById('entry-state-badge');
    statusBadge.classList.remove('draft-status-badge','posted-status-badge','void-status-badge');
    statusBadge.classList.add(badgeStatus==='draft'?'draft-status-badge':badgeStatus==='posted'?'posted-status-badge':'void-status-badge');
    statusBadge.textContent=badgeStatus==='draft'?'Drafting':badgeStatus==='posted'?'Posted':'Voided';
    document.getElementById('drawer-subtitle').textContent = editingEntry ? `${editingEntry.status === 'void' ? 'Voided' : editingEntry.status === 'posted' ? 'Posted (demo)' : 'Draft'} · ${locked ? 'View entry' : 'Editing entry'} · Tab only` : 'Single-line demo entry · Saved only in this tab';
    const defaultDate = window.WorkingPeriod.matches(editorFields[0].value) ? editorFields[0].value : window.WorkingPeriod.entryDate();
    const values = {'Date':entry?.date || defaultDate,'Customer':entry?.customer || '', 'Gross amount':entry ? (entry.gross/100).toFixed(2) : '', 'Tax type':entry?.tax ?? '','Revenue account':entry?.account ?? '','Income withholding':entry?.cwt ?? '','VAT withholding':entry?.vatCwt ?? '','Terms':entry?.terms || 'Cash','Classification':entry?.classification || 'Regular','Invoice reference':entry?.reference || '', 'Deposit account':entry?.bank || '1010 · Cash on Hand','Description':entry?.memo || '', 'Particulars':entry?.notes || ''};
    Object.entries(values).forEach(([name,value]) => {
        setEntryValue(name,value);
        const field = entryFields[name];
        if(field.tagName === 'SELECT') field.disabled = locked;
        else field.readOnly = locked;
    });
    setEntryValue('Settlement amount', entry?.settlement ?? '');
    entryFields['Settlement amount'].readOnly = locked;
    if(!entry)setEntryValue('Revenue account','');
    document.getElementById('entry-validation').textContent = '';
    document.getElementById('entry-suggestion-status')?.replaceChildren();
    document.getElementById('entry-new-customer').hidden = locked;
    document.getElementById('entry-match-gross').hidden = locked;
    document.getElementById('save-draft-btn').hidden = locked;
    document.getElementById('post-sale-btn').hidden = locked;
    document.getElementById('delete-entry-btn').hidden = locked;
    document.getElementById('save-draft-btn').textContent = editingEntry?.status==='posted' ? 'Save changes' : 'Save Draft';
    document.getElementById('post-sale-btn').hidden = locked || editingEntry?.status === 'posted' || editingEntry?.status === 'void';
    document.getElementById('edit-entry-btn').hidden = !locked || editingEntry?.status === 'void';
    document.getElementById('void-entry-btn').hidden = !editingEntry;
    document.getElementById('print-entry-btn').hidden = !editingEntry;
    document.getElementById('print-entry-btn').disabled = !locked;
    document.getElementById('print-entry-btn').title = locked ? 'Print saved entry' : 'Save changes before printing';
    document.querySelector('[data-void-label]').textContent = editingEntry?.status === 'void' ? 'Restore' : 'Void';
    document.getElementById('entry-void-confirm').hidden = true;
    document.getElementById('invoice-files').disabled = locked;
    document.getElementById('invoice-upload-label').hidden = locked;
    document.getElementById('invoice-file-error').textContent = '';
    document.getElementById('invoice-files').value = '';
    renderInvoiceFiles();
    entryDrawer.querySelector('[data-entry-quick-picks]').hidden = locked;
    updateEntryPreview();
    renderVoidDetails();
    popupBaseline=popupSnapshot();
    if(!entryDrawer.open) entryDrawer.showModal();
    if(locked) document.getElementById('close-drawer-btn').focus();
    else entryFields.Customer.focus({preventScroll:true});
    entryDrawer.querySelector('.overflow-y-auto').scrollTop = 0;
}
function saveEntryDrawer(posted=false) {
    if(!entryIsEditable) return;
    const gross = amountToCents(entryFields['Gross amount'].value);
    entryFields.Customer.value = entryFields.Customer.value.trim();
    entryFields['Gross amount'].setCustomValidity(gross !== null && gross > 0 ? '' : 'Enter a positive amount with at most two decimal places.');
    if(posted||editingEntry?.status==='posted'){
        for(const field of Object.values(entryFields)){if(!field.reportValidity())return;}
    }else{
        if(entryFields['Gross amount'].value&&gross===null){entryFields['Gross amount'].reportValidity();return;}
        for(const field of Object.values(entryFields)){if(field!==entryFields['Gross amount']&&field.value&&!field.reportValidity())return;}
    }
    const entry = {id:editingEntry?.id || nextNumber, number:editingEntry?.number || nextSaleNumber(), date:entryFields.Date.value || workingPeriod+'-01',customer:entryFields.Customer.value,gross:gross || 0,tax:entryFields['Tax type'].value,account:entryFields['Revenue account'].value,cwt:entryFields['Income withholding'].value,vatCwt:entryFields['VAT withholding'].value,terms:entryFields.Terms.value,reference:entryFields['Invoice reference'].value.trim(),classification:entryFields.Classification.value,bank:entryFields['Deposit account'].value,memo:entryFields.Description.value.trim(),notes:entryFields.Particulars.value.trim(),status:posted?'posted':'draft'};
    if(posted||editingEntry?.status==='posted'){const error=window.SheetMasters.withholdingError(entry,true);if(error){announce(error);return;}}
    entry.attachments = [...entryAttachments];
    entry.settlement = entryFields['Settlement amount'].value;
    if(editingEntry?.voidAudit) entry.voidAudit=editingEntry.voidAudit;
    if(editingEntry?.previousStatus) entry.previousStatus=editingEntry.previousStatus;
    if(editingEntry && !posted) entry.status = editingEntry.status;
    renderEntry(entry,editingEntry?.row);
    if(!editingEntry) nextNumber++;
    if(entryFromInline) draft.hidden = true;
    window.WorkingPeriod.revealDate(entry.date);workingPeriod=window.WorkingPeriod.current().from.slice(0,7);
    filterInput.value='';globalSearch.value='';statusFilter.value='all';
    updatePeriod();
    entryDrawer.close();
    announce(`${entry.number} ${posted?'marked Posted (demo)':editingEntry?'updated':'saved as draft'} in this tab. No real ledger entry was created.`);
}
function suggestSalesFields(popup){
    if(popup && (!entryIsEditable || editingEntry && editingEntry.status!=='draft'))return;
    const mapping={customer:'Customer',account:'Revenue account',tax:'Tax type',cwt:'Income withholding',vatCwt:'VAT withholding'};
    const record={status:'draft',...Object.fromEntries(Object.entries(mapping).map(([key,name])=>[key,popup?entryFields[name].value:editorFields[fieldNames.indexOf(name)].value]))};
    const suggestion=window.SheetMasters.partyDefaults(record,true);
    for(const [key,value] of Object.entries(suggestion.values)){const target=popup?entryFields[mapping[key]]:editorFields[fieldNames.indexOf(mapping[key])];if(popup)setEntryValue(mapping[key],value);else window.SheetMasters.selectValue(target,value);target.dispatchEvent(new Event('masters:sync'));}
    if(suggestion.sourceNumber){const message='Suggested account and tax settings from '+suggestion.sourceNumber+'. Review before posting.';announce(message);if(popup){let notice=document.getElementById('entry-suggestion-status');if(!notice){notice=document.createElement('p');notice.id='entry-suggestion-status';notice.className='text-body-sm text-secondary';notice.setAttribute('role','status');document.getElementById('entry-validation').before(notice);}notice.textContent=message;}}
}
Object.values(entryFields).forEach(field => {
    field.addEventListener('input',()=>{field.setCustomValidity('');updateEntryPreview();autoSaveSalesForm(true);});
    field.addEventListener('change',()=>{if(field===entryFields.Customer)suggestSalesFields(true);updateEntryPreview();autoSaveSalesForm(true);});
});
document.getElementById('new-entry-form').addEventListener('submit',event=>{event.preventDefault();saveEntryDrawer(false);});
document.getElementById('entry-match-gross').addEventListener('click',()=>{
    entryFields['Settlement amount'].value = entryFields['Gross amount'].value;
    updateEntryPreview();
});
document.getElementById('entry-new-customer').addEventListener('click',()=>{
    window.SheetMasters.openCreator(entryFields.Customer,'parties',entryFields.Customer.value,'Customer');
});
document.getElementById('save-draft-btn').addEventListener('click',()=>saveEntryDrawer(false));
document.getElementById('post-sale-btn').addEventListener('click',()=>saveEntryDrawer(true));
document.getElementById('close-drawer-btn').addEventListener('click',requestCloseEntry);
entryDrawer.addEventListener('cancel',event=>{event.preventDefault();requestCloseEntry();});
document.getElementById('delete-entry-btn').addEventListener('click',()=>{
    if(editingEntry&&editingEntry.status!=='draft'){requestCloseEntry();return;}
    window.DraftLeaveGuard.confirmDelete(()=>{if(editingEntry){entries.delete(editingEntry.id);editingEntry.row.remove();updateSheet();}autoSalesDraft=null;if(entryFromInline)draft.hidden=true;persistSales();entryDrawer.close();announce('Draft deleted.');},editingEntry?.number || 'this new entry');
});
entryDrawer.querySelector('[data-entry-quick-picks]').addEventListener('click',()=>{
    const descriptions=[...new Set([...entries.values()].map(entry=>entry.memo).filter(Boolean))];
    const current=descriptions.indexOf(entryFields.Description.value);
    entryFields.Description.value=descriptions[(current+1)%descriptions.length] || '';
});
tbody.addEventListener('click',event=>{
    const opener=event.target.closest('[data-open-entry]');
    if(opener) {openEntryDrawer(entries.get(Number(opener.dataset.openEntry)));return;}
    if(event.target.closest('button,input,select,textarea,a,label'))return;
    const row=event.target.closest('tr[data-sale-id]');
    if(row)openEntryDrawer(entries.get(Number(row.dataset.saleId)));
});

function renderInvoiceFiles() {
    const list = document.getElementById('invoice-file-list');
    list.replaceChildren();
    if(!entryAttachments.length) {
        const empty = document.createElement('li');
        empty.textContent = 'No documents attached.';
        list.append(empty);
    }
    entryAttachments.forEach((file,index) => {
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.textContent = `${file.name} (${(file.size/1024).toFixed(0)} KB)`;
        link.href = file.url;
        link.target = '_blank';
        link.rel = 'noopener';
        item.append(link);
        if(entryIsEditable) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label',`Remove attachment ${file.name}`);
            remove.addEventListener('click',()=>{entryAttachments.splice(index,1);renderInvoiceFiles();});
            item.append(remove);
        }
        list.append(item);
    });
}
document.getElementById('invoice-files').addEventListener('change',event=>{
    if(!entryIsEditable)return;
    const selected = [...event.target.files];
    const error = document.getElementById('invoice-file-error');
    error.textContent = '';
    if(selected.length + entryAttachments.length > 5) error.textContent = 'Attach at most 5 documents per sale.';
    else if(selected.some(file=>file.size > 10*1024*1024 || file.size === 0)) error.textContent = 'Each document must be non-empty and no larger than 10 MB.';
    else if(selected.some(file=>!['application/pdf','image/jpeg','image/png','image/webp'].includes(file.type))) error.textContent = 'Choose PDF, JPG, PNG or WebP documents.';
    else {
        selected.forEach(file=>entryAttachments.push({name:file.name,size:file.size,url:URL.createObjectURL(file)}));
        renderInvoiceFiles();
    }
    event.target.value = '';
});
document.getElementById('edit-entry-btn').addEventListener('click',()=>{
    if(editingEntry && editingEntry.status !== 'void')openEntryDrawer(editingEntry,false,true);
});
document.getElementById('void-entry-btn').addEventListener('click',()=>{
    if(!editingEntry)return;
    if(editingEntry.status === 'void') {
        if((editingEntry.previousStatus || 'draft')==='draft'&&(!draft.hidden||[...entries.values()].some(record=>record.id!==editingEntry.id&&record.status==='draft'))){announce('Post or delete the existing draft before restoring another draft.');return;}
        editingEntry.status = editingEntry.previousStatus || 'draft';
        renderEntry(editingEntry,editingEntry.row);updateSheet();
        announce(`${editingEntry.number} restored in this tab.`);
        openEntryDrawer(editingEntry);
    } else {document.getElementById('void-entry-reason').value='';document.getElementById('entry-void-confirm').hidden = false;document.getElementById('void-entry-reason').focus();}
});
document.getElementById('cancel-void-entry').addEventListener('click',()=>{document.getElementById('entry-void-confirm').hidden=true;});
document.getElementById('confirm-void-entry').addEventListener('click',()=>{
    if(!editingEntry || editingEntry.status === 'void')return;
    const reasonField = document.getElementById('void-entry-reason');
    if(!reasonField.value.trim()){reasonField.setCustomValidity('Enter a reason for voiding this entry.');reasonField.reportValidity();return;}
    editingEntry.voidAudit = {reason:reasonField.value.trim(),user:companyDetails.full_name || 'Demo user',time:new Date().toISOString()};
    editingEntry.previousStatus = editingEntry.status;
    editingEntry.status = 'void';
    renderEntry(editingEntry,editingEntry.row);updateSheet();
    announce(`${editingEntry.number} voided in this tab. Restore it from the entry popup.`);
    openEntryDrawer(editingEntry);
});

const printDialog = document.getElementById('sales-print-dialog');
const companyDetails = JSON.parse(document.getElementById('sales-company-data').textContent);
const escapeStatement = value => String(value ?? '').replace(/[&<>"']/g,character=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
function statementMarkup(entry) {
    const safe = escapeStatement;
    const totals = amounts(entry);
    const vatWithholding = Math.round(totals.net*Number(entry.vatCwt.match(/(\d+(?:\.\d+)?)%/)?.[1] || 0)/100);
    const address = ['building','street','barangay','city','province','zip_code'].map(key=>companyDetails[key]).filter(Boolean).join(', ');
    const date = new Date(`${entry.date}T12:00:00`).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
    return `<article class="billing-statement">
        <header class="statement-heading"><div><h1>${safe(companyDetails.company_name)}</h1><p>${safe(companyDetails.registered_name || companyDetails.company_name)}</p><p>${safe(address || 'Address not provided')}</p><p>TIN: ${safe(window.SheetMasters.formatTin(companyDetails.tin,companyDetails.branch_code) || 'Not provided')}</p><p>Tel: ${safe(companyDetails.contact_number || 'Not provided')}</p></div><div class="statement-right"><h2>Billing Statement</h2><strong>No. ${safe(entry.number)}</strong><p>${safe(entry.status === 'void' ? 'VOIDED' : entry.status === 'draft' ? 'DRAFT' : 'POSTED (DEMO)')}</p></div></header>
        <section class="statement-bill-to"><div><small>BILL TO</small><h3>${safe(entry.customer)}</h3><p>Customer address / TIN: Not provided</p></div><div class="statement-right"><p>Date: ${safe(date)}</p><p>Reference: ${safe(entry.reference || '—')}</p></div></section>
        <section class="statement-terms"><div><strong>TERMS</strong><p>${safe(entry.terms)}</p></div><div><strong>AUDIT TRAIL</strong><p>Status: ${safe(entry.status)}</p></div></section>
        <table><thead><tr><th>#</th><th>Description</th><th>Account</th><th>Type</th><th class="statement-right">Amount</th></tr></thead><tbody><tr><td>1</td><td>${safe(entry.memo || entry.account)}<small class="statement-cwt">CWT ${safe(entry.cwt)}</small></td><td>${safe(entry.account)}</td><td>${safe(window.SheetMasters.taxLabel(entry.tax))}</td><td class="statement-right">${safe(currency(entry.gross))}</td></tr></tbody></table>
        <dl class="statement-totals"><div><dt>Net of VAT</dt><dd>${safe(currency(totals.net))}</dd></div><div><dt>VAT</dt><dd>${safe(currency(totals.vat))}</dd></div><div class="statement-total"><dt>Total</dt><dd>${safe(currency(entry.gross))}</dd></div><div><dt>Less: expected CWT (income tax)</dt><dd>${safe(currency(totals.cwt))}</dd></div><div><dt>Less: expected CWT (VAT)</dt><dd>${safe(currency(vatWithholding))}</dd></div><div class="statement-total"><dt>Net collectible for this entry</dt><dd>${safe(currency(entry.gross-totals.cwt-vatWithholding))}</dd></div></dl>
        <section class="statement-balances"><h3>STATEMENT OF ACCOUNT - PRIOR UNSETTLED BALANCES</h3><p>Prior balances, payments and credits are not available in this local preview. The amounts above apply only to this entry and do not confirm payment or an outstanding account balance.</p></section>
        <section><h3>Particulars</h3><p class="statement-notes">${safe(entry.notes || entry.memo || '—')}</p></section>
        <section><h3>Invoice documents</h3><p>${safe(entry.attachments?.map(file=>file.name).join(', ') || 'No documents attached.')}</p></section>
        <div class="statement-signatures"><span>Prepared by</span><span>Approved by</span><span>Received by</span></div>
        <footer><span>Local demo statement · No ledger or tax filing generated.</span><span>${safe(entry.number)}</span></footer>
    </article>`;
}
document.getElementById('print-entry-btn').addEventListener('click',()=>{
    if(!editingEntry || entryIsEditable)return;
    document.getElementById('statement-preview').innerHTML = statementMarkup(editingEntry);
    entryDrawer.close();
    printDialog.showModal();
});
document.getElementById('close-print-btn').addEventListener('click',()=>{printDialog.close();openEntryDrawer(editingEntry);});
document.getElementById('print-statement-btn').addEventListener('click',()=>{
    document.getElementById('sales-print-output').innerHTML = statementMarkup(editingEntry);
    printDialog.close();
    window.print();
    printDialog.showModal();
});
printDialog.addEventListener('cancel',event=>{event.preventDefault();printDialog.close();openEntryDrawer(editingEntry);});

document.getElementById('void-entry-reason').addEventListener('input',event=>event.target.setCustomValidity(''));
function renderVoidDetails() {
    const voided = editingEntry?.status === 'void';
    entryDrawer.classList.toggle('is-void',voided);
    entryDrawer.querySelector('.entry-drawer-content > div:first-child .material-symbols-outlined').textContent = voided ? 'block' : 'add_circle';
    document.getElementById('void-audit-card').hidden = !voided;
    const reversalCard = document.getElementById('void-reversal-card');
    entryDrawer.querySelector('.entry-journal').before(reversalCard);
    reversalCard.hidden = !voided;
    entryDrawer.querySelector('.entry-journal').hidden = voided;
    document.getElementById('delete-voided-entry-btn').hidden = !voided;
    document.getElementById('edit-void-reason-btn').hidden = !voided;
    document.getElementById('void-reason-editor').hidden = true;
    document.getElementById('save-void-reason').hidden = true;
    document.getElementById('void-audit-reason').hidden = false;
    if(!voided)return;
    document.getElementById('print-entry-btn').hidden = true;
    document.getElementById('drawer-title').textContent = 'Void entry';
    document.getElementById('drawer-subtitle').textContent = `Local demo record · ${editingEntry.number}`;
    const audit = editingEntry.voidAudit;
    document.getElementById('void-audit-reference').textContent = `VOID-${editingEntry.number}`;
    document.getElementById('void-audit-reason').textContent = audit?.reason || 'No reason recorded.';
    document.getElementById('void-audit-user').textContent = audit?.user || 'Not recorded';
    document.getElementById('void-audit-time').textContent = audit?.time ? new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'medium'}).format(new Date(audit.time)) : 'Not recorded';
    entryDrawer.querySelector('[data-entry-settlement]').textContent = 'Voided · Excluded from totals';
    const totals = amounts(editingEntry);
    const vatCwt = Math.round(totals.net*Number(editingEntry.vatCwt.match(/(\d+(?:\.\d+)?)%/)?.[1] || 0)/100);
    const lines = [[`Dr. ${editingEntry.account}`,totals.net],['Dr. Output VAT Payable',totals.vat],[`Cr. ${editingEntry.terms === 'Cash' ? editingEntry.bank : 'Receivable'}`,editingEntry.gross-totals.cwt-vatCwt],['Cr. Creditable Withholding',totals.cwt+vatCwt]];
    const list = document.getElementById('void-reversal-lines');list.replaceChildren();
    lines.filter(([,value])=>value!==0).forEach(([label,value])=>{
        const line=document.createElement('div');line.className='flex justify-between gap-2';
        const name=document.createElement('span');name.textContent=label;
        const amount=document.createElement('span');amount.textContent=currency(value);
        line.append(name,amount);list.append(line);
    });
}
document.getElementById('edit-void-reason-btn').addEventListener('click',()=>{
    const editor = document.getElementById('void-reason-editor');
    editor.value=editingEntry?.voidAudit?.reason || '';
    editor.hidden=false;
    document.getElementById('void-audit-reason').hidden=true;
    document.getElementById('save-void-reason').hidden=false;
    editor.focus();
});
document.getElementById('save-void-reason').addEventListener('click',()=>{
    const editor=document.getElementById('void-reason-editor');
    if(!editor.value.trim()){editor.setCustomValidity('Enter a void reason.');editor.reportValidity();return;}
    editingEntry.voidAudit={...editingEntry.voidAudit,reason:editor.value.trim()};
    persistSales();
    renderVoidDetails();
});
document.getElementById('void-reason-editor').addEventListener('input',event=>event.target.setCustomValidity(''));
document.getElementById('delete-voided-entry-btn').addEventListener('click',()=>{
    if(editingEntry?.status!=='void')return;
    const record=editingEntry;
    record.status='archived';updateSheet();entryDrawer.close();
    announce(`${record.number} removed from this tab. `);
    const undo=document.createElement('button');undo.type='button';undo.textContent='Undo delete';undo.className='underline';
    undo.addEventListener('click',()=>{record.status='void';updateSheet();announce(`${record.number} restored as voided.`);openEntryDrawer(record);});
    statusMessage.append(undo);
});

function inlineDraftRecord(){const values=editorFields.map(field=>field.value.trim());return {id:nextNumber,number:nextSaleNumber(),date:values[0],customer:values[1],gross:amountToCents(values[2]) || 0,tax:values[3],account:values[4],cwt:values[5],vatCwt:values[6],terms:values[7],reference:values[8],classification:values[9],bank:values[10],memo:values[11],status:'draft'};}
function autoSaveSalesForm(popup,touched=true){
    if(popup&&(!entryDrawer.open||!entryIsEditable||editingEntry?.status==='posted'||editingEntry?.status==='void'))return;
    if(!popup&&draft.hidden)return;
    const record=popup?{id:editingEntry?.id || nextNumber,number:editingEntry?.number || nextSaleNumber(),date:entryFields.Date.value,customer:entryFields.Customer.value,gross:amountToCents(entryFields['Gross amount'].value) || 0,tax:entryFields['Tax type'].value,account:entryFields['Revenue account'].value,cwt:entryFields['Income withholding'].value,vatCwt:entryFields['VAT withholding'].value,terms:entryFields.Terms.value,reference:entryFields['Invoice reference'].value,classification:entryFields.Classification.value,bank:entryFields['Deposit account'].value,memo:entryFields.Description.value,notes:entryFields.Particulars.value,status:'draft'}:inlineDraftRecord();
    record.draftTouched=Boolean(touched||autoSalesDraft?.draftTouched||editingEntry?.draftTouched);
    if(!window.DraftLeaveGuard.hasDraftDetails(record))return;
    if(editingEntry?.status==='draft'&&popup)Object.assign(editingEntry,record);else autoSalesDraft=record;
    persistSales();
    refreshPostCount();
}
function keepAutoSalesDraft(){if(autoSalesDraft){const record=autoSalesDraft;autoSalesDraft=null;renderEntry(record);nextNumber=Math.max(nextNumber,record.id+1);}else if(editingEntry?.status==='draft')renderEntry(editingEntry,editingEntry.row);draft.hidden=true;persistSales();}
function inlineDraftChanged(){return !draft.hidden&&window.DraftLeaveGuard.hasDraftDetails(inlineDraftRecord());}
function hasSalesDrafts(){return Boolean(autoSalesDraft)||[...entries.values()].some(entry=>window.DraftLeaveGuard.hasDraftDetails(entry));}
function popupSnapshot() {
    return JSON.stringify({fields:Object.values(entryFields).map(field=>field.value),attachments:entryAttachments.map(file=>[file.name,file.size,file.url])});
}
function popupDraftChanged() {
    return entryDrawer.open&&entryIsEditable&&popupSnapshot()!==popupBaseline;
}
function discardSalesDrafts() {
    autoSalesDraft=null;for(const entry of [...entries.values()])if(window.DraftLeaveGuard.hasDraftDetails(entry)){entry.row.remove();entries.delete(entry.id);}
    draft.hidden=true;persistSales();
    entryDrawer.close();
    popupBaseline=popupSnapshot();
}
function requestCloseEntry() {
    if(entryIsEditable&&(!editingEntry||editingEntry.status==='draft')){autoSaveSalesForm(true,false);keepAutoSalesDraft();entryDrawer.close();popupBaseline=popupSnapshot();return;}
    const close=()=>{
        if(entryFromInline)draft.hidden=true;
        entryDrawer.close();
        announce('Unsaved entry changes discarded.');
    };
    if(popupDraftChanged()||(entryFromInline&&inlineDraftChanged()))leaveGuard.confirm(close,true);
    else entryDrawer.close();
}
const leaveGuard=new window.DraftLeaveGuard({hasChanges:()=>hasSalesDrafts()||inlineDraftChanged()||popupDraftChanged(),discard:discardSalesDrafts});
const requestedSale=new URLSearchParams(location.search).get('entry');
if(requestedSale){const sale=[...entries.values()].find(entry=>entry.number===requestedSale);if(sale)openEntryDrawer(sale);}

window.SheetBulk={selected:selectedEntries,refresh:()=>{const saved=window.KontaduryaLedger.sales();for(const entry of [...entries.values()])if(!saved.some(item=>item.id===entry.id)){entry.row.remove();entries.delete(entry.id);}for(const item of saved){if(autoSalesDraft?.id===item.id){Object.assign(autoSalesDraft,item);continue;}const previous=entries.get(item.id);renderEntry({...item,attachments:previous?.attachments || []},previous?.row);}nextNumber=autoSalesDraft?.id || Math.max(0,...saved.map(item=>Number(item.id) || 0))+1;if(!draft.hidden)draft.cells[3].textContent=nextSaleNumber()+'*';updateSheet();},announce};

document.addEventListener('working-period:change',event=>{workingPeriod=event.detail.period;updatePeriod();});
