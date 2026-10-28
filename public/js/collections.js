(() => {
    const table = document.getElementById('collections-table');
    const tbody = document.getElementById('collection-rows');
    const originalRows = [...tbody.rows];
    const postedTemplate = originalRows[0].cloneNode(true);
    const draftTemplate = originalRows[2].cloneNode(true);
    const addRow = originalRows[3];
    const dialog = document.getElementById('collections-info');
    const search = document.getElementById('collection-search');
    const globalSearch = document.getElementById('sales-global-search');
    const statusFilter = document.getElementById('collection-status-filter');
    const methodFilter = document.getElementById('collection-method-filter');
    const selectAll = table.querySelector('thead input');
    const records = new Map();
    let period = window.WorkingPeriod.current().from.slice(0,7);
    const ledger = window.KontaduryaLedger;
    let ascending = true;
    let grouped = false;
    const money = value => new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format((value || 0)/100);
    const announce = text => {document.getElementById('collection-status').textContent=text;};
    const parseMoney = value => {
        const clean=String(value).replace(/[,₱\s]/g,'');
        if(!/^\d{1,10}(\.\d{1,2})?$/.test(clean))return null;
        const [whole,fraction='']=clean.split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));
    };
    const fieldKeys=['date','customer','invoice','amount','bank','reference','method','type'];
    const labels=['Date','Customer','Source invoice','Amount','Cash or bank account','OR or reference','Payment method','Entry type'];
    tbody.replaceChildren(addRow);
    function fields(row) {return [...row.querySelectorAll('input:not([type="checkbox"]):not(.sheet-master-search),select')];}
    function render(record) {
        const previous=record.row;
        const row=(record.status==='draft'?draftTemplate:postedTemplate).cloneNode(true);
        row.dataset.collectionId=record.id;
        row.querySelector('input[type="checkbox"]').checked=record.selected || false;
        row.querySelector('input[type="checkbox"]').setAttribute('aria-label',`Select ${record.number}`);
        const cells=row.cells;
        cells[1].replaceChildren(Object.assign(document.createElement('span'),{className:record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge',textContent:record.status==='draft'?'Drafting':record.status==='posted'?'Posted':'Voided'}));
    window.CompanyLedger?.decorateBadge?.(cells[1].firstElementChild,'collections',record);
        cells[3].textContent=record.number;
        row.classList.toggle('collection-void',record.status==='void');
        if(record.status==='draft') {
            let inputs=fields(row);
            const customer=document.createElement('input');customer.className=inputs[1].className;customer.type='text';customer.placeholder='Customer name';customer.maxLength=255;
            inputs[1].replaceWith(customer);inputs=fields(row);
            inputs.forEach((field,index)=>{
                const key=fieldKeys[index];
                if(key==='invoice') {
                    field.replaceChildren(new Option('Unlinked / Advance Deposit','Unlinked / Advance Deposit'));
                    ledger.invoices().filter(invoice=>invoice.balance>0||invoice.number===record.invoice).forEach(invoice=>field.add(new Option(`${invoice.number} — ${money(invoice.balance)} unpaid`,invoice.number)));
                }
                let value=key==='amount'?(record.amount ? (record.amount/100).toFixed(2) : ''):record[key];
                if(field.tagName==='SELECT' && ![...field.options].some(option=>option.value===value))field.add(new Option(value,value));
                field.value=value;
                field.setAttribute('aria-label',`${record.number} ${labels[index]}`);
                if(['date','customer','amount'].includes(key))field.required=true;
                if(key==='amount')field.inputMode='decimal';
                field.addEventListener('input',()=>{
                    record.draftTouched=true;record.draftSaved=false;field.setCustomValidity('');record[key]=key==='amount'?parseMoney(field.value):field.value.trim();update(false);
                });
                field.addEventListener('change',()=>{
                    record.draftTouched=true;record.draftSaved=false;record[key]=key==='amount'?parseMoney(field.value):field.value.trim();
                    if(key==='invoice') {
                        const invoice=ledger.invoices().find(sale=>sale.number===record.invoice);
                        if(invoice){record.customer=invoice.customer;inputs[1].value=invoice.customer;record.type='Settlement';inputs[7].value='Settlement';}
                    }
                    update(false);
                });
                field.addEventListener('keydown',event=>{
                    if(event.key==='Enter'){event.preventDefault();if(index<inputs.length-1)inputs[index+1].focus();else addCollection();}
                });
            });
        } else {
            [record.date,record.customer,record.invoice || 'Unlinked / Advance Deposit',money(record.amount),record.bank,record.reference || '—',record.method,record.type].forEach((value,index)=>{cells[[2,4,5,6,7,8,9,10][index]].textContent=value;});
            const number=document.createElement('button');number.type='button';number.dataset.collectionAction='edit';number.textContent=record.number;number.setAttribute('aria-label',`View ${record.number}`);cells[3].replaceChildren(number);
        }
        cells[11].textContent=record.status==='void'?'Excluded':record.origin==='cash-sale'?'From cash sale':record.status==='draft'?'Demo draft':'Local demo';
        row.querySelectorAll('[data-collection-action]').forEach(button=>{
            const action=button.dataset.collectionAction;
            button.setAttribute('aria-label',`${action==='post-row'?'Post':action==='discard'?'Discard':action==='duplicate'?'Duplicate':action==='more'?'Options for':'View'} ${record.number}`);
        });
        if(record.status==='draft')window.SheetPosting.draftActions(cells[12],record,{
            save:()=>action('save-draft',record),post:()=>post([record]),delete:()=>action('discard',record),more:event=>action('more',record,false,event.currentTarget)
        });
        if(previous)previous.replaceWith(row);else tbody.insertBefore(row,addRow);
        record.row=row;records.set(record.id,record);
    }
    function visibleRecords(){return [...records.values()].filter(record=>!record.row.hidden);}
    function selectedRecords(){return visibleRecords().filter(record=>record.row.querySelector('input[type="checkbox"]').checked);}
    function update(reorder=true,persist=true) {
        const query=search.value.trim().toLowerCase();
        const ordered=[...records.values()].sort((a,b)=>grouped?a.customer.localeCompare(b.customer):(ascending?1:-1)*a.date.localeCompare(b.date));
        ordered.forEach(record=>{
            record.row.hidden=!(record.status!=='archived' && window.WorkingPeriod.matches(record.date) && (statusFilter.value==='all'||record.status===statusFilter.value) && (methodFilter.value==='all'||record.method===methodFilter.value) && `${record.number} ${record.customer} ${record.invoice} ${record.reference} ${money(record.amount)}`.toLowerCase().includes(query));
            if(record.row.hidden)record.row.querySelector('input[type="checkbox"]').checked=false;
            if(reorder)tbody.insertBefore(record.row,addRow);
        });
        const visible=visibleRecords();const active=visible.filter(record=>record.status!=='void');
        const total=active.reduce((sum,record)=>sum+(record.amount || 0),0);
        const advance=active.filter(record=>record.type==='Advance'||record.invoice==='Unlinked / Advance Deposit').reduce((sum,record)=>sum+(record.amount || 0),0);
        document.getElementById('collection-total').textContent=money(total);
        document.getElementById('collection-applied').textContent=money(total-advance);
        document.getElementById('collection-advance').textContent=money(advance);
        const selected=selectedRecords();
        document.getElementById('collection-counts').textContent=`${visible.length} rows · ${active.filter(record=>record.status==='posted').length} posted (demo) · ${active.filter(record=>record.status==='draft').length} drafts · ${selected.length} selected`;
        ['all','draft','posted'].forEach(status=>{
            const button=document.querySelector(`[data-collection-action="${status}"]`);
            button.textContent=`${status==='all'?'All':status==='draft'?'Drafts':'Posted'} (${[...records.values()].filter(record=>record.status!=='archived'&&window.WorkingPeriod.matches(record.date)&&(status==='all'||record.status===status)).length})`;
            button.setAttribute('aria-pressed',String(statusFilter.value===status));
        });
        document.querySelector('[data-collection-action="post"]').textContent=`Post (${window.SheetPosting.candidates(visible,selected.map(record=>record.id)).length})`;
        document.querySelector('[data-collection-tab-count]').textContent=active.length;
        selectAll.checked=visible.length>0&&selected.length===visible.length;
        selectAll.indeterminate=selected.length>0&&selected.length<visible.length;
        document.getElementById('collection-empty').hidden=visible.length>0;
        try {if(persist)ledger.saveCollections([...records.values()].map(({row,selected,...record})=>record));for(const saved of ledger.collections()){const record=records.get(saved.id);if(record&&record.number!==saved.number){record.number=saved.number;render(record);}}}
        catch(error){announce(error.message);}
        renderUnpaidInvoices();
    }
    function updatePeriod(){
        window.WorkingPeriod.render();
        update();
    }
    function renderUnpaidInvoices() {
        const list=document.getElementById('unpaid-invoice-list');list.replaceChildren();
        const invoices=ledger.invoices().filter(invoice=>invoice.balance>0);
        document.getElementById('unpaid-invoice-count').textContent=`${invoices.length} unpaid`;
        if(!invoices.length){const empty=document.createElement('p');empty.className='text-body-sm text-on-surface-variant';empty.textContent='No unpaid sales invoices.';list.append(empty);}
        invoices.forEach(invoice=>{
            const row=document.createElement('div');row.className='unpaid-invoice';
            const info=document.createElement('div');
            const number=document.createElement('strong');number.textContent=invoice.number;
            const customer=document.createElement('p');customer.textContent=`${invoice.customer} · ${invoice.terms}`;info.append(number,customer);
            const balance=document.createElement('span');balance.className='font-mono text-secondary';balance.textContent=money(invoice.balance);
            const collect=document.createElement('button');collect.type='button';collect.textContent='Collect';collect.setAttribute('aria-label',`Collect ${invoice.number}`);
            collect.addEventListener('click',()=>{
                const existing=[...records.values()].find(record=>record.status==='draft'&&record.invoice===invoice.number);
                if(existing){window.WorkingPeriod.revealDate(existing.date);period=window.WorkingPeriod.current().from.slice(0,7);statusFilter.value='all';methodFilter.value='all';search.value='';globalSearch.value='';updatePeriod();fields(existing.row)[3].focus();announce(`Continue the existing draft ${existing.number}.`);}
                else addCollection({date:window.WorkingPeriod.entryDate(),customer:invoice.customer,invoice:invoice.number,amount:invoice.balance,bank:invoice.bank,method:'Cash',type:'Settlement'});
            });
            row.append(info,balance,collect);list.append(row);
        });
    }
    function addCollection(copy=null){
        const pending=window.DraftLeaveGuard.continueDraft(records.values(),record=>{window.WorkingPeriod.revealDate(record.date);period=window.WorkingPeriod.current().from.slice(0,7);statusFilter.value='all';methodFilter.value='all';search.value='';globalSearch.value='';updatePeriod();record.row.scrollIntoView({block:'nearest'});fields(record.row)[1].focus();announce('Continue '+record.number+'. Only one draft is allowed on this page.');});if(pending)return pending;
        const record={...ledger.newCollection(),date:window.WorkingPeriod.entryDate(),customer:'',invoice:'Unlinked / Advance Deposit',amount:0,bank:(window.WorkspaceMasters?.preferences?.defaultBank || '1010 · Cash on Hand'),reference:'',method:'Cash',type:'Advance',status:'draft',...(copy?{date:copy.date,customer:copy.customer,invoice:copy.invoice,amount:copy.amount,bank:copy.bank,method:copy.method,type:copy.type}:{} )};
        window.WorkingPeriod.revealDate(record.date);period=window.WorkingPeriod.current().from.slice(0,7);statusFilter.value='all';methodFilter.value='all';search.value='';globalSearch.value='';render(record);updatePeriod();
        record.row.scrollIntoView({block:'nearest'});fields(record.row)[1].focus();announce(`${record.number} added as an editable demo draft.`);
    }
    function validate(record,reservations=new Map()){
        if(record.status!=='draft')return false;
        const inputs=fields(record.row);
        inputs[3].setCustomValidity(record.amount!==null&&record.amount>0?'':'Enter a positive amount with at most two decimal places.');
        inputs[1].setCustomValidity(record.customer?'':'Enter a customer name.');
        inputs[2].setCustomValidity(record.type!=='Advance'&&record.invoice==='Unlinked / Advance Deposit'?'Choose a source invoice for this entry type.':'');
        if(record.invoice!=='Unlinked / Advance Deposit') {
            const invoice=ledger.invoices().find(sale=>sale.number===record.invoice);
            const available=(invoice?.balance || 0)-(reservations.get(record.invoice) || 0);
            if(!invoice)inputs[2].setCustomValidity('This invoice is no longer posted. Choose another invoice.');
            else if(record.type==='Advance')inputs[2].setCustomValidity('Use Unlinked / Advance Deposit for an advance.');
            else if(invoice.customer!==record.customer)inputs[1].setCustomValidity('The customer must match the selected sales invoice.');
            else if(record.amount>available)inputs[3].setCustomValidity(`The unpaid amount available is ${money(available)}.`);
            reservations.set(record.invoice,(reservations.get(record.invoice) || 0)+(record.amount || 0));
        }
        return inputs.every(field=>field.reportValidity());
    }
    function post(recordsToPost){
        const drafts=recordsToPost.filter(record=>record.status==='draft');
        if(!drafts.length){announce('Complete a draft collection or select draft rows to post.');return false;}
        const reservations=new Map();
        if(!drafts.every(record=>validate(record,reservations))){announce('Complete the required collection fields before posting.');return false;}
        const ids=new Set(drafts.map(record=>record.id)),postedAt=new Date().toISOString();
        const next=[...records.values()].map(({row,selected,...record})=>ids.has(record.id)?{...record,status:'posted',postedAt}:record);
        try{ledger.saveCollections(next);}catch(error){announce(error.message);return false;}
        drafts.forEach(record=>{Object.assign(record,{status:'posted',postedAt,selected:false});render(record);});update(true,false);announce(`${drafts.length} collection(s) posted. The linked unpaid invoice balances are updated.`);return true;
    }
    function showInfo(title,text,record=null){
        document.getElementById('collection-dialog-title').textContent=title;
        document.getElementById('collection-dialog-text').textContent=text;
        const actions=document.getElementById('collection-dialog-actions');actions.replaceChildren();
        if(record){
            const duplicate=document.createElement('button');duplicate.type='button';duplicate.textContent='Duplicate as draft';duplicate.addEventListener('click',()=>{dialog.close();addCollection(record);});actions.append(duplicate);
            const toggle=document.createElement('button');toggle.type='button';toggle.textContent=record.status==='void'?'Restore':'Void (demo)';
            toggle.addEventListener('click',()=>{
                if(record.origin==='cash-sale'){announce('This collection follows its cash sale. Void or restore the sale from the Sales page.');dialog.close();}
                else if(record.status==='void'){if([...records.values()].some(item=>item.id!==record.id&&item.status==='draft')){announce('Post or delete the existing draft before restoring another draft.');return;}record.status='draft';render(record);update();dialog.close();announce(`${record.number} restored as a draft. Review and post it to apply the collection again.`);}
                else if(toggle.dataset.confirm==='true'){record.previousStatus=record.status;record.status='void';render(record);update();dialog.close();announce(`${record.number} voided in this tab.`);}
                else {toggle.dataset.confirm='true';toggle.textContent='Confirm void';}
            });actions.append(toggle);
        }
        if(!dialog.open)dialog.showModal();
    }
    function exportRows(){
        const quote=value=>'"'+(/^[=+@\-\t\r]/.test(String(value))?"'":'')+String(value).replaceAll('"','""')+'"';
        const lines=[['Status','Date','Transaction','Customer','Invoice','Amount','Bank','Reference','Method','Type'],...visibleRecords().map(record=>[record.status,record.date,record.number,record.customer,record.invoice,((record.amount || 0)/100).toFixed(2),record.bank,record.reference,record.method,record.type])];
        const url=URL.createObjectURL(new Blob(['\uFEFF'+lines.map(line=>line.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const anchor=document.createElement('a');anchor.href=url;anchor.download=`demo-collections-${period}.csv`;anchor.click();setTimeout(()=>URL.revokeObjectURL(url),1000);announce('Visible collection rows exported to CSV.');
    }
    function action(name,record,confirmed=false,trigger=null){
        if(!confirmed&&['previous','next'].includes(name)&&hasIncompleteCollections()){
            leaveGuard.confirm(()=>{discardIncompleteCollections();action(name,record,true);},true);return;
        }
        if(name==='add')addCollection();
        else if(name==='duplicate'&&record)addCollection(record);
        else if(name==='more'&&record){window.SheetEntryActions.open(trigger || record.row.querySelector('[data-collection-action="more"]'),record,{delete:()=>action('discard',record),edit:()=>{entryPopup.open(record);document.getElementById('edit-collection-entry').click();},duplicate:()=>addCollection(record),print:()=>{entryPopup.open(record);document.getElementById('print-collection-entry').click();},void:()=>{entryPopup.open(record);document.getElementById('void-collection-entry').click();},view:()=>entryPopup.open(record)});}
        else if(name==='edit'&&record){
            entryPopup.open(record);
        }
        else if(name==='post-row'&&record)post([record]);
        else if(name==='post')post(window.SheetPosting.candidates(visibleRecords(),selectedRecords().map(record=>record.id)));
        else if(name==='discard'&&record){window.DraftLeaveGuard.confirmDelete(()=>{records.delete(record.id);record.row.remove();update();announce(record.number+' draft deleted.');},record.number);}
        else if(name==='save-draft'&&record){record.draftSaved=true;update();announce(record.number+' draft saved in this tab. Post it when ready.');}
        else if(['all','draft','posted'].includes(name)){statusFilter.value=name;update();}
        else if(name==='filter'){const panel=document.getElementById('collection-filter');panel.hidden=!panel.hidden;}
        else if(name==='previous'||name==='next'){const [year,month]=period.split('-').map(Number);const date=new Date(year,month-1+(name==='next'?1:-1),1);period=`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}`;updatePeriod();}
        else if(name==='sort'){ascending=!ascending;grouped=false;update();announce(`Date order: ${ascending?'oldest':'newest'} first.`);}
        else if(name==='group'){grouped=!grouped;update();announce(grouped?'Rows ordered by customer.':'Customer grouping cleared.');}
        else if(name==='columns')table.classList.toggle('collection-hide-extra');
        else if(name==='freeze')table.classList.toggle('collection-no-freeze');
        else if(name==='wrap')table.classList.toggle('collection-wrap');
        else if(name==='summary'){const summary=document.getElementById('collection-summary');summary.hidden=!summary.hidden;}
        else if(name==='export')exportRows();
        else if(name==='shortcuts')showInfo('Collection shortcuts','Shift+N: add collection. Tab: next field. Enter: next field or new row. Ctrl/⌘+K: search. Ctrl/⌘+P: simulate posting selected drafts. Delete: open void confirmation for one selected record.');
        else if(name==='about')showInfo('About Collections','Cash sales automatically create posted collections. On-account sales stay unpaid until a collection is posted. Records are shared with Sales in this browser tab and survive reloads. No real bank payment or BIR submission is made.');
        else if(name==='bulk')showInfo('Bulk actions','Select draft rows and use Post to simulate posting. Select one record and press Delete to review void options.');
        else showInfo(name==='notifications'?'Notifications':'Import collections',name==='notifications'?'No collection notifications.':'Import is not connected yet. No file has been uploaded.');
    }
    document.addEventListener('click',event=>{const button=event.target.closest('[data-collection-action]');if(!button)return;const row=button.closest('[data-collection-id]');action(button.dataset.collectionAction,row?records.get(Number(row.dataset.collectionId)):null,false,button);});
    selectAll.addEventListener('change',()=>{visibleRecords().forEach(record=>record.row.querySelector('input[type="checkbox"]').checked=selectAll.checked);update(false);});
    tbody.addEventListener('change',event=>{if(event.target.matches('input[type="checkbox"]'))update(false);});
    tbody.addEventListener('click',event=>{
        if(event.target.closest('button,input,select,textarea,a,label'))return;
        const row=event.target.closest('[data-collection-id]');
        if(row)entryPopup.open(records.get(Number(row.dataset.collectionId)));
    });
    search.addEventListener('input',()=>{globalSearch.value=search.value;update(false);});
    globalSearch.addEventListener('input',()=>{search.value=globalSearch.value;update(false);});
    statusFilter.addEventListener('change',()=>update());methodFilter.addEventListener('change',()=>update());
    document.querySelectorAll('[data-collection-info],[data-sales-info]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();showInfo(link.textContent.trim(),'This workspace module is not connected yet. Sales and Collections are available from the sheet tabs.');}));
    const menu=document.getElementById('sales-menu-toggle');const backdrop=document.getElementById('sales-backdrop');
    function closeMenu(){document.body.classList.remove('sales-menu-open');menu.setAttribute('aria-expanded','false');backdrop.hidden=true;}
    menu.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');menu.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;});backdrop.addEventListener('click',closeMenu);window.matchMedia('(min-width:1024px)').addEventListener('change',closeMenu);
    document.addEventListener('keydown',event=>{
        if(dialog.open||entryPopup.isOpen()||document.querySelector('.draft-leave-dialog[open]'))return;
        const editing=event.target.matches('input,select,textarea');
        if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();search.focus();}
        if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='p'){event.preventDefault();post(window.SheetPosting.candidates(visibleRecords(),selectedRecords().map(record=>record.id)));}
        if(!editing&&event.shiftKey&&event.key.toLowerCase()==='n'){event.preventDefault();addCollection();}
        if(!editing&&event.key==='Enter'&&!event.target.closest('button,a,summary')){event.preventDefault();addCollection();}
        if(!editing&&event.key==='Delete'){const selected=selectedRecords();if(selected.length===1){event.preventDefault();action('more',selected[0]);}else announce('Select one collection to review void options.');}
        if(!editing&&event.key==='?')action('shortcuts');if(event.key==='Escape')closeMenu();
    });
    const empty=document.createElement('tr');empty.id='collection-empty';const cell=document.createElement('td');cell.colSpan=13;cell.className='p-6 text-body-md text-on-surface-variant';cell.textContent='No collections match this period and filter.';empty.append(cell);tbody.insertBefore(empty,addRow);
    ledger.collections().forEach(record=>render(record));
    updatePeriod();
    function incompleteCollection(record) {
        return window.DraftLeaveGuard.hasDraftDetails(record);
    }
    function hasIncompleteCollections(){return [...records.values()].some(incompleteCollection);}
    function discardIncompleteCollections(){
        [...records.values()].filter(incompleteCollection).forEach(record=>{records.delete(record.id);record.row.remove();});
        update();
    }
    const leaveGuard=new window.DraftLeaveGuard({hasChanges:()=>hasIncompleteCollections()||entryPopup.hasChanges(),discard:()=>{discardIncompleteCollections();entryPopup.discardClose();}});
    const entryPopup=new window.CollectionEntryDrawer({
        ledger,guard:leaveGuard,
        onSave:record=>{render(record);update();announce(`${record.number} ${record.status==='posted'?'posted':'saved'} in this local preview.`);},
        onDiscard:record=>{records.delete(record.id);record.row.remove();update();announce(`${record.number} unfinished draft discarded.`);},
        onVoid:(record,reason)=>{
            const company=JSON.parse(document.getElementById('collection-company-data').textContent);
            const saved=record.status==='void'?ledger.restoreCollection(record.id):ledger.voidCollection(record.id,reason,company.full_name);
            saved.row=record.row;render(saved);update();announce(`${saved.number} ${saved.status==='void'?'voided; its applied amount is available to collect again':saved.status==='draft'?'restored as a draft':'restored as posted'} in this local preview.`);return saved;
        },
        onMemo:(record,reason)=>{
            const company=JSON.parse(document.getElementById('collection-company-data').textContent);
            const saved=ledger.editCollectionVoidMemo(record.id,reason,company.full_name);saved.row=record.row;render(saved);update();return saved;
        },
        onPurge:record=>{
            const saved=ledger.archiveVoidedCollection(record.id);saved.row=record.row;render(saved);update();
            announce(`${saved.number} hidden from this sheet. Its audit history is retained. `);
            const undo=document.createElement('button');undo.type='button';undo.className='underline';undo.textContent='Undo purge';
            undo.addEventListener('click',()=>{const restored=ledger.unarchiveVoidedCollection(saved.id);restored.row=saved.row;render(restored);update();announce(`${restored.number} restored to the voided records.`);entryPopup.open(restored);});
            document.getElementById('collection-status').append(undo);
        }
    });
    const requestedEntry=new URLSearchParams(location.search).get('entry');
    const linkedEntry=[...records.values()].find(record=>record.number===requestedEntry&&record.status==='posted');
    if(linkedEntry){window.WorkingPeriod.revealDate(linkedEntry.date);period=window.WorkingPeriod.current().from.slice(0,7);updatePeriod();entryPopup.open(linkedEntry);}
    window.SheetBulk={selected:selectedRecords,refresh:()=>{const saved=ledger.collections();for(const record of [...records.values()])if(!saved.some(item=>item.id===record.id)){record.row.remove();records.delete(record.id);}for(const item of saved){item.row=records.get(item.id)?.row;render(item);}update();},announce};
document.addEventListener('working-period:change',event=>{period=event.detail.period;updatePeriod();});
})();
