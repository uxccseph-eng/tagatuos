(() => {
    const ledger=window.KontaduryaLedger;
    const element=id=>document.getElementById(id);
    const company=JSON.parse(element('purchase-company').textContent);
    const table=element('purchases-table'),rows=element('purchases-rows'),drawer=element('purchase-entry'),infoDialog=element('purchases-info');
    const fields=Object.fromEntries([...drawer.querySelectorAll('[data-purchase-field]')].map(field=>[field.dataset.purchaseField,field]));
    const clone=value=>JSON.parse(JSON.stringify(value));
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format((value || 0)/100);
    const cents=value=>{const text=String(value).trim();if(!/^\d{1,9}(\.\d{0,2})?$/.test(text))return 0;const [whole,fraction='']=text.split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));};
    const ewtLabel=(rate,code)=>code?code+' · '+Number(rate)+'%':Number(rate || 0)+'%';
    const fieldValue=(record,key)=>key==='wvat'&&!record.wvatCode&&(record.wvat===''||record.wvat==null)?0:record[key];
    let records=ledger.purchases(),period=window.WorkingPeriod.current().from.slice(0,7),status='all',descending=false,grouped=false,working=null,original=null,editing=false;
    const selected=new Set();
    const announce=message=>{element('purchases-status').textContent=message;if(drawer.open)element('purchase-entry-message').textContent=message;};
    const store=next=>{try{ledger.savePurchases(next);records=next;return true;}catch(error){announce(error.message || 'Could not save in this tab. Remove large receipt attachments and try again.');return false;}};
    const invalid=record=>!/^\d{4}-\d{2}-\d{2}$/.test(record.date)||!record.supplier?.trim()||!Number.isSafeInteger(record.gross)||record.gross<=0||record.gross>99999999999||!record.account||!(window.SheetMasters?.taxChoices(false).some(choice=>choice.value===record.tax)||['VAT (12%)','Non-VAT','Zero-Rated','Exempt'].includes(record.tax)||String(record.tax).startsWith('TAX|'))||!window.SheetMasters.allowedRate(record.ewt)||!window.SheetMasters.allowedRate(record.wvat,true);
    const incomplete=record=>window.DraftLeaveGuard.hasDraftDetails(record);
    const unsavedEdit=()=>editing&&original?.status==='posted'&&JSON.stringify(working)!==JSON.stringify(original);
    const guard=new window.DraftLeaveGuard({hasChanges:()=>records.some(incomplete)||unsavedEdit(),discard:()=>{store(records.filter(record=>!incomplete(record)));}});
    const current=()=>{const query=`${element('purchases-search').value} ${element('sales-global-search').value}`.trim().toLowerCase();return records.filter(record=>record.status!=='archived'&&window.WorkingPeriod.matches(record.date)&&(status==='all'||record.status===status)&&`${record.number} ${record.supplier} ${record.tin} ${record.reference} ${record.description} ${record.gross/100}`.toLowerCase().includes(query));};
    function actionButton(label,callback,icon){const button=document.createElement('button');button.type='button';button.title=label;button.setAttribute('aria-label',label);button.addEventListener('click',callback);if(icon){button.className='purchase-row-action';const span=document.createElement('span');span.className='material-symbols-outlined';span.textContent=icon;span.setAttribute('aria-hidden','true');button.append(span);}else button.textContent=label;return button;}
    function info(title,message,actions=[]){element('purchases-dialog-title').textContent=title;element('purchases-dialog-text').textContent=message;element('purchases-dialog-actions').replaceChildren(...actions.map(([label,callback])=>actionButton(label,callback)));if(!infoDialog.open)infoDialog.showModal();}
    function saveDraft(record){const saved={...clone(record),draftSaved:true};if(!store(records.map(item=>item.id===record.id?saved:item)))return;if(working?.id===record.id)working=clone(saved);render();announce(record.number+' draft saved in this tab. Post it when ready.');}
    function deleteDraft(record){window.DraftLeaveGuard.confirmDelete(()=>{if(!store(records.filter(item=>item.id!==record.id)))return false;if(drawer.open&&working?.id===record.id){drawer.close();editing=false;}render();announce('Draft deleted.');},record.number);}
    function history(record,label){record.history=[...(record.history || []),{label,user:company.full_name || 'Demo user',time:new Date().toISOString()}];}
    function add(template){const pending=window.DraftLeaveGuard.continueDraft(records,record=>{window.WorkingPeriod.revealDate(record.date);period=window.WorkingPeriod.current().from.slice(0,7);status='all';element('purchases-search').value='';element('sales-global-search').value='';render();element('purchase-row-'+record.id)?.scrollIntoView({block:'nearest'});announce('Continue '+record.number+'. Only one draft is allowed on this page.');});if(pending)return pending;
        const next=records.reduce((highest,record)=>Math.max(highest,Number(record.number.split('-')[1]) || 0),0)+1;
        const record={id:crypto.randomUUID(),number:`BILL-${String(next).padStart(6,'0')}`,date:window.WorkingPeriod.entryDate(),supplier:'',tin:'',gross:0,tax:'',account:'',ewt:'',wvat:0,wvatCode:'',terms:'Cash',reference:'',bank:'1010 · Cash on Hand',description:'',notes:'',attachments:[],status:'draft',createdAt:new Date().toISOString()};
        if(template)for(const key of ['supplier','tin','gross','tax','account','ewt','wvat','ewtCode','wvatCode','terms','bank','description','notes'])record[key]=template[key];
        history(record,'Created');if(!store([...records,record]))return;
        status='all';element('purchases-search').value='';element('sales-global-search').value='';render();announce(`${record.number} added. Fill its row or click an inactive cell to open the entry popup.`);element(`purchase-row-${record.id}`)?.scrollIntoView({block:'nearest'});return record;
    }
    function input(record,key){
        const field=fields[key].cloneNode(true);field.removeAttribute('data-purchase-field');field.removeAttribute('id');field.disabled=false;field.readOnly=false;field.setAttribute('aria-label',`${record.number} ${key}`);
        if(field.tagName==='SELECT'&&['account','bank'].includes(key))ledger.setAccountSelect(field,record[key] || '');else field.value=key==='gross'?(record.gross/100).toFixed(2):record[key] ?? '';
        if(field.tagName==='SELECT'&&key!=='billId')window.SheetMasters.selectValue(field,fieldValue(record,key),record[key+'Code']);
        field.addEventListener('input',()=>{if(['ewt','wvat'].includes(key))record[key+'Code']=field.selectedOptions[0]?.dataset.masterCode || '';record.draftTouched=true;record.draftSaved=false;const previousAccount=record.account;record[key]=key==='gross'?cents(field.value):['ewt','wvat'].includes(key)?(field.value===''?'':Number(field.value)):field.value;if(key==='account')window.SheetMasters.applyAccountWithholding(record,previousAccount);if(key==='supplier'){Object.assign(record,window.SheetMasters.partySnapshot(record));}store(records);refresh();});
        field.addEventListener('change',()=>{if(key==='supplier'){suggestPurchaseSettings(record,field.closest('tr'));store(records);}render();});return field;
    }
    function suggestPurchaseSettings(record,scope){
        const suggestion=window.SheetMasters.partyDefaults(record,false);
        Object.assign(record,suggestion.values);
        for(const key of ['account','tax','ewt','wvat'])if(Object.hasOwn(suggestion.values,key)){
            const target=scope?.querySelector('[data-purchase-field="'+key+'"]') || scope?.querySelector('[aria-label="'+record.number+' '+key+'"]');
            if(target){window.SheetMasters.selectValue(target,record[key],record[key+'Code']);target.dispatchEvent(new Event('masters:sync'));}
        }
        if(suggestion.sourceNumber)announce('Suggested account and tax settings from '+suggestion.sourceNumber+'. Review before posting.');
    }
    function post(candidates){
        if(!candidates.length){announce('Select one or more draft bills to post.');return false;}
        for(const record of candidates){const error=window.SheetMasters.withholdingError({...record,ewtCode:ledger.wtpAtc(record),wvatCode:ledger.wtpVatAtc(record)});if(error){announce(record.number+': '+error);return false;}}
        if(candidates.some(invalid)){announce('Complete the date, supplier, amount, expense account and tax type before posting.');return false;}
        const next=clone(records);for(const candidate of candidates){const target=next.find(record=>record.id===candidate.id);if(!target||target.status!=='draft')return false;Object.assign(target,candidate,{ewtCode:ledger.wtpAtc(candidate),wvatCode:ledger.wtpVatAtc(candidate),status:'posted',postedAt:new Date().toISOString()});history(target,'Posted');}
        if(!store(next))return false;selected.clear();render();announce(`${candidates.length} bill(s) posted in this local preview.`);return true;
    }
    function render(){
        rows.replaceChildren();const shown=current().sort((a,b)=>grouped?a.supplier.localeCompare(b.supplier):(descending?-1:1)*a.date.localeCompare(b.date));
        for(const record of shown){
            const row=document.createElement('tr');row.dataset.status=record.status;row.id=`purchase-row-${record.id}`;const cells=Array.from({length:16},()=>{const td=document.createElement('td');row.append(td);return td;});
            const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=selected.has(record.id);checkbox.setAttribute('aria-label',`Select ${record.number}`);checkbox.addEventListener('change',()=>{checkbox.checked?selected.add(record.id):selected.delete(record.id);refresh();});cells[0].append(checkbox);
            const badge=document.createElement('span');badge.className=record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge';badge.textContent=record.status==='draft'?'Drafting':record.status==='posted'?'Posted':'Voided';cells[1].append(badge);
            const txn=actionButton(`View ${record.number}`,()=>open(record));txn.textContent=record.number;txn.className='purchase-txn';cells[3].append(txn);
            const mapping={2:'date',4:'supplier',5:'gross',6:'tax',7:'account',8:'ewt',9:'wvat',10:'terms',11:'reference',12:'bank',13:'description'};
            for(const [index,key] of Object.entries(mapping)){
                if(record.status==='draft')cells[index].append(input(record,key));
                else cells[index].textContent=key==='tax'?window.SheetMasters.taxLabel(record.tax):key==='gross'?money(record.gross):key==='ewt'?ewtLabel(record.ewt,record.ewtCode):key==='wvat'?ewtLabel(record.wvat,record.wvatCode):record[key];
            }
            if(record.status!=='draft'){const tin=document.createElement('small');tin.textContent=`TIN: ${record.tin || 'Not recorded'}`;cells[4].append(tin);}
            cells[14].textContent=money(ledger.purchaseAmounts(record).payable);
            if(record.status==='draft')window.SheetPosting.draftActions(cells[15],record,{save:()=>saveDraft(record),post:()=>post([record]),delete:()=>deleteDraft(record),more:event=>entryOptions(record,event)});
            else cells[15].append(actionButton(`View ${record.number}`,()=>open(record),'visibility'),actionButton(`Duplicate ${record.number}`,()=>{const draft=add(record);if(draft)open(draft);},'content_copy'),actionButton(`Options for ${record.number}`,event=>entryOptions(record,event),'more_vert'));
            row.addEventListener('click',event=>{if(!event.target.closest('button,input,select,textarea,a,label'))open(record);});rows.append(row);
        }
        if(!shown.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=16;td.textContent='No purchase bills in this view.';tr.append(td);rows.append(tr);}
        refresh();
    }
    function refresh(){
        const shown=current();for(const id of selected)if(!shown.some(record=>record.id===id))selected.delete(id);
        const posted=shown.filter(record=>record.status==='posted');const totals=posted.map(ledger.purchaseAmounts).reduce((total,row)=>{for(const key of Object.keys(total))total[key]+=row[key];return total;},{gross:0,net:0,vat:0,ewt:0,wvat:0,payable:0});
        for(const key of ['gross','vat','ewt','net','payable'])element(`purchases-${key}`).textContent=money(totals[key]);
        element('purchases-footer-vat').textContent=money(totals.vat);element('purchases-footer-ewt').textContent=money(totals.ewt);element('purchases-bills').textContent=`${posted.length} posted bill(s) · Net ${money(totals.net)}`;element('purchases-footing').textContent=`${posted.length} of ${posted.length}`;
        element('purchases-count').textContent=`${shown.length} bill(s) · ${shown.filter(record=>record.status==='draft').length} drafting · ${posted.length} posted · ${selected.size} selected`;
        element('purchases-tab-count').textContent=String(shown.length);element('purchases-filter-label').textContent=status==='all'?'All statuses':status==='draft'?'Drafts':status==='posted'?'Posted':'Voided';element('purchases-filter').setAttribute('aria-pressed',String(status!=='all'));
        element('purchases-post-count').textContent=`(${window.SheetPosting.candidates(shown,selected).length})`;
        element('purchases-select-all').checked=shown.length>0&&shown.every(record=>selected.has(record.id));element('purchases-select-all').indeterminate=selected.size>0&&!element('purchases-select-all').checked;
        window.WorkingPeriod.render();
    }
    function open(record,edit=false){
        original=clone(record);working=clone(record);working.attachments ||= [];editing=record.status==='draft'||edit;
        drawer.classList.toggle('purchase-is-void',record.status==='void');element('purchase-entry-message').textContent='';
        const badge=element('purchase-entry-badge');badge.className=record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge';badge.textContent=`${record.status==='draft'?'New entry':record.status==='void'?'Voided':'Posted'} · ${record.number}`;
        element('purchase-entry-title').textContent=record.status==='draft'?'New Bill':record.status==='void'?'Voided Bill':edit?'Edit Bill':'Posted Bill';
        for(const [key,field] of Object.entries(fields)){field.value=key==='gross'?(record.gross/100).toFixed(2):record[key] ?? '';if(field.tagName==='SELECT')window.SheetMasters.selectValue(field,fieldValue(working,key),working[key+'Code']);field.setCustomValidity('');if(field.tagName==='SELECT')field.disabled=!editing;else field.readOnly=!editing;}
        element('purchase-draft-actions').hidden=record.status!=='draft';element('purchase-posted-actions').hidden=record.status!=='posted'||edit;element('purchase-edit-actions').hidden=!edit;element('purchase-void-actions').hidden=record.status!=='void';element('purchase-confirm-actions').hidden=true;element('purchase-void-confirm').hidden=true;
        element('purchase-void-audit').hidden=record.status!=='void';element('purchase-void-reason').textContent=record.voidReason || '';element('purchase-void-time').textContent=record.voidedAt?`${record.voidedBy || 'Demo user'} · ${new Date(record.voidedAt).toLocaleString('en-PH',{timeZone:'Asia/Manila'})} PHT`:'';
        element('purchase-upload-label').hidden=record.status==='void';element('purchase-history').hidden=!(record.history?.length);
        element('purchase-history-list').replaceChildren(...(record.history || []).map(event=>{const li=document.createElement('li');li.textContent=`${event.label} · ${event.user} · ${new Date(event.time).toLocaleString('en-PH')}`;return li;}));
        syncCustomsFields(true);preview();renderFiles();if(!drawer.open)drawer.showModal();drawer.querySelector('.cwt-entry-body').scrollTop=0;element('purchase-entry-close').focus({preventScroll:true});
    }
    const customsMoney=['dutiable','charges','vatPaid'];
    function syncCustomsFields(populate=false){
        if(!working)return;const enabled=/IMPORT/i.test(window.SheetMasters.taxInfo(working.tax).code || '');element('purchase-customs').hidden=!enabled;
        drawer.querySelectorAll('[data-customs-field]').forEach(input=>{input.disabled=!enabled;input.readOnly=!editing;if(populate){const value=working.importation?.[input.dataset.customsField];input.value=value===undefined||value===null?'':customsMoney.includes(input.dataset.customsField)?(value/100).toFixed(2):value;}});
    }
    drawer.querySelectorAll('[data-customs-field]').forEach(input=>input.addEventListener('input',()=>{if(!editing)return;working.importation ||= {};const field=input.dataset.customsField;if(input.value.trim()==='')delete working.importation[field];else working.importation[field]=customsMoney.includes(field)?cents(input.value):input.value.trim();working.draftTouched=true;working.draftSaved=false;syncDraft();}));
    function preview(){
        if(!working)return;const amounts=ledger.purchaseAmounts(working);drawer.querySelectorAll('[data-amount]').forEach(field=>field.textContent=money(amounts[field.dataset.amount]));
        const reversed=working.status==='void';element('purchase-journal-title').textContent=reversed?'Ledger Reversal Preview':'Journal Entry Preview';
        const account=working.terms==='Cash'?working.bank:'2010 · Accounts Payable';
        const journal=[[working.account || 'Expense Account',amounts.net,'Dr'],['2110 · Input VAT',amounts.vat,'Dr'],['2140 · EWT Payable',amounts.ewt,'Cr'],['2150 · Withholding VAT Payable',amounts.wvat,'Cr'],[account,amounts.payable,'Cr']];
        element('purchase-journal').replaceChildren(...journal.filter(([,amount])=>amount>0).map(([title,amount,side])=>{const div=document.createElement('div');const name=document.createElement('span'),value=document.createElement('span');name.textContent=`${reversed?(side==='Dr'?'Cr':'Dr'):side} ${title}`;value.textContent=money(amount);div.append(name,value);return div;}));
    }
    function syncDraft(){if(original?.status!=='draft')return false;const next=records.map(record=>record.id===working.id?clone(working):record);const saved=store(next);if(saved)refresh();return saved;}
    for(const [key,field] of Object.entries(fields))field.addEventListener('input',()=>{if(!editing)return;if(['ewt','wvat'].includes(key))working[key+'Code']=field.selectedOptions[0]?.dataset.masterCode || '';const previousAccount=working.account;working[key]=key==='gross'?cents(field.value):['ewt','wvat'].includes(key)?(field.value===''?'':Number(field.value)):field.value.trim();if(key==='account'){window.SheetMasters.applyAccountWithholding(working,previousAccount);window.SheetMasters.selectValue(fields.ewt,working.ewt,working.ewtCode);fields.ewt.dispatchEvent(new Event('masters:sync'));}if(key==='supplier'){Object.assign(working,window.SheetMasters.partySnapshot(working));}if(key==='tax'){if(!/IMPORT/i.test(window.SheetMasters.taxInfo(working.tax).code || ''))delete working.importation;syncCustomsFields(true);}working.draftTouched=true;working.draftSaved=false;preview();syncDraft();});
    fields.supplier.addEventListener('change',()=>{if(!editing)return;suggestPurchaseSettings(working,drawer);syncCustomsFields(true);preview();syncDraft();});
    function close(){if(working?.status==='draft'){drawer.close();editing=false;render();return;}if((working&&incomplete(working))||unsavedEdit()){guard.confirm(()=>{if(working.status==='draft')store(records.filter(record=>record.id!==working.id));drawer.close();editing=false;render();},true);}else{drawer.close();editing=false;render();}}
    element('purchase-entry-close').addEventListener('click',close);drawer.addEventListener('cancel',event=>{event.preventDefault();close();});
    element('purchase-entry-form').addEventListener('submit',event=>{
        event.preventDefault();if(invalid(working)){announce('Complete all required bill details before posting.');return;}
        const atcError=window.SheetMasters.withholdingError({...working,ewtCode:ledger.wtpAtc(working),wvatCode:ledger.wtpVatAtc(working)});if(atcError){announce(atcError);return;}
        if(original.status==='posted'){history(working,'Edited');if(store(records.map(record=>record.id===working.id?clone(working):record))){render();open(working);announce('Bill changes saved.');}}
        else if(post([working]))open(records.find(record=>record.id===working.id));
    });
    element('purchase-save').addEventListener('click',()=>saveDraft(working));
    element('purchase-discard').addEventListener('click',()=>deleteDraft(working));
    element('purchase-edit').addEventListener('click',()=>open(records.find(record=>record.id===working.id),true));element('purchase-cancel-edit').addEventListener('click',()=>{if(unsavedEdit())guard.confirm(()=>open(original),true);else open(original);});
    element('purchase-void').addEventListener('click',()=>{element('purchase-void-confirm').hidden=false;element('purchase-confirm-actions').hidden=false;element('purchase-posted-actions').hidden=true;element('purchase-void-input').value='';element('purchase-void-input').setCustomValidity('');element('purchase-void-input').focus();});
    element('purchase-cancel-void').addEventListener('click',()=>open(original));
    element('purchase-confirm-void').addEventListener('click',()=>{const reason=element('purchase-void-input');reason.setCustomValidity(reason.value.trim()?'':'Enter a reason for voiding.');if(!reason.reportValidity())return;Object.assign(working,{status:'void',voidReason:reason.value.trim(),voidedAt:new Date().toISOString(),voidedBy:company.full_name || 'Demo user'});history(working,'Voided');if(store(records.map(record=>record.id===working.id?clone(working):record))){render();open(working);announce('Bill voided; excluded from posted totals.');}});
    element('purchase-restore').addEventListener('click',()=>{if(invalid(working)){announce('This bill has incomplete inputs. Clone it to correct its details.');return;}working.status='posted';history(working,'Restored');if(store(records.map(record=>record.id===working.id?clone(working):record))){render();open(working);announce('Bill restored to posted.');}});
    element('purchase-clone').addEventListener('click',()=>{const record=add(working);if(record)open(record);});
    element('purchase-archive').addEventListener('click',()=>info(`Remove ${working.number}?`,'The voided bill will be archived from the visible sheet. Its number and audit history are retained.',[['Archive bill',()=>{working.status='archived';history(working,'Archived');if(store(records.map(record=>record.id===working.id?clone(working):record))){const id=working.id;drawer.close();render();info('Bill archived','The bill was removed from the visible sheet.',[['Undo',()=>{const restored=records.find(record=>record.id===id);restored.status='void';history(restored,'Unarchived');store(records);render();infoDialog.close();open(restored);}]]) ;}}]]));
    function renderFiles(){element('purchase-files').replaceChildren(...(working.attachments || []).map(file=>{const li=document.createElement('li'),link=document.createElement('a');link.textContent=file.name;link.href=file.data;link.download=file.name;li.append(link);if(working.status!=='void')li.append(actionButton(`Remove ${file.name}`,()=>{working.attachments=working.attachments.filter(item=>item.id!==file.id);saveFiles();renderFiles();},'close'));return li;}));}
    function saveFiles(){if(original.status==='posted'&&!editing){const next=records.map(record=>record.id===working.id?{...record,attachments:clone(working.attachments)}:record);return store(next);}if(original.status==='draft'){working.draftTouched=true;working.draftSaved=false;return syncDraft();}return true;}
    element('purchase-upload').addEventListener('change',async event=>{
        const files=[...event.target.files];const total=(working.attachments || []).reduce((sum,file)=>sum+file.size,0)+files.reduce((sum,file)=>sum+file.size,0);
        if(total>2*1024*1024||files.some(file=>!['application/pdf','image/jpeg','image/png'].includes(file.type))){announce('Choose PDF, JPG or PNG receipts, up to 2 MB total per bill.');event.target.value='';return;}
        const activeId=working.id;const previous=clone(working.attachments);
        try{const additions=await Promise.all(files.map(file=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve({id:crypto.randomUUID(),name:file.name,type:file.type,size:file.size,data:reader.result});reader.onerror=reject;reader.readAsDataURL(file);})));if(!drawer.open||working.id!==activeId)return;working.attachments.push(...additions);if(!saveFiles())working.attachments=previous;renderFiles();}catch{announce('Could not read this receipt. Please try another file.');}event.target.value='';
    });
    element('purchase-print').addEventListener('click',()=>{
        const sheet=element('purchase-print-sheet');sheet.replaceChildren();const title=document.createElement('h1');title.textContent=`${company.company_name} · Purchase Bill`;const note=document.createElement('p');note.textContent='Local purchase statement · Not an official BIR Form 2307';sheet.append(title,note);
        const amounts=ledger.purchaseAmounts(working);for(const [label,value] of [['Bill',working.number],['Date',working.date],['Supplier',working.supplier],['TIN',working.tin],['Invoice / Reference',working.reference],['Description',working.description],['Status',working.status],...Object.entries(amounts).map(([key,value])=>[key,money(value)])]){const p=document.createElement('p');p.textContent=`${label}: ${value || 'Not recorded'}`;sheet.append(p);}window.print();
    });
    element('purchases-add').addEventListener('click',()=>add());document.querySelector('[data-purchases-add]').addEventListener('click',()=>add());document.querySelectorAll('[data-purchases-add-row]').forEach(button=>button.addEventListener('click',()=>add()));
    element('purchases-post').addEventListener('click',()=>post(window.SheetPosting.candidates(current(),selected)));
    element('purchases-filter').addEventListener('click',()=>{const values=['all','draft','posted','void'];status=values[(values.indexOf(status)+1)%values.length];render();});
    element('purchases-sort').addEventListener('click',()=>{descending=!descending;element('purchases-sort').setAttribute('aria-pressed',String(descending));render();});element('purchases-group').addEventListener('click',()=>{grouped=!grouped;element('purchases-group').setAttribute('aria-pressed',String(grouped));render();});
    [['purchases-columns','purchase-hide-extra'],['purchases-freeze','purchase-freeze'],['purchases-wrap','purchase-wrap']].forEach(([id,className])=>element(id).addEventListener('click',()=>element(id).setAttribute('aria-pressed',String(table.classList.toggle(className)))));
    element('purchases-stats').addEventListener('click',()=>{element('purchases-metrics').hidden=!element('purchases-metrics').hidden;element('purchases-stats').setAttribute('aria-pressed',String(!element('purchases-metrics').hidden));});
    ['purchases-search','sales-global-search'].forEach(id=>element(id).addEventListener('input',render));element('purchases-select-all').addEventListener('change',event=>{current().forEach(record=>event.target.checked?selected.add(record.id):selected.delete(record.id));render();});
    [['purchases-prev',-1],['purchases-next',1]].forEach(([id,step])=>element(id).addEventListener('click',()=>guard.confirm(()=>{if(!store(records.filter(record=>!incomplete(record))))return;const date=new Date(`${period}-01T12:00:00`);date.setMonth(date.getMonth()+step);period=`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}`;render();})));
    document.querySelectorAll('[data-purchases-info],[data-sales-info]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();const title=button.dataset.purchasesInfo || button.textContent.trim();const messages={OCR:'Receipt scanning is not connected yet. Add a bill and attach its receipt.',Import:'Import is not connected yet. Use Add Bill to enter purchases.',About:'Purchases and receipts are stored in this browser tab. Tax calculations and journal entries are previews. Payments, BIR tax forms and SLSP exports are not connected.',Shortcuts:'Shift+N: new bill. Ctrl/⌘+K: search. Tab: next field. Click an inactive cell to open the bill popup.'};info(title,messages[title] || 'This workspace module is not connected yet.');}));element('purchases-dialog-close').addEventListener('click',()=>infoDialog.close());
    element('purchases-export').addEventListener('click',()=>{const cell=value=>`"${String(value ?? '').replace(/^[=+@-]/,"'$&").replace(/"/g,'""')}"`;const data=[['Status','Date','Bill','Supplier','TIN','Gross','Tax Profile','Expense Account','EWT','VAT','Net Payable','Reference','Description'],...current().map(record=>{const amounts=ledger.purchaseAmounts(record);return [record.status,record.date,record.number,record.supplier,record.tin,(record.gross/100).toFixed(2),window.SheetMasters.taxLabel(record.tax),record.account,(amounts.ewt/100).toFixed(2),(amounts.vat/100).toFixed(2),(amounts.payable/100).toFixed(2),record.reference,record.description];})];const url=URL.createObjectURL(new Blob(['\uFEFF'+data.map(row=>row.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download=`purchases-${period}.csv`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
    const menu=element('sales-menu-toggle'),backdrop=element('sales-backdrop');const closeMenu=()=>{document.body.classList.remove('sales-menu-open');menu.setAttribute('aria-expanded','false');backdrop.hidden=true;};menu.addEventListener('click',()=>{const opened=document.body.classList.toggle('sales-menu-open');menu.setAttribute('aria-expanded',String(opened));backdrop.hidden=!opened;});backdrop.addEventListener('click',closeMenu);window.matchMedia('(min-width:1024px)').addEventListener('change',closeMenu);
    document.addEventListener('keydown',event=>{if(drawer.open||infoDialog.open||document.querySelector('.draft-leave-dialog[open]'))return;if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();element('purchases-search').focus();}if(!event.target.matches('input,select,textarea')&&event.shiftKey&&event.key.toLowerCase()==='n'){event.preventDefault();add();}});
    render();
    const requestedBill=new URLSearchParams(location.search).get('entry');
    const linkedBill=records.find(record=>record.number===requestedBill&&record.status==='posted');
    if(linkedBill)open(linkedBill,true);
    function entryOptions(record,event){event.stopPropagation();window.SheetEntryActions.open(event.currentTarget,record,{delete:()=>deleteDraft(record),edit:()=>{open(record);element('purchase-edit').click();},duplicate:()=>{const draft=add(record);if(draft)open(draft);},print:()=>{open(record);element('purchase-print').click();},void:()=>{open(record);element('purchase-void').click();},view:()=>open(record)});}
    window.SheetBulk={selected:()=>current().filter(record=>selected.has(record.id)),refresh:()=>{records=ledger.purchases();selected.clear();render();},announce};
document.addEventListener('working-period:change',event=>{period=event.detail.period;render();});
})();
