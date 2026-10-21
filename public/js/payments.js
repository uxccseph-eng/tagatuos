(() => {
    const ledger=window.KontaduryaLedger;
    const element=id=>document.getElementById(id);
    const company=JSON.parse(element('payment-company').textContent);
    const table=element('payments-table'),rows=element('payments-rows'),drawer=element('payment-entry'),infoDialog=element('payments-info');
    const fields=Object.fromEntries([...drawer.querySelectorAll('[data-payment-field]')].map(field=>[field.dataset.paymentField,field]));
    const clone=value=>JSON.parse(JSON.stringify(value));
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format((value || 0)/100);
    const cents=value=>{const text=String(value).trim();if(!/^\d{1,9}(\.\d{0,2})?$/.test(text))return 0;const [whole,fraction='']=text.split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));};
    let records=ledger.payments(),period=window.WorkingPeriod.current().from.slice(0,7),status='all',descending=false,grouped=false,working=null,original=null,editing=false;
    const selected=new Set();
    const announce=message=>{element('payments-status').textContent=message;if(drawer.open)element('payment-entry-message').textContent=message;};
    const store=next=>{try{ledger.savePayments(next);records=next;return true;}catch(error){announce(error.message || 'Could not save in this tab. Remove large voucher attachments and try again.');return false;}};
    const invalid=record=>Boolean(ledger.paymentError(record,records));
    const incomplete=record=>window.DraftLeaveGuard.hasDraftDetails(record);
    const unsavedEdit=()=>editing&&original?.status==='posted'&&JSON.stringify(working)!==JSON.stringify(original);
    const guard=new window.DraftLeaveGuard({hasChanges:()=>records.some(incomplete)||unsavedEdit(),discard:()=>{store(records.filter(record=>!incomplete(record)));}});
    const current=()=>{const query=`${element('payments-search').value} ${element('sales-global-search').value}`.trim().toLowerCase();return records.filter(record=>record.status!=='archived'&&window.WorkingPeriod.matches(record.date)&&(status==='all'||record.status===status)&&`${record.number} ${record.supplier} ${record.tin} ${record.reference} ${record.notes} ${record.amount/100}`.toLowerCase().includes(query));};
    function actionButton(label,callback,icon){const button=document.createElement('button');button.type='button';button.title=label;button.setAttribute('aria-label',label);button.addEventListener('click',callback);if(icon){button.className='payment-row-action';const span=document.createElement('span');span.className='material-symbols-outlined';span.textContent=icon;span.setAttribute('aria-hidden','true');button.append(span);}else button.textContent=label;return button;}
    function info(title,message,actions=[]){element('payments-dialog-title').textContent=title;element('payments-dialog-text').textContent=message;element('payments-dialog-actions').replaceChildren(...actions.map(([label,callback])=>actionButton(label,callback)));if(!infoDialog.open)infoDialog.showModal();}
    function saveDraft(record){const saved={...clone(record),draftSaved:true};if(!store(records.map(item=>item.id===record.id?saved:item)))return;if(working?.id===record.id)working=clone(saved);render();announce(record.number+' draft saved in this tab. Post it when ready.');}
    function deleteDraft(record){window.DraftLeaveGuard.confirmDelete(()=>{if(!store(records.filter(item=>item.id!==record.id)))return false;if(drawer.open&&working?.id===record.id){drawer.close();editing=false;}render();announce('Draft deleted.');},record.number);}
    function history(record,label){record.history=[...(record.history || []),{label,user:company.full_name || 'Demo user',time:new Date().toISOString()}];}
    function add(template,billId){const pending=window.DraftLeaveGuard.continueDraft(records,record=>{window.WorkingPeriod.revealDate(record.date);period=window.WorkingPeriod.current().from.slice(0,7);status='all';element('payments-search').value='';element('sales-global-search').value='';render();element('payment-row-'+record.id)?.scrollIntoView({block:'nearest'});announce('Continue '+record.number+'. Only one draft is allowed on this page.');});if(pending)return pending;
        const identity=ledger.nextPayment();
        const record={...identity,date:window.WorkingPeriod.entryDate(),supplier:'',tin:'',billId:null,bill:'',amount:0,bank:(window.WorkspaceMasters?.preferences?.defaultBank || '1010 · Cash on Hand'),method:'Cash',type:'AP Settlement',account:'',reference:'',notes:'',attachments:[],status:'draft',origin:'manual',createdAt:new Date().toISOString()};
        if(template)for(const key of ['supplier','tin','amount','bank','method','type','account','notes'])record[key]=template[key];
        if(template?.billId)selectBill(record,template.billId);
        if(billId)selectBill(record,billId);
        history(record,'Created');if(!store([...records,record]))return;
        status='all';element('payments-search').value='';element('sales-global-search').value='';render();announce(record.number+' added. Fill its row or click an inactive cell to open the entry popup.');element('payment-row-'+record.id)?.scrollIntoView({block:'nearest'});return record;
    }
    function available(billId,excludeId){const bill=ledger.payableBills().find(bill=>bill.id===Number(billId));if(!bill)return 0;const paid=records.filter(record=>record.id!==excludeId&&record.billId===bill.id&&record.type==='AP Settlement'&&record.status==='posted').reduce((sum,record)=>sum+record.amount,0);return Math.max(0,bill.payable-paid);}
    function selectBill(record,billId){const bill=ledger.payableBills().find(bill=>bill.id===Number(billId));Object.assign(record,{billId:bill?.id || null,bill:bill?.number || '',supplier:bill?.supplier || record.supplier,tin:bill?.tin || '',type:'AP Settlement',account:'2010 · Accounts Payable',amount:bill?available(bill.id,record.id):0});}
    function billOptions(select,record){select.replaceChildren(new Option('Select a posted purchase bill',''));for(const bill of ledger.payableBills())if(available(bill.id,record.id)>0||record.billId===bill.id)select.add(new Option(bill.number+' — '+money(record.status==='posted'&&!editing?bill.balance:available(bill.id,record.id))+' open',String(bill.id)));if(record.billId&&![...select.options].some(option=>option.value===String(record.billId)))select.add(new Option(record.bill || 'Unavailable bill',String(record.billId)));select.value=String(record.billId || '');}

    function input(record,key){
        const field=fields[key].cloneNode(true);field.removeAttribute('data-payment-field');field.removeAttribute('id');field.disabled=false;field.readOnly=false;field.setAttribute('aria-label',record.number+' '+key);
        if(key==='billId'){billOptions(field,record);field.disabled=record.type!=='AP Settlement';}else if(field.tagName==='SELECT'&&['account','bank'].includes(key))ledger.setAccountSelect(field,record[key] || '');else field.value=key==='amount'?(record.amount/100).toFixed(2):record[key] ?? '';
        if(key==='supplier'&&record.billId)field.readOnly=true;
        if(field.tagName==='SELECT'&&key!=='billId')window.SheetMasters.selectValue(field,record[key],record[key+'Code']);
        field.addEventListener('input',()=>{if(['ewt','wvat'].includes(key))record[key+'Code']=field.selectedOptions[0]?.dataset.masterCode || '';record.draftTouched=true;record.draftSaved=false;
            if(key==='billId')selectBill(record,Number(field.value));
            else{record[key]=key==='amount'?cents(field.value):field.value;if(key==='type'&&record.type!=='AP Settlement'){record.billId=null;record.bill='';record.account=record.type==='Supplier Advance'?'1170 · Advances to Suppliers':'';}}
            if(key==='supplier')Object.assign(record,window.SheetMasters.partySnapshot(record));store(records);refresh();
        });field.addEventListener('change',()=>render());return field;
    }

    function post(candidates){
        if(!candidates.length){announce('Select one or more draft payments to post.');return false;}
        for(const record of candidates){const error=ledger.paymentError(record,records);if(error){announce(record.number+': '+error);return false;}}
        const next=clone(records);for(const candidate of candidates){const target=next.find(record=>record.id===candidate.id);if(!target||target.status!=='draft')return false;Object.assign(target,candidate,{status:'posted',postedAt:new Date().toISOString()});history(target,'Posted');}
        if(!store(next))return false;selected.clear();render();announce(candidates.length+' payment(s) posted in this local preview.');return true;
    }

    function render(){
        rows.replaceChildren();const shown=current().sort((a,b)=>grouped?a.supplier.localeCompare(b.supplier):(descending?-1:1)*a.date.localeCompare(b.date));
        for(const record of shown){const row=document.createElement('tr');row.dataset.status=record.status;row.id='payment-row-'+record.id;const cells=Array.from({length:13},()=>{const td=document.createElement('td');row.append(td);return td;});
            const check=document.createElement('input');check.type='checkbox';check.checked=selected.has(record.id);check.setAttribute('aria-label','Select '+record.number);check.addEventListener('change',()=>{check.checked?selected.add(record.id):selected.delete(record.id);refresh();});cells[0].append(check);
            const badge=document.createElement('span');badge.className=record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge';badge.textContent=record.status==='draft'?'Drafting':record.status==='posted'?'Posted':'Voided';cells[1].append(badge);
            const txn=actionButton('View '+record.number,()=>open(record));txn.textContent=record.number;txn.className='payment-txn';cells[3].append(txn);
            for(const [index,key] of Object.entries({2:'date',4:'supplier',5:'billId',6:'amount',7:'bank',8:'reference',9:'method',10:'type'})){
                if(record.status==='draft')cells[index].append(input(record,key));else cells[index].textContent=key==='amount'?money(record.amount):key==='billId'?record.bill || 'Unlinked':record[key];
            }
            if(record.status!=='draft'){const tin=document.createElement('small');tin.textContent='TIN: '+(record.tin || 'Not recorded');cells[4].append(tin);}
            cells[11].textContent=record.origin==='cash-purchase'?'From cash purchase':record.type==='AP Settlement'?'Bill settlement':record.type;
            if(record.status==='draft')window.SheetPosting.draftActions(cells[12],record,{save:()=>saveDraft(record),post:()=>post([record]),delete:()=>deleteDraft(record),more:event=>entryOptions(record,event)});
            else cells[12].append(actionButton('View '+record.number,()=>open(record),'visibility'),actionButton('Duplicate '+record.number,()=>{const draft=add(record);if(draft)open(draft);},'content_copy'),actionButton('Options for '+record.number,event=>entryOptions(record,event),'more_vert'));
            row.addEventListener('click',event=>{if(event.target.matches('input[readonly]')||!event.target.closest('button,input,select,textarea,a,label'))open(record);});rows.append(row);
        }
        if(!shown.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=13;td.textContent='No payments in this view.';tr.append(td);rows.append(tr);}refresh();
    }

    function refresh(){
        const shown=current();for(const id of selected)if(!shown.some(record=>record.id===id))selected.delete(id);
        const posted=shown.filter(record=>record.status==='posted'),total=posted.reduce((sum,record)=>sum+record.amount,0),settled=posted.filter(record=>record.type==='AP Settlement').reduce((sum,record)=>sum+record.amount,0);
        element('payments-total').textContent=money(total);element('payments-settled').textContent=money(settled);element('payments-cash').textContent=money(total);element('payments-booked').textContent=posted.length+' posted disbursement(s)';element('payments-accounts').textContent=new Set(posted.map(record=>record.bank)).size+' cash / bank account(s)';element('payments-footing').textContent=posted.length+' of '+posted.length;
        element('payments-footer-total').textContent=money(total);element('payments-footer-ap').textContent=money(settled);element('payments-footer-other').textContent=money(total-settled);element('payments-count').textContent=shown.length+' payment(s) · '+shown.filter(record=>record.status==='draft').length+' drafting · '+posted.length+' posted · '+selected.size+' selected';
        element('payments-tab-count').textContent=String(shown.length);element('payments-filter-label').textContent=status==='all'?'All statuses':status==='draft'?'Drafts':status==='posted'?'Posted':'Voided';element('payments-filter').setAttribute('aria-pressed',String(status!=='all'));element('payments-post-count').textContent='('+window.SheetPosting.candidates(shown,selected).length+')';
        element('payments-select-all').checked=shown.length>0&&shown.every(record=>selected.has(record.id));element('payments-select-all').indeterminate=selected.size>0&&!element('payments-select-all').checked;
        window.WorkingPeriod.render();
        const pending=element('payment-bills-list');pending.replaceChildren();for(const bill of ledger.payableBills().filter(bill=>bill.balance>0)){const card=document.createElement('div');card.className='payment-unpaid';const text=document.createElement('div');text.textContent=bill.number+' · '+money(bill.balance);const supplier=document.createElement('small');supplier.textContent=bill.supplier;const pay=actionButton('Pay '+bill.number,()=>{const draft=add(null,bill.id);if(draft)open(draft);});pay.textContent='Pay';text.append(supplier);card.append(text,pay);pending.append(card);}pending.closest('.payment-pending').hidden=!pending.children.length;
    }

    function open(record,edit=false){
        original=clone(record);working=clone(record);working.attachments ||= [];editing=record.status==='draft'||edit;
        drawer.classList.toggle('payment-is-void',record.status==='void');element('payment-entry-message').textContent='';const badge=element('payment-entry-badge');badge.className=record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge';badge.textContent=(record.status==='draft'?'New entry':record.status==='void'?'Voided':'Posted')+' · '+record.number;
        element('payment-entry-title').textContent=record.status==='draft'?'New Payment Entry':record.status==='void'?'Voided Payment':edit?'Edit Payment':'Voucher Inspection';
        for(const [key,field] of Object.entries(fields)){if(key==='billId')billOptions(field,record);else field.value=key==='amount'?(record.amount/100).toFixed(2):record[key] ?? '';if(field.tagName==='SELECT')window.SheetMasters.selectValue(field,working[key],working[key+'Code']);field.setCustomValidity('');if(field.tagName==='SELECT')field.disabled=!editing;else field.readOnly=!editing;}
        fields.supplier.readOnly=!editing||Boolean(record.billId);fields.billId.disabled=!editing||record.type!=='AP Settlement';element('payment-expense-label').hidden=record.type!=='Direct Expense';element('payment-exact').hidden=!editing||record.type!=='AP Settlement';
        element('payment-draft-actions').hidden=record.status!=='draft';element('payment-posted-actions').hidden=record.status!=='posted'||edit;element('payment-edit-actions').hidden=!edit;element('payment-void-actions').hidden=record.status!=='void';element('payment-confirm-actions').hidden=true;element('payment-void-confirm').hidden=true;
        element('payment-void-audit').hidden=record.status!=='void';element('payment-void-reason').textContent=record.voidReason || '';element('payment-void-time').textContent=record.voidedAt?(record.voidedBy || 'Demo user')+' · '+new Date(record.voidedAt).toLocaleString('en-PH',{timeZone:'Asia/Manila'})+' PHT':'';
        element('payment-origin-note').hidden=record.origin!=='cash-purchase';element('payment-upload-label').hidden=record.status==='void';element('payment-history').hidden=!record.history?.length;element('payment-history-list').replaceChildren(...(record.history || []).map(event=>{const li=document.createElement('li');li.textContent=event.label+' · '+event.user+' · '+new Date(event.time).toLocaleString('en-PH');return li;}));
        preview();renderFiles();if(!drawer.open)drawer.showModal();drawer.querySelector('.cwt-entry-body').scrollTop=0;element('payment-entry-close').focus({preventScroll:true});
    }
    function preview(){
        if(!working)return;const amount=working.amount || 0,reversed=working.status==='void',settlement=working.type==='AP Settlement';
        element('payment-preview-total').textContent=money(amount);element('payment-outflow-label').textContent=reversed?'Reversed Disbursement':'Net Disbursed';element('payment-preview-ap').textContent=settlement?money(amount):'Not an AP settlement';element('payment-preview-balance').textContent=working.billId?money(Math.max(0,available(working.billId,working.id)-(reversed?0:amount))):'No bill linked';
        element('payment-journal-title').textContent=reversed?'Automated Ledger Reversal Preview':'GL Voucher Preview';const debit=settlement?'2010 · Accounts Payable':working.type==='Supplier Advance'?'1170 · Advances to Suppliers':working.account || 'Select expense account';const journal=[[debit,amount,reversed?'Cr':'Dr'],[working.bank || 'Cash / Bank',amount,reversed?'Dr':'Cr']];element('payment-journal').replaceChildren(...journal.map(([account,value,side])=>{const div=document.createElement('div'),name=document.createElement('span'),total=document.createElement('span');name.textContent=side+' '+account;total.textContent=money(value);div.append(name,total);return div;}));
    }
    function syncDraft(){if(original?.status!=='draft')return false;const next=records.map(record=>record.id===working.id?clone(working):record);const saved=store(next);if(saved)refresh();return saved;}
    for(const [key,field] of Object.entries(fields))field.addEventListener('input',()=>{
        if(!editing)return;
        if(key==='billId'){selectBill(working,Number(field.value));for(const key of ['supplier','tin','amount'])fields[key].value=key==='amount'?(working.amount/100).toFixed(2):working[key];fields.supplier.readOnly=Boolean(working.billId);}
        else{working[key]=key==='amount'?cents(field.value):field.value.trim();if(key==='type'){if(working.type!=='AP Settlement'){working.billId=null;working.bill='';working.account=working.type==='Supplier Advance'?'1170 · Advances to Suppliers':'';fields.billId.value='';fields.account.value=working.account;fields.supplier.readOnly=false;}fields.billId.disabled=working.type!=='AP Settlement';element('payment-expense-label').hidden=working.type!=='Direct Expense';element('payment-exact').hidden=working.type!=='AP Settlement';}}
        working.draftTouched=true;working.draftSaved=false;preview();syncDraft();
    });
    element('payment-exact').addEventListener('click',()=>{working.amount=available(working.billId,working.id);fields.amount.value=(working.amount/100).toFixed(2);working.draftTouched=true;working.draftSaved=false;preview();syncDraft();});

    function close(){if(working?.status==='draft'){drawer.close();editing=false;render();return;}if((working&&incomplete(working))||unsavedEdit()){guard.confirm(()=>{if(working.status==='draft')store(records.filter(record=>record.id!==working.id));drawer.close();editing=false;render();},true);}else{drawer.close();editing=false;render();}}
    element('payment-entry-close').addEventListener('click',close);drawer.addEventListener('cancel',event=>{event.preventDefault();close();});
    element('payment-entry-form').addEventListener('submit',event=>{
        event.preventDefault();const error=ledger.paymentError(working,records);if(error){announce(error);return;}
        if(original.status==='posted'){history(working,'Edited');if(store(records.map(record=>record.id===working.id?clone(working):record))){render();open(working);announce('Payment changes saved.');}}
        else if(post([working]))open(records.find(record=>record.id===working.id));
    });
    element('payment-save').addEventListener('click',()=>saveDraft(working));
    element('payment-discard').addEventListener('click',()=>deleteDraft(working));
    element('payment-edit').addEventListener('click',()=>{if(working.origin==='cash-purchase'){guard.confirm(()=>{if(store(records.filter(record=>!incomplete(record))))location.assign(drawer.dataset.purchasesUrl+'?entry='+encodeURIComponent(working.bill));});}else open(records.find(record=>record.id===working.id),true);});element('payment-cancel-edit').addEventListener('click',()=>{if(unsavedEdit())guard.confirm(()=>open(original),true);else open(original);});
    element('payment-void').addEventListener('click',()=>{element('payment-void-confirm').hidden=false;element('payment-confirm-actions').hidden=false;element('payment-posted-actions').hidden=true;element('payment-void-input').value='';element('payment-void-input').setCustomValidity('');element('payment-void-input').focus();});
    element('payment-cancel-void').addEventListener('click',()=>open(original));
    element('payment-confirm-void').addEventListener('click',()=>{const reason=element('payment-void-input');reason.setCustomValidity(reason.value.trim()?'':'Enter a reason for voiding.');if(!reason.reportValidity())return;Object.assign(working,{status:'void',paymentVoided:true,voidReason:reason.value.trim(),voidedAt:new Date().toISOString(),voidedBy:company.full_name || 'Demo user'});history(working,'Voided');if(store(records.map(record=>record.id===working.id?clone(working):record))){render();open(working);announce('Payment voided; bill balance reopened.');}});
    element('payment-restore').addEventListener('click',()=>{if(invalid(working)){announce('This payment cannot be restored. Check its source bill and remaining balance.');return;}working.status='posted';working.paymentVoided=false;history(working,'Restored');if(store(records.map(record=>record.id===working.id?clone(working):record))){render();open(working);announce('Payment restored to posted.');}});
    element('payment-clone').addEventListener('click',()=>{const record=add(working);if(record)open(record);});
    element('payment-archive').addEventListener('click',()=>info(`Remove ${working.number}?`,'The voided payment will be archived from the visible sheet. Its number and audit history are retained.',[['Archive payment',()=>{working.status='archived';working.paymentArchived=true;history(working,'Archived');if(store(records.map(record=>record.id===working.id?clone(working):record))){const id=working.id;drawer.close();render();info('Payment archived','The payment was removed from the visible sheet.',[['Undo',()=>{const restored=records.find(record=>record.id===id);restored.status='void';restored.paymentArchived=false;history(restored,'Unarchived');store(records);render();infoDialog.close();open(restored);}]]) ;}}]]));
    function renderFiles(){element('payment-files').replaceChildren(...(working.attachments || []).map(file=>{const li=document.createElement('li'),link=document.createElement('a');link.textContent=file.name;link.href=file.data;link.download=file.name;li.append(link);if(working.status!=='void')li.append(actionButton(`Remove ${file.name}`,()=>{working.attachments=working.attachments.filter(item=>item.id!==file.id);saveFiles();renderFiles();},'close'));return li;}));}
    function saveFiles(){if(original.status==='posted'&&!editing){const next=records.map(record=>record.id===working.id?{...record,attachments:clone(working.attachments)}:record);return store(next);}if(original.status==='draft'){working.draftTouched=true;working.draftSaved=false;return syncDraft();}return true;}
    element('payment-upload').addEventListener('change',async event=>{
        const files=[...event.target.files];const total=(working.attachments || []).reduce((sum,file)=>sum+file.size,0)+files.reduce((sum,file)=>sum+file.size,0);
        if(total>2*1024*1024||files.some(file=>!['application/pdf','image/jpeg','image/png'].includes(file.type))){announce('Choose PDF, JPG or PNG receipts, up to 2 MB total per payment.');event.target.value='';return;}
        const activeId=working.id;const previous=clone(working.attachments);
        try{const additions=await Promise.all(files.map(file=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve({id:crypto.randomUUID(),name:file.name,type:file.type,size:file.size,data:reader.result});reader.onerror=reject;reader.readAsDataURL(file);})));if(!drawer.open||working.id!==activeId)return;working.attachments.push(...additions);if(!saveFiles())working.attachments=previous;renderFiles();}catch{announce('Could not read this receipt. Please try another file.');}event.target.value='';
    });
    element('payment-print').addEventListener('click',()=>{
        const sheet=element('payment-print-sheet');sheet.replaceChildren();const title=document.createElement('h1');title.textContent=company.company_name+' · Payment Voucher';const note=document.createElement('p');note.textContent='Local voucher copy · Not an official BIR Form 2307';sheet.append(title,note);for(const [label,value] of [['Voucher',working.number],['Date',working.date],['Payee',working.supplier],['TIN',working.tin],['Source Bill',working.bill],['Payment Account',working.bank],['Method',working.method],['Reference',working.reference],['Amount',money(working.amount)],['Notes',working.notes],['Status',working.status]]){const line=document.createElement('p');line.textContent=label+': '+(value || 'Not recorded');sheet.append(line);}window.print();
    });

    element('payments-add').addEventListener('click',()=>add());document.querySelector('[data-payments-add]').addEventListener('click',()=>add());document.querySelectorAll('[data-payments-add-row]').forEach(button=>button.addEventListener('click',()=>add()));
    element('payments-post').addEventListener('click',()=>post(window.SheetPosting.candidates(current(),selected)));
    element('payments-filter').addEventListener('click',()=>{const values=['all','draft','posted','void'];status=values[(values.indexOf(status)+1)%values.length];render();});
    element('payments-sort').addEventListener('click',()=>{descending=!descending;element('payments-sort').setAttribute('aria-pressed',String(descending));render();});element('payments-group').addEventListener('click',()=>{grouped=!grouped;element('payments-group').setAttribute('aria-pressed',String(grouped));render();});
    [['payments-columns','payment-hide-extra'],['payments-freeze','payment-freeze'],['payments-wrap','payment-wrap']].forEach(([id,className])=>element(id).addEventListener('click',()=>element(id).setAttribute('aria-pressed',String(table.classList.toggle(className)))));
    element('payments-stats').addEventListener('click',()=>{element('payments-metrics').hidden=!element('payments-metrics').hidden;element('payments-stats').setAttribute('aria-pressed',String(!element('payments-metrics').hidden));});
    ['payments-search','sales-global-search'].forEach(id=>element(id).addEventListener('input',render));element('payments-select-all').addEventListener('change',event=>{current().forEach(record=>event.target.checked?selected.add(record.id):selected.delete(record.id));render();});
    [['payments-prev',-1],['payments-next',1]].forEach(([id,step])=>element(id).addEventListener('click',()=>guard.confirm(()=>{if(!store(records.filter(record=>!incomplete(record))))return;const date=new Date(`${period}-01T12:00:00`);date.setMonth(date.getMonth()+step);period=`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}`;render();})));
    document.querySelectorAll('[data-payments-info],[data-sales-info]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();const title=button.dataset.paymentsInfo || button.textContent.trim();const messages={OCR:'Receipt scanning is not connected yet. Add a payment and attach its receipt.',Import:'Import is not connected yet. Use Add Payment to enter payments.',About:'Payment vouchers and evidences are stored in this browser tab. Bank transfers, official ledger postings and BIR tax forms are not connected.',Shortcuts:'Shift+N: new payment. Ctrl/⌘+K: search. Tab: next field. Click an inactive cell to open the payment popup.'};info(title,messages[title] || 'This workspace module is not connected yet.');}));element('payments-dialog-close').addEventListener('click',()=>infoDialog.close());
    element('payments-export').addEventListener('click',()=>{const cell=value=>'"'+String(value ?? '').replace(/^[=+@-]/,"'$&").replace(/"/g,'""')+'"';const data=[['Status','Date','Voucher','Payee','TIN','Source Bill','Amount','Account','Reference','Method','Type','Notes'],...current().map(record=>[record.status,record.date,record.number,record.supplier,record.tin,record.bill,(record.amount/100).toFixed(2),record.bank,record.reference,record.method,record.type,record.notes])];const url=URL.createObjectURL(new Blob(['\uFEFF'+data.map(row=>row.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download='payments-'+period+'.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});

    const menu=element('sales-menu-toggle'),backdrop=element('sales-backdrop');const closeMenu=()=>{document.body.classList.remove('sales-menu-open');menu.setAttribute('aria-expanded','false');backdrop.hidden=true;};menu.addEventListener('click',()=>{const opened=document.body.classList.toggle('sales-menu-open');menu.setAttribute('aria-expanded',String(opened));backdrop.hidden=!opened;});backdrop.addEventListener('click',closeMenu);window.matchMedia('(min-width:1024px)').addEventListener('change',closeMenu);
    document.addEventListener('keydown',event=>{if(drawer.open||infoDialog.open||document.querySelector('.draft-leave-dialog[open]'))return;if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();element('payments-search').focus();}if(!event.target.matches('input,select,textarea')&&event.shiftKey&&event.key.toLowerCase()==='n'){event.preventDefault();add();}});
    const requestedEntry=new URLSearchParams(location.search).get('entry');
    const linkedEntry=records.find(record=>record.number===requestedEntry&&record.status==='posted');
    if(linkedEntry){window.WorkingPeriod.revealDate(linkedEntry.date);period=window.WorkingPeriod.current().from.slice(0,7);render();open(linkedEntry);}else render();
    function entryOptions(record,event){event.stopPropagation();window.SheetEntryActions.open(event.currentTarget,record,{delete:()=>deleteDraft(record),edit:()=>{open(record);element('payment-edit').click();},duplicate:()=>{const draft=add(record);if(draft)open(draft);},print:()=>{open(record);element('payment-print').click();},void:()=>{open(record);element('payment-void').click();},view:()=>open(record)});}
    window.SheetBulk={selected:()=>current().filter(record=>selected.has(record.id)),refresh:()=>{records=ledger.payments();selected.clear();render();},announce};
document.addEventListener('working-period:change',event=>{period=event.detail.period;render();});
})();
