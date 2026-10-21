window.CollectionEntryDrawer = class {
    constructor({ledger,guard,onSave,onDiscard,onVoid,onMemo,onPurge}) {
        Object.assign(this,{ledger,guard,onSave,onDiscard,onVoid,onMemo,onPurge});
        this.memoEditing=false;
        this.dialog=document.getElementById('collection-entry-drawer');
        this.fields=Object.fromEntries([...this.dialog.querySelectorAll('[data-collection-field]')].map(field=>[field.dataset.collectionField,field]));
        this.money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format((value || 0)/100);
        this.company=JSON.parse(document.getElementById('collection-company-data').textContent);
        this.printDialog=document.getElementById('collection-print-preview');
        this.element('close-collection-entry').addEventListener('click',()=>this.requestClose());
        this.element('discard-new-collection').addEventListener('click',()=>window.DraftLeaveGuard.confirmDelete(()=>{this.onDiscard(this.record);this.discardClose();},this.record.number));
        this.dialog.addEventListener('cancel',event=>{event.preventDefault();this.requestClose();});
        Object.values(this.fields).forEach(field=>{
            field.addEventListener('input',()=>{field.setCustomValidity('');this.preview();this.autoSaveDraft();});
            field.addEventListener('change',()=>{
                field.setCustomValidity('');
                if(field===this.fields.invoice){const invoice=this.invoice();if(invoice){this.fields.customer.value=invoice.customer;this.fields.type.value='Settlement';}}
                if(field===this.fields.type&&field.value==='Advance')this.fields.invoice.value='Unlinked / Advance Deposit';
                this.preview();this.autoSaveDraft();
            });
        });
        this.element('edit-collection-entry').addEventListener('click',()=>{
            if(this.record.origin==='cash-sale')this.element('collection-entry-sales-link').click();
            else this.open(this.record,true);
        });
        this.element('save-collection-entry').addEventListener('click',()=>this.save(false));
        this.element('post-collection-entry').addEventListener('click',()=>this.save(true));
        this.element('collection-entry-form').addEventListener('submit',event=>{event.preventDefault();this.save(false);});
        this.element('void-collection-entry').addEventListener('click',()=>{
            if(this.record.status==='void'){this.changeVoidStatus();return;}
            if(this.hasChanges()){this.element('collection-entry-error').textContent='Save your changes before voiding this collection.';return;}
            this.element('collection-entry-void-confirm').hidden=false;
            this.element('collection-void-reason').value='';
            this.element('collection-void-reason').setCustomValidity('');
            this.element('collection-void-reason').focus();
        });
        this.element('cancel-void-collection-entry').addEventListener('click',()=>{this.element('collection-entry-void-confirm').hidden=true;});
        this.element('confirm-void-collection-entry').addEventListener('click',()=>{
            const reason=this.element('collection-void-reason');
            reason.value=reason.value.trim();
            if(!reason.reportValidity())return;
            this.changeVoidStatus(reason.value);
        });
        this.element('purge-collection-entry').addEventListener('click',()=>{
            if(this.hasChanges()){this.voidError('Save or cancel your memo changes first.');return;}
            this.element('collection-void-purge-confirm').hidden=false;
            this.element('collection-void-restore-confirm').hidden=true;
        });
        this.element('confirm-purge-collection').addEventListener('click',()=>{
            try {this.onPurge(this.record);this.discardClose();}catch(error){this.voidError(error.message);}
        });
        this.element('restore-void-collection').addEventListener('click',()=>{
            if(this.hasChanges()){this.voidError('Save or cancel your memo changes first.');return;}
            this.element('collection-void-restore-confirm').hidden=false;
            this.element('collection-void-purge-confirm').hidden=true;
        });
        this.element('confirm-restore-collection').addEventListener('click',()=>this.changeVoidStatus());
        this.dialog.querySelectorAll('[data-cancel-collection-void-action]').forEach(button=>button.addEventListener('click',()=>{
            this.element('collection-void-purge-confirm').hidden=true;this.element('collection-void-restore-confirm').hidden=true;
        }));
        this.element('edit-collection-void-memo').addEventListener('click',()=>{
            this.memoEditing=true;
            this.element('collection-void-memo-editor').hidden=false;
            this.element('collection-void-memo-actions').hidden=false;
            this.element('collection-void-reason-display').hidden=true;
            this.element('collection-void-memo-editor').focus();
        });
        this.element('collection-void-memo-editor').addEventListener('input',event=>event.target.setCustomValidity(''));
        this.element('save-collection-void-memo').addEventListener('click',()=>{
            const editor=this.element('collection-void-memo-editor');
            editor.value=editor.value.trim();editor.setCustomValidity(editor.value?'':'Enter a void memo.');
            if(!editor.reportValidity())return;
            try {this.open(this.onMemo(this.record,editor.value));}catch(error){this.voidError(error.message);}
        });
        this.element('cancel-collection-void-memo').addEventListener('click',()=>{
            if(this.hasChanges())this.guard.confirm(()=>this.open(this.record),true);else this.open(this.record);
        });
        this.element('print-collection-entry').addEventListener('click',()=>{
            this.element('collection-voucher-preview').innerHTML=this.receipt();
            this.dialog.close();this.printDialog.showModal();
        });
        const closePrint=()=>{this.printDialog.close();this.open(this.record);};
        this.element('close-collection-print').addEventListener('click',closePrint);
        this.printDialog.addEventListener('cancel',event=>{event.preventDefault();closePrint();});
        this.element('print-collection-voucher').addEventListener('click',()=>{
            this.element('sales-print-output').innerHTML=this.receipt();
            this.printDialog.close();window.print();this.printDialog.showModal();
        });
    }
    element(id){return document.getElementById(id);}
    text(id,value){this.element(id).textContent=value;}
    snapshot(){return JSON.stringify(Object.values(this.fields).map(field=>field.value));}
    hasChanges(){return this.dialog.open&&((this.editing&&this.snapshot()!==this.baseline)||(this.memoEditing&&this.element('collection-void-memo-editor').value!==this.memoBaseline));}
    isOpen(){return this.dialog.open||this.printDialog.open;}
    discardClose(){this.dialog.close();this.printDialog.close();}
    incomplete(){return this.record.status==='draft'&&!this.record.draftSaved&&(!this.record.customer||!this.record.date||!this.record.amount);}
    autoSaveDraft(){
        if(!this.editing||this.record?.status!=='draft')return;
        const draft={...this.record,...Object.fromEntries(Object.entries(this.fields).map(([key,field])=>[key,field.value.trim()])),amount:this.amount() || 0,status:'draft',draftTouched:true,draftSaved:false};
        this.record=draft;this.onSave(draft);this.baseline=this.snapshot();
    }
    requestClose(){
        if(this.record?.status==='draft'){if(this.hasChanges())this.autoSaveDraft();this.dialog.close();return;}
        if(this.hasChanges()||this.incomplete())this.guard.confirm(()=>{if(this.incomplete())this.onDiscard(this.record);this.discardClose();},true);
        else this.dialog.close();
    }
    invoice(){return this.ledger.invoices().find(invoice=>invoice.number===this.fields.invoice.value);}
    amount(){
        const value=this.fields.amount.value;
        if(!/^\d{1,10}(\.\d{1,2})?$/.test(value))return null;
        const [whole,fraction='']=value.split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));
    }
    open(record,edit=false){
        this.record=record;
        this.memoEditing=false;
        this.editing=(edit||record.status==='draft')&&record.origin!=='cash-sale'&&record.status!=='void';
        const invoices=this.ledger.invoices();
        this.fields.invoice.replaceChildren(new Option('Unlinked / Advance Deposit','Unlinked / Advance Deposit'));
        invoices.filter(invoice=>invoice.balance>0||invoice.number===record.invoice).forEach(invoice=>this.fields.invoice.add(new Option(`${invoice.number} — ${this.money(invoice.balance)} unpaid`,invoice.number)));
        Object.entries(this.fields).forEach(([key,field])=>{
            const value=key==='amount'?record.amount?(record.amount/100).toFixed(2):'':record[key] || '';
            if(field.tagName==='SELECT'&&![...field.options].some(option=>option.value===value))field.add(new Option(value,value));
            field.value=value;field.setCustomValidity('');
            if(field.tagName==='SELECT')field.disabled=!this.editing;else field.readOnly=!this.editing;
        });
        this.text('collection-entry-title',record.number);
        const badge=this.element('collection-entry-badge');badge.className=record.status==='draft'?'draft-status-badge':record.status==='posted'?'posted-status-badge':'void-status-badge';badge.textContent=record.status==='draft'?'Drafting':record.status==='posted'?'Posted':'Voided';
        this.dialog.classList.toggle('collection-is-void',record.status==='void');
        const draft=record.status==='draft';
        this.dialog.classList.toggle('collection-is-draft',draft);
        this.dialog.querySelector('.collection-new-heading').hidden=!draft;
        this.dialog.querySelectorAll('.collection-draft-only').forEach(element=>element.hidden=!draft);
        this.text('collection-cash-heading',draft?'Derived Cash Flow Logic':'Derived analytics');
        this.text('collection-journal-heading',draft?'Journal Posting Telemetry':'Journal preview');
        this.text('save-collection-entry',draft?'Save Draft':'Save changes');
        this.text('post-collection-entry','Post Collection');
        this.element('discard-new-collection').hidden=!draft;
        if(draft){badge.textContent='New Entry';badge.className='posted-status-badge';}
        this.text('collection-entry-mode',this.editing?'Editing · Saved in this tab':'Read-only');
        this.text('collection-entry-origin',record.origin==='cash-sale'?'Created automatically from a posted cash sale. Edit its payment details from Sales. Voiding this receipt reopens the invoice balance.':'Manual collection · Stored in this browser tab');
        this.element('collection-entry-void-confirm').hidden=true;
        this.element('collection-entry-error').textContent='';
        this.element('collection-entry-source-note').hidden=record.origin!=='cash-sale';
        this.text('collection-entry-source-note','Voiding this receipt does not void its linked sale.');
        this.element('void-collection-entry').hidden=false;
        this.element('void-collection-entry').title='Void or restore this collection';
        this.dialog.querySelector('[data-collection-void-label]').textContent=record.status==='void'?'Restore':'Void';
        this.element('edit-collection-entry').hidden=this.editing||record.status==='void';
        this.element('edit-collection-entry').title=record.origin==='cash-sale'?'Open linked sale to edit this automatic collection':'Edit collection';
        this.element('print-collection-entry').disabled=this.editing;
        this.element('collection-entry-save-actions').hidden=!this.editing;
        this.element('post-collection-entry').hidden=record.status!=='draft';
        this.element('collection-void-audit').hidden=record.status!=='void';
        if(record.status==='void') {
            this.text('collection-void-audit-reference',`VOID-${record.number}`);
            this.text('collection-void-audit-reason',record.voidAudit?.reason || 'Voided with the linked sale.');
            this.text('collection-void-audit-user',record.voidAudit?.user || 'Linked Sales entry');
            this.text('collection-void-audit-time',record.voidAudit?.time?new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'medium'}).format(new Date(record.voidAudit.time)):'See linked Sales entry');
            this.text('collection-void-audit-effect',this.ledger.invoices().some(invoice=>invoice.number===record.invoice)?'Excluded from collection totals. The linked sale stays posted and its applied amount is available to collect again.':'Excluded from collection totals. No posted sales invoice balance is affected.');
        }
        this.renderVoidLayout();
        this.preview();this.baseline=this.snapshot();
        if(!this.dialog.open)this.dialog.showModal();
        this.element('close-collection-entry').focus({preventScroll:true});
        this.dialog.querySelector('.overflow-y-auto').scrollTop=0;
    }
    preview(){
        const amount=this.amount() || 0;
        const invoice=this.invoice();
        const linked=this.fields.invoice.value!=='Unlinked / Advance Deposit'&&this.fields.type.value!=='Advance';
        const available=invoice?invoice.balance+(this.record.status==='posted'&&this.record.invoice===invoice.number&&this.record.type!=='Advance'?this.record.amount:0):0;
        const remaining=Math.max(0,available-amount);
        this.text('collection-entry-due',this.money(available));
        this.text('collection-entry-remaining',invoice?`${this.money(remaining)}${amount>available?' · Overpayment':amount>0&&remaining===0?' · Fully settled':''}`:'No linked invoice');
        const date=this.fields.date.value;
        this.text('collection-entry-tax-period',date?`Tax period: ${date.slice(0,4)}-Q${Math.ceil(Number(date.slice(5,7))/3)}`:'Tax period: —');
        this.text('collection-entry-open-items',`${this.ledger.invoices().filter(item=>item.balance>0).length} open item(s)`);
        this.text('collection-entry-inflow',this.money(amount));
        this.text('collection-entry-applied',this.money(linked&&this.record.status!=='void'?amount:0));
        this.text('collection-entry-advance',this.money(!linked&&this.record.status!=='void'?amount:0));
        this.text('collection-entry-settlement',this.record.status==='void'?'Voided':this.record.status==='draft'||this.editing?'Draft / preview only':invoice?invoice.balance===0?'Fully collected':'Partially collected':'Unapplied advance');
        if(this.record.status==='draft')this.text('collection-entry-settlement',!amount?'Awaiting amount':!linked?'Advance / unapplied':!invoice?'Select an invoice':amount>available?'Exceeds unpaid balance':remaining===0?'Full settlement preview':'Partial settlement preview');
        const journal=this.element('collection-entry-journal');journal.replaceChildren();
        const reversed=this.record.status==='void';
        [[`${reversed?'Cr':'Dr'}. ${this.fields.bank.value}`,amount],[`${reversed?'Dr':'Cr'}. ${linked?'Accounts Receivable - Trade':'Customer Advances'}`,amount]].forEach(([label,value])=>{
            const line=document.createElement('div');line.className='collection-journal-line';const name=document.createElement('span');name.textContent=label;const figure=document.createElement('strong');figure.textContent=this.money(value);line.append(name,figure);journal.append(line);
            if(this.record.status==='draft'){const detail=document.createElement('small');detail.textContent=label.startsWith('Dr')?'Cash & cash equivalents · Asset increase':linked?`Invoice ${this.fields.invoice.value} · Receivable decrease`:'Customer advance · Liability increase';name.append(detail);}
        });
        const balanced=document.createElement('p');balanced.className='collection-journal-totals';balanced.textContent=`Dr: ${this.money(amount)} · Cr: ${this.money(amount)} · Variance: ${this.money(0)}${reversed?' · Reversal preview':''}`;journal.append(balanced);
        this.text('collection-entry-matched',invoice?'1 invoice matched':'No posted invoice linked');
        this.text('collection-entry-invoice-number',linked?this.fields.invoice.value:'Unlinked / advance');
        this.text('collection-entry-invoice-detail',invoice?`Net collectible: ${this.money(invoice.total)} · Unpaid: ${this.money(invoice.balance)} · ${invoice.date}`:'No linked posted sales document.');
        const link=this.element('collection-entry-sales-link');link.hidden=!linked;
        link.href=`${this.dialog.dataset.salesUrl}?entry=${encodeURIComponent(this.fields.invoice.value)}`;
    }
    save(post){
        if(!this.editing)return;
        const amount=this.amount();const invoice=this.invoice();
        this.fields.customer.value=this.fields.customer.value.trim();
        if(!post&&this.record.status==='draft'){
            Object.values(this.fields).forEach(field=>field.setCustomValidity(''));
            if(this.fields.amount.value&&amount===null)this.fields.amount.setCustomValidity('Enter an amount with at most two decimal places.');
            if(!Object.values(this.fields).filter(field=>field.value).every(field=>field.reportValidity()))return;
            const draft={...this.record,...Object.fromEntries(Object.entries(this.fields).map(([key,field])=>[key,field.value.trim()])),amount:amount || 0,status:'draft',draftSaved:true};
            this.onSave(draft);this.open(draft);return;
        }
        this.fields.amount.setCustomValidity(amount!==null&&amount>0?'':'Enter a positive amount with at most two decimal places.');
        this.fields.invoice.setCustomValidity('');this.fields.customer.setCustomValidity('');
        const unlinked=this.fields.invoice.value==='Unlinked / Advance Deposit';
        if(unlinked&&this.fields.type.value!=='Advance')this.fields.invoice.setCustomValidity('Choose a source invoice for a settlement or downpayment.');
        if(!unlinked){
            if(!invoice)this.fields.invoice.setCustomValidity('The source sale is not posted. Choose another invoice.');
            else if(this.fields.type.value==='Advance')this.fields.invoice.setCustomValidity('Choose Unlinked / Advance Deposit for an advance.');
            else if(invoice.customer!==this.fields.customer.value)this.fields.customer.setCustomValidity('The customer must match the linked invoice.');
            else {
                const originalApplied=this.record.status==='posted'&&this.record.invoice===invoice.number&&this.record.type!=='Advance'?this.record.amount:0;
                const available=invoice.balance+originalApplied;
                if(amount>available)this.fields.amount.setCustomValidity(`The unpaid amount available is ${this.money(available)}.`);
            }
        }
        if(!Object.values(this.fields).every(field=>field.reportValidity()))return;
        const candidate={...this.record,...Object.fromEntries(Object.entries(this.fields).map(([key,field])=>[key,field.value.trim()])),amount,status:post?'posted':this.record.status};
        this.onSave(candidate);this.open(candidate);
    }
    changeVoidStatus(reason) {
        try {this.open(this.onVoid(this.record,reason));}
        catch(error){this.element('collection-entry-error').textContent=error.message;this.voidError(error.message);}
    }
    voidError(message){this.text('collection-void-action-error',message);}
    renderVoidLayout(){
        const record=this.record;
        const voided=record.status==='void';
        this.element('collection-void-layout').hidden=!voided;
        this.element('collection-void-footer').hidden=!voided;
        this.dialog.querySelector('.collection-void-heading').hidden=!voided;
        this.element('collection-void-purge-confirm').hidden=true;
        this.element('collection-void-restore-confirm').hidden=true;
        this.voidError('');
        if(!voided)return;
        this.element('collection-entry-badge').textContent='Voided transaction';
        this.text('collection-void-reason-display',record.voidAudit?.reason || 'Voided with the linked Sales entry.');
        this.text('collection-void-user-display',record.voidAudit?.user || 'Linked Sales entry');
        this.text('collection-void-time-display',record.voidAudit?.time?new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'medium'}).format(new Date(record.voidAudit.time)):'See linked Sales entry');
        this.text('collection-void-reference-display',`VOID-${record.number}`);
        this.memoBaseline=record.voidAudit?.reason || '';
        const editor=this.element('collection-void-memo-editor');editor.value=this.memoBaseline;editor.hidden=true;editor.setCustomValidity('');
        this.element('collection-void-memo-actions').hidden=true;this.element('collection-void-reason-display').hidden=false;
        this.text('collection-void-net',`−${this.money(record.amount)}`);
        this.text('collection-void-original',`+${this.money(record.amount)}`);
        this.text('collection-void-original-detail',this.money(record.amount));
        this.text('collection-void-offset',`−${this.money(record.amount)}`);
        const invoice=this.ledger.invoices().find(sale=>sale.number===record.invoice);
        this.text('collection-void-invoice-action',invoice?`${invoice.number} · ${this.money(invoice.balance)} unpaid`:record.invoice==='Unlinked / Advance Deposit'?'Unlinked advance':'No posted invoice balance');
        this.text('collection-void-settlement-state',invoice&&invoice.balance>0?'Cancelled / Re-collectible':invoice?'Cancelled / Invoice collected elsewhere':'Cancelled / Excluded');
        const journal=this.element('collection-void-journal-lines');journal.replaceChildren();
        [[`DR · ${record.type==='Advance'?'Customer Advances':'Accounts Receivable'}`,'Customer balance / liability reinstatement'],[`CR · ${record.bank}`,'Reversal of original collection']].forEach(([label,detail])=>{
            const line=document.createElement('div');line.className='collection-void-journal-row';
            const title=document.createElement('div');const name=document.createElement('strong');name.textContent=label;const value=document.createElement('strong');value.textContent=this.money(record.amount);title.append(name,value);
            const note=document.createElement('p');note.textContent=detail;line.append(title,note);journal.append(line);
        });
        this.text('collection-void-variance',`Dr: ${this.money(record.amount)} | Cr: ${this.money(record.amount)} · Variance: ${this.money(0)}`);
        this.text('collection-void-date',record.date);
        const month=Number(record.date?.slice(5,7));
        this.text('collection-void-tax-period',month?`${record.date.slice(0,4)}-Q${Math.ceil(month/3)}`:'Not recorded');
        this.text('collection-void-customer',record.customer);
        this.text('collection-void-bank',record.bank);
        this.text('collection-void-method',`${record.method} · Reference: ${record.reference || 'Not recorded'}`);
        this.text('collection-void-annotation',`Collection ${record.number}${record.reference?` / ${record.reference}`:''} is marked cancelled in this local preview. Audit history is retained. ${record.notes || ''}`);
        this.text('collection-void-restore-message',record.origin==='cash-sale'?'Restore this cash receipt as posted? This applies its amount to the invoice again, if the unpaid balance permits.':'Restore this collection as a draft? Review and post it to apply the payment again.');
    }
    receipt(){
        const safe=value=>String(value ?? '').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        const record=this.record;
        return `<article class="billing-statement collection-voucher"><header class="statement-heading"><div><h1>${safe(this.company.company_name)}</h1><p>TIN: ${safe(window.SheetMasters.formatTin(this.company.tin,this.company.branch_code) || 'Not provided')}</p></div><div class="statement-right"><h2>Collection Voucher</h2><strong>${safe(record.number)}</strong><p>${safe(record.status.toUpperCase())} · LOCAL PREVIEW</p></div></header><section class="statement-bill-to"><div><small>RECEIVED FROM</small><h3>${safe(record.customer)}</h3><p>Source invoice: ${safe(record.invoice)}</p></div><div><p>Date: ${safe(record.date)}</p><p>Reference: ${safe(record.reference || '—')}</p><p>Check / Bank Ref: ${safe(record.bankReference || '—')}</p></div></section><table><thead><tr><th>Method</th><th>Deposit account</th><th>Type</th><th>Amount</th></tr></thead><tbody><tr><td>${safe(record.method)}</td><td>${safe(record.bank)}</td><td>${safe(record.type)}</td><td>${safe(this.money(record.amount))}</td></tr></tbody></table><section class="mt-6"><h3>Particulars</h3><p class="statement-notes">${safe(record.notes || '—')}</p></section><div class="statement-signatures"><span>Prepared by</span><span>Approved by</span><span>Received by</span></div><footer>Local demo voucher. No real payment or official tax receipt is generated.</footer></article>`;
    }
};
