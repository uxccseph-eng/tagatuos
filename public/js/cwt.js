(() => {
    const ledger=window.KontaduryaLedger;
    const element=id=>document.getElementById(id);
    const rows=element('cwt-rows');
    const table=element('cwt-table');
    const dialog=element('cwt-info');
    const entryDialog=element('cwt-entry');
    const entryFields=Object.fromEntries([...entryDialog.querySelectorAll('[data-cwt-field]')].map(field=>[field.dataset.cwtField,field]));
    let activeRecord=null,memoEditing=false;
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format((value || 0)/100);
    let records=ledger.cwtCertificates();
    let period=window.WorkingPeriod.current().from.slice(0,7);
    let status='all',descending=false,grouped=false;
    const selected=new Set();
    const announce=message=>{element('cwt-status').textContent=message;if(entryDialog.open)element('cwt-entry-message').textContent=message;};
    const sources=()=>ledger.sales().map(sale=>ledger.cwtSource(sale.id)).filter(sale=>sale&&sale.withheld>0);
    const match=record=>{const sale=ledger.cwtSource(record.saleId);return sale&&sale.number===record.invoice&&sale.customer===record.customer&&sale.taxBase===record.taxBase&&sale.cwt===record.atc&&sale.withheld===record.amount;};
    function persist(){ledger.saveCwtCertificates(records);}
    function visible(){const query=`${element('cwt-search').value} ${element('sales-global-search').value}`.trim().toLowerCase();return records.filter(record=>record.status!=='archived'&&window.WorkingPeriod.matches(record.date)&&(status==='all'||record.status===status)&&`${record.number} ${record.customer} ${record.tin} ${record.invoice} ${record.atc} ${record.certificate}`.toLowerCase().includes(query));}
    function info(title,message,actions=[]){
        element('cwt-dialog-title').textContent=title;element('cwt-dialog-text').textContent=message;
        element('cwt-dialog-actions').replaceChildren(...actions.map(([label,callback])=>button(label,callback)));
        if(!dialog.open)dialog.showModal();
    }
    function button(label,callback){const result=document.createElement('button');result.type='button';result.textContent=label;result.addEventListener('click',callback);return result;}
    function rowAction(label,icon,record,callback){
        const action=button('',callback);action.className='cwt-row-action';action.title=label;action.setAttribute('aria-label',`${label} ${record.number}`);
        const symbol=document.createElement('span');symbol.className='material-symbols-outlined';symbol.textContent=icon;symbol.setAttribute('aria-hidden','true');action.append(symbol);return action;
    }
    const invalidDraft=record=>record.status==='draft'&&(!record.date||!record.customer||!record.tin||!record.amount);
    const incomplete=record=>window.DraftLeaveGuard.hasDraftDetails(record);
    function saveDraft(record){record.draftSaved=true;persist();render();announce(record.number+' draft saved in this tab. Post it when ready.');}
    function deleteDraft(record){window.DraftLeaveGuard.confirmDelete(()=>{records=records.filter(item=>item.id!==record.id);persist();render();if(entryDialog.open&&activeRecord?.id===record.id)entryDialog.close();announce('Draft deleted.');},record.number);}
    const guard=new window.DraftLeaveGuard({hasChanges:()=>records.some(incomplete),discard:()=>{records=records.filter(record=>!incomplete(record));persist();render();}});
    function add(saleId){
        const pending=window.DraftLeaveGuard.continueDraft(records,record=>{window.WorkingPeriod.revealDate(record.date);period=window.WorkingPeriod.current().from.slice(0,7);status='all';element('cwt-search').value='';element('sales-global-search').value='';render();element('cwt-row-'+record.id)?.scrollIntoView({block:'nearest'});announce('Continue '+record.number+'. Only one draft is allowed on this page.');});if(pending)return pending;
        const sale=ledger.cwtSource(saleId);
        const existing=records.find(record=>record.saleId===saleId&&record.status!=='void');
        if(sale&&existing){window.WorkingPeriod.revealDate(existing.date);period=window.WorkingPeriod.current().from.slice(0,7);status='all';element('cwt-search').value='';element('sales-global-search').value='';render();announce(`Continue ${existing.number}; a certificate already exists for this invoice.`);element(`cwt-row-${existing.id}`)?.scrollIntoView({block:'nearest'});return;}
        const record={id:crypto.randomUUID(),number:'',date:window.WorkingPeriod.entryDate(),saleId:sale?.id || null,invoice:sale?.number || '',customer:sale?.customer || '',tin:sale?.tin || '',taxBase:sale?.taxBase || 0,atc:sale?.cwt || '',amount:sale?.withheld || 0,offset:'1150 · CWT Asset',certificate:'',status:'draft'};
        records.push(record);status='all';element('cwt-search').value='';element('sales-global-search').value='';persist();render();
        element(`cwt-row-${record.id}`).scrollIntoView({block:'nearest'});announce(`${record.number} added as an editable draft. Enter the received certificate details.`);
    }
    function control(record,key,type='text'){
        const field=document.createElement('input');field.type=type;field.value=record[key] || '';field.maxLength=255;
        field.setAttribute('aria-label',`${record.number} ${key==='certificate'?'Certificate number':key==='tin'?'Agent TIN':'Date'}`);
        field.addEventListener('input',()=>{record.draftTouched=true;record.draftSaved=false;record[key]=field.value.trim();persist();refresh();});return field;
    }
    function post(candidates){
        if(!candidates.length){announce('Select one or more draft certificates to post.');return;}
        const numbers=new Set(records.filter(record=>record.status==='posted').map(record=>String(record.certificate || '').toLowerCase()));
        const saleIds=new Set(records.filter(record=>record.status==='posted').map(record=>record.saleId));
        for(const record of candidates){
            const atcError=window.SheetMasters.withholdingError({cwt:record.atc,vatCwt:'None'},true);if(atcError){announce(record.number+': '+atcError);return false;}
            if(invalidDraft(record)||!/^\d{3}-?\d{3}-?\d{3}(?:-?\d{3,5})?$/.test(record.tin)){announce(`${record.number}: enter a date, agent TIN and withholding amount.`);return;}
            if(!match(record)){announce(`${record.number}: the source sale is missing or changed. Select its invoice again to refresh the draft.`);return;}
            if((record.certificate&&numbers.has(String(record.certificate).toLowerCase()))||saleIds.has(record.saleId)){announce(`${record.number}: this certificate number or invoice already has a posted certificate.`);return;}
            if(record.certificate)numbers.add(String(record.certificate).toLowerCase());saleIds.add(record.saleId);
        }
        const ids=new Set(candidates.map(record=>record.id)),postedAt=new Date().toISOString();
        const next=records.map(record=>ids.has(record.id)?{...record,status:'posted',postedAt}:record);
        try{ledger.saveCwtCertificates(next);}catch(error){announce(error.message);return false;}
        records=next;selected.clear();render();announce(`${candidates.length} certificate(s) posted.`);return true;
    }
    function inspect(record){
        activeRecord=record;memoEditing=false;element('cwt-entry-message').textContent='';
        entryDialog.classList.toggle('is-void',record.status==='void');
        const draft=record.status==='draft',voided=record.status==='void';
        const badge=element('cwt-entry-badge');badge.className=draft?'draft-status-badge':voided?'void-status-badge':'posted-status-badge';badge.textContent=`${draft?'New entry':voided?'Voided certificate':'Posted certificate'} · ${record.number}`;window.CompanyLedger?.decorateBadge?.(badge,'cwtCertificates',record);
        element('cwt-entry-title').textContent=voided?'BIR Form 2307 Certificate (Cancelled)':'BIR Form 2307 Certificate';
        for(const [key,field] of Object.entries(entryFields)){field.value=record[key] || '';field.readOnly=!draft||['customer','atc'].includes(key);}
        const invoice=element('cwt-entry-invoice');invoice.replaceChildren(new Option('Select posted sales invoice',''));
        sources().forEach(sale=>invoice.add(new Option(`${sale.number} — ${sale.customer}`,String(sale.id))));
        if(record.saleId&&![...invoice.options].some(option=>option.value===String(record.saleId)))invoice.add(new Option(record.invoice,String(record.saleId)));
        invoice.value=String(record.saleId || '');invoice.disabled=!draft;
        element('cwt-draft-actions').hidden=!draft;element('cwt-posted-actions').hidden=record.status!=='posted';element('cwt-void-actions').hidden=!voided;
        element('cwt-entry-memo').lastChild.textContent='Edit Memo';element('cwt-void-audit').hidden=!voided;element('cwt-void-confirm').hidden=true;element('cwt-confirm-actions').hidden=true;
        element('cwt-entry-void-reason').value='';element('cwt-entry-void-reason').setCustomValidity('');
        element('cwt-void-reason-text').textContent=record.voidReason || 'No reason recorded.';
        element('cwt-void-time').textContent=record.voidedAt?`Voided: ${new Date(record.voidedAt).toLocaleString('en-PH',{timeZone:'Asia/Manila'})} PHT`:'';
        element('cwt-cancelled-attributes').hidden=!voided;
        entryDialog.querySelectorAll('[data-cwt-attribute]').forEach(field=>field.textContent=record[field.dataset.cwtAttribute] || 'Not recorded');
        previewEntry();if(!entryDialog.open)entryDialog.showModal();
        entryDialog.querySelector('.cwt-entry-body').scrollTop=0;element('cwt-entry-close').focus({preventScroll:true});
    }
    function previewEntry(){
        const record=activeRecord;if(!record)return;
        const voided=record.status==='void';
        element('cwt-amount-label').textContent=voided?'Reversal Net IT Withheld':'Creditable IT Withheld';
        element('cwt-entry-amount').textContent=money(voided?-record.amount:record.amount);
        element('cwt-entry-base').textContent=money(record.taxBase);
        element('cwt-entry-net').value=((record.taxBase || 0)/100).toFixed(2);element('cwt-entry-it').value=((record.amount || 0)/100).toFixed(2);
        const date=new Date(`${record.date}T12:00:00`);element('cwt-entry-quarter').value=Number.isNaN(date.getTime())?'':`${date.getFullYear()} - Q${Math.floor(date.getMonth()/3)+1}`;
        element('cwt-entry-source-note').textContent=record.invoice?`${record.invoice} · ${record.atc || 'No ATC'} · ${match(record)?'Source matched':'Source needs review'}`:'Select a source sales invoice to fill customer and tax details.';
        element('cwt-journal-title').textContent=voided?'GL Reversal Preview':'Journal Voucher Preview';
        element('cwt-journal-debit').textContent=voided?'Dr 1105 · AR - Trade':'Dr 1150 · Prepaid CWT (2307)';
        element('cwt-journal-credit').textContent=voided?'Cr 1150 · Prepaid CWT (2307)':'Cr 1105 · AR - Trade';
        entryDialog.querySelectorAll('[data-cwt-journal-amount]').forEach(field=>field.textContent=money(record.amount));
    }
    function closeEntry(){if(activeRecord?.status==='draft'){entryDialog.close();return;}
        if(activeRecord&&incomplete(activeRecord)){
            guard.confirm(()=>{records=records.filter(record=>record.id!==activeRecord.id);persist();render();entryDialog.close();},true);
        }else if(memoEditing&&entryFields.notes.value.trim()!==(activeRecord.notes || ''))guard.confirm(()=>entryDialog.close(),true);
        else entryDialog.close();
    }
    Object.entries(entryFields).forEach(([key,field])=>field.addEventListener('input',()=>{
        if(!activeRecord||field.readOnly)return;
        if(activeRecord.status==='draft'){activeRecord.draftTouched=true;activeRecord.draftSaved=false;activeRecord[key]=field.value.trim();persist();render();previewEntry();}
    }));
    element('cwt-entry-invoice').addEventListener('change',event=>{
        if(activeRecord?.status!=='draft')return;
        const sale=ledger.cwtSource(event.target.value);
        Object.assign(activeRecord,{draftTouched:true,draftSaved:false,saleId:sale?.id || null,invoice:sale?.number || '',date:sale?.date || activeRecord.date,customer:sale?.customer || '',tin:sale?.tin || '',taxBase:sale?.taxBase || 0,atc:sale?.cwt || '',amount:sale?.withheld || 0});
        persist();render();inspect(activeRecord);
    });
    element('cwt-entry-close').addEventListener('click',closeEntry);
    entryDialog.addEventListener('cancel',event=>{event.preventDefault();closeEntry();});
    element('cwt-entry-discard').addEventListener('click',()=>deleteDraft(activeRecord));
    element('cwt-entry-save').addEventListener('click',()=>saveDraft(activeRecord));
    element('cwt-entry-form').addEventListener('submit',event=>{event.preventDefault();if(activeRecord?.status==='draft'&&post([activeRecord]))inspect(activeRecord);});
    element('cwt-entry-void').addEventListener('click',()=>{element('cwt-void-confirm').hidden=false;element('cwt-confirm-actions').hidden=false;element('cwt-posted-actions').hidden=true;element('cwt-entry-void-reason').focus();});
    element('cwt-entry-cancel-void').addEventListener('click',()=>inspect(activeRecord));
    element('cwt-entry-confirm-void').addEventListener('click',()=>{
        const reason=element('cwt-entry-void-reason');reason.setCustomValidity(reason.value.trim()?'':'Enter a reason for voiding.');if(!reason.reportValidity())return;
        Object.assign(activeRecord,{status:'void',voidReason:reason.value.trim(),voidedAt:new Date().toISOString()});persist();render();inspect(activeRecord);announce(`${activeRecord.number} voided.`);
    });
    element('cwt-entry-restore').addEventListener('click',()=>{if(post([activeRecord])){activeRecord.restoredAt=new Date().toISOString();persist();inspect(activeRecord);announce(`${activeRecord.number} restored.`);}});
    element('cwt-entry-memo').addEventListener('click',()=>{
        if(memoEditing){activeRecord.notes=entryFields.notes.value.trim();persist();memoEditing=false;entryFields.notes.readOnly=true;element('cwt-entry-memo').lastChild.textContent='Edit Memo';announce('Audit memo saved.');}
        else{memoEditing=true;entryFields.notes.readOnly=false;element('cwt-entry-memo').lastChild.textContent='Save Memo';entryFields.notes.focus();}
    });
    element('cwt-entry-purge').addEventListener('click',()=>{
        const record=activeRecord;
        info(`Purge ${record.number}?`,'Remove this voided certificate from the local preview? An Undo action will be available.',[['Purge certificate',()=>{
            records=records.filter(item=>item.id!==record.id);selected.delete(record.id);persist();render();entryDialog.close();
            info('Certificate removed',`${record.number} removed from this tab.`,[['Undo purge',()=>{records.push(record);persist();render();dialog.close();inspect(record);}]]) ;
        }]]);
    });
    function render(){
        rows.replaceChildren();
        const sorted=visible().sort((a,b)=>grouped?a.atc.localeCompare(b.atc):(descending?-1:1)*a.date.localeCompare(b.date));
        sorted.forEach(record=>{
            const row=document.createElement('tr');row.id=`cwt-row-${record.id}`;row.dataset.status=record.status;
            const cells=Array.from({length:16},()=>{const cell=document.createElement('td');row.append(cell);return cell;});
            const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=selected.has(record.id);checkbox.setAttribute('aria-label',`Select ${record.number}`);checkbox.addEventListener('change',()=>{checkbox.checked?selected.add(record.id):selected.delete(record.id);refresh();});cells[0].append(checkbox);
            const badge=document.createElement('span');badge.className=record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge';badge.textContent=record.status==='draft'?'Drafting':record.status==='posted'?'Posted':'Voided';window.CompanyLedger?.decorateBadge?.(badge,'cwtCertificates',record);cells[1].append(badge);
            const transaction=button(record.number,()=>inspect(record));transaction.setAttribute('aria-label',`View ${record.number}`);transaction.className='cwt-transaction-link';cells[3].append(transaction);
            if(record.status==='draft'){
                cells[2].append(control(record,'date','date'));
                const customer=control(record,'customer');customer.classList.add('cwt-customer-field');customer.readOnly=true;customer.placeholder='Choose an invoice';customer.setAttribute('aria-label',`${record.number} Withholding agent or customer`);
                const tin=control(record,'tin');tin.classList.add('cwt-tin-field');tin.placeholder='Agent TIN';tin.setAttribute('aria-label',`${record.number} Agent TIN`);cells[4].append(customer,tin);
                const invoice=document.createElement('select');invoice.setAttribute('aria-label',`${record.number} Source invoice`);invoice.add(new Option('Select posted sales invoice',''));
                sources().forEach(sale=>invoice.add(new Option(`${sale.number} — ${sale.customer}`,String(sale.id))));invoice.value=String(record.saleId || '');
                invoice.addEventListener('change',()=>{const sale=ledger.cwtSource(invoice.value);Object.assign(record,{draftTouched:true,draftSaved:false,saleId:sale?.id || null,invoice:sale?.number || '',date:sale?.date || record.date,customer:sale?.customer || '',tin:sale?.tin || '',taxBase:sale?.taxBase || 0,atc:sale?.cwt || '',amount:sale?.withheld || 0});persist();render();});cells[5].append(invoice);
                cells[13].append(control(record,'certificate'));
                window.SheetPosting.draftActions(cells[15],record,{save:()=>saveDraft(record),post:()=>post([record]),delete:()=>deleteDraft(record),more:event=>entryOptions(record,event)});
            }else{
                cells[2].textContent=record.date;cells[4].textContent=record.customer;const tin=document.createElement('small');tin.textContent=`TIN: ${record.tin}`;cells[4].append(tin);cells[5].textContent=record.invoice;cells[13].textContent=record.certificate;cells[15].append(rowAction('View','visibility',record,()=>inspect(record)));if(record.status==='posted')cells[15].append(rowAction('More options','more_vert',record,event=>entryOptions(record,event)));
            }
            cells[6].textContent=money(record.taxBase);cells[7].textContent=record.atc || 'From source sale';cells[8].textContent=money(record.amount);cells[9].textContent='Separate 2306';cells[10].textContent='—';cells[11].textContent=money(record.amount);cells[12].textContent=record.offset;cells[14].textContent=record.status==='void'?'Excluded':match(record)?'Source matched':'Needs review';
            row.addEventListener('click',event=>{if(event.target.matches('input[readonly]')||!event.target.closest('button,input,select,textarea,a,label'))inspect(record);});rows.append(row);
        });
        if(!sorted.length){const row=document.createElement('tr');const cell=document.createElement('td');cell.colSpan=16;cell.textContent='No certificates in this view. Prepare a draft from a posted sale or add a certificate.';row.append(cell);rows.append(row);}
        refresh();
    }
    function refresh(){
        const shown=visible();const posted=shown.filter(record=>record.status==='posted');const periodSales=sources().filter(sale=>window.WorkingPeriod.matches(sale.date));
        const total=posted.reduce((sum,record)=>sum+record.amount,0);
        window.WorkingPeriod.render();
        element('cwt-base-total').textContent=money(posted.reduce((sum,record)=>sum+record.taxBase,0));element('cwt-it-total').textContent=money(total);element('cwt-footer-total').textContent=money(total);
        element('cwt-expected').textContent=money(periodSales.reduce((sum,sale)=>sum+sale.withheld,0));element('cwt-atc-count').textContent=`${new Set(posted.map(record=>record.atc)).size} ATC code(s)`;
        element('cwt-reconciled').textContent=`${periodSales.filter(sale=>records.some(record=>record.status==='posted'&&record.saleId===sale.id&&match(record))).length} of ${periodSales.length}`;
        element('cwt-count').textContent=`${shown.length} certificate(s) · ${shown.filter(record=>record.status==='draft').length} drafting · ${posted.length} posted · ${selected.size} selected`;
        element('cwt-filter-label').textContent=status==='all'?'All statuses':status==='draft'?'Drafts':status==='posted'?'Posted':'Voided';element('cwt-filter').setAttribute('aria-pressed',String(status!=='all'));
        element('cwt-post-count').textContent=`(${window.SheetPosting.candidates(shown,selected).length})`;
        const pending=element('cwt-pending-list');pending.replaceChildren();
        periodSales.filter(sale=>!records.some(record=>record.saleId===sale.id&&record.status==='posted')).forEach(sale=>{
            const card=document.createElement('div');card.className='cwt-source';const details=document.createElement('div');details.textContent=`${sale.number} · ${money(sale.withheld)}`;const customer=document.createElement('small');customer.textContent=sale.customer;details.append(customer);const prepare=button(records.some(record=>record.saleId===sale.id&&record.status==='draft')?'Continue draft':'Prepare',()=>add(sale.id));prepare.classList.add('cwt-primary','cwt-source-action');card.append(details,prepare);pending.append(card);
        });if(!pending.children.length)pending.textContent='No outstanding certificates for this period.';
        const visibleIds=new Set(shown.map(record=>record.id));for(const id of selected)if(!visibleIds.has(id))selected.delete(id);
        element('cwt-select-all').checked=shown.length>0&&shown.every(record=>selected.has(record.id));element('cwt-select-all').indeterminate=shown.some(record=>selected.has(record.id))&&!element('cwt-select-all').checked;
    }
    element('cwt-add').addEventListener('click',()=>add());document.querySelector('[data-cwt-add]').addEventListener('click',()=>add());
    document.querySelectorAll('[data-cwt-add-row]').forEach(button=>button.addEventListener('click',()=>add()));
    element('cwt-post').addEventListener('click',()=>post(window.SheetPosting.candidates(visible(),selected)));
    element('cwt-filter').addEventListener('click',()=>{const statuses=['all','draft','posted','void'];status=statuses[(statuses.indexOf(status)+1)%4];render();});
    element('cwt-sort').addEventListener('click',()=>{descending=!descending;element('cwt-sort').setAttribute('aria-pressed',String(descending));render();});
    element('cwt-group').addEventListener('click',()=>{grouped=!grouped;element('cwt-group').setAttribute('aria-pressed',String(grouped));render();});
    [['cwt-columns','cwt-hide-statutory'],['cwt-freeze','cwt-freeze'],['cwt-wrap','cwt-wrap']].forEach(([id,className])=>element(id).addEventListener('click',()=>element(id).setAttribute('aria-pressed',String(table.classList.toggle(className)))));
    element('cwt-stats').addEventListener('click',()=>{element('cwt-metrics').hidden=!element('cwt-metrics').hidden;element('cwt-stats').setAttribute('aria-pressed',String(!element('cwt-metrics').hidden));});
    ['cwt-search','sales-global-search'].forEach(id=>element(id).addEventListener('input',render));
    element('cwt-select-all').addEventListener('change',()=>{visible().forEach(record=>element('cwt-select-all').checked?selected.add(record.id):selected.delete(record.id));render();});
    [['cwt-prev',-1],['cwt-next',1]].forEach(([id,step])=>element(id).addEventListener('click',()=>guard.confirm(()=>{records=records.filter(record=>!incomplete(record));persist();const date=new Date(`${period}-01T12:00:00`);date.setMonth(date.getMonth()+step);period=`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}`;render();})));
    element('cwt-dialog-close').addEventListener('click',()=>dialog.close());
    document.querySelectorAll('[data-cwt-info],[data-sales-info]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();const title=link.dataset.cwtInfo || link.textContent.trim();const messages={OCR:'AI OCR is not connected yet. Enter details from the received certificate using Add certificate.',Import:'Certificate import is not connected yet. Use Add certificate to record a received certificate.',Shortcuts:'Shift+N: add certificate. Ctrl/⌘+K: search. Tab: next field.','About':'Record received CWT certificates against posted sales. Amounts copy the withholding already recorded on the source sale. Certificate posting does not change cash collections or invoice balances. CSV exports are working copies; official BIR exports are not connected.'};info(title,messages[title] || 'This workspace module is not connected yet.');}));
    element('cwt-export').addEventListener('click',()=>{
        const csvCell=value=>`"${String(value ?? '').replace(/^[=+@-]/,"'$&").replace(/"/g,'""')}"`;
        const data=[['Status','Date','Transaction','Agent','TIN','Invoice','Tax Base','ATC','CWT IT','Certificate'],...visible().map(record=>[record.status,record.date,record.number,record.customer,record.tin,record.invoice,(record.taxBase/100).toFixed(2),record.atc,(record.amount/100).toFixed(2),record.certificate])];
        const url=URL.createObjectURL(new Blob(['\uFEFF'+data.map(row=>row.map(csvCell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download=`cwt-${period}.csv`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    const menu=element('sales-menu-toggle');const backdrop=element('sales-backdrop');
    function closeMenu(){document.body.classList.remove('sales-menu-open');menu.setAttribute('aria-expanded','false');backdrop.hidden=true;}
    menu.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');menu.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;});backdrop.addEventListener('click',closeMenu);window.matchMedia('(min-width:1024px)').addEventListener('change',closeMenu);
    document.addEventListener('keydown',event=>{if(dialog.open||entryDialog.open||document.querySelector('.draft-leave-dialog[open]'))return;if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();element('cwt-search').focus();}if(!event.target.matches('input,select,textarea')&&event.shiftKey&&event.key.toLowerCase()==='n'){event.preventDefault();add();}});
    const requestedEntry=new URLSearchParams(location.search).get('entry');
    const linkedEntry=records.find(record=>record.number===requestedEntry&&record.status==='posted');
    if(linkedEntry){window.WorkingPeriod.revealDate(linkedEntry.date);period=window.WorkingPeriod.current().from.slice(0,7);render();inspect(linkedEntry);}else {render();const source=new URLSearchParams(location.search).get('source');const sale=ledger.invoices().find(sale=>sale.number===source);if(sale){window.WorkingPeriod.revealDate(sale.date);period=window.WorkingPeriod.current().from.slice(0,7);add(sale.id);}}
    function entryOptions(record,event){event.stopPropagation();window.SheetEntryActions.open(event.currentTarget,record,{delete:()=>deleteDraft(record),editLabel:record.status==='draft'?'Edit entry':'Edit memo',edit:()=>{inspect(record);if(record.status!=='draft')element('cwt-entry-memo').click();},duplicate:()=>{if(records.some(record=>record.status==='draft')){add();return;}add();const draft=records.at(-1);Object.assign(draft,{date:record.date,customer:record.customer,tin:record.tin,notes:record.notes || ''});persist();render();inspect(draft);},void:()=>{inspect(record);element('cwt-entry-void').click();},view:()=>inspect(record)});}
    window.SheetBulk={selected:()=>visible().filter(record=>selected.has(record.id)),refresh:()=>{records=ledger.cwtCertificates();selected.clear();render();},announce};
document.addEventListener('working-period:change',event=>{period=event.detail.period;render();});
})();
