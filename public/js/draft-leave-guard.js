document.addEventListener('click',event=>{
    const dialog=event.target;
    if(!(dialog instanceof HTMLDialogElement)||!dialog.open)return;
    const bounds=dialog.getBoundingClientRect();
    if(event.clientX>=bounds.left&&event.clientX<=bounds.right&&event.clientY>=bounds.top&&event.clientY<=bounds.bottom)return;
    event.preventDefault();event.stopImmediatePropagation();
},true);

window.DraftLeaveGuard = class {
    static continueDraft(records,continueEntry) {
        const draft=Array.from(records).find(record=>record.status==='draft');
        if(draft)continueEntry(draft);
        return draft;
    }
    static hasDraftDetails(record) {
        if(!record||record.status!=='draft')return false;
        return Boolean(record.draftTouched)||['customer','supplier','reference','memo','notes','description','particulars','certificate'].some(key=>String(record[key] || '').trim())||['gross','amount','taxBase'].some(key=>Number(record[key])>0)||Boolean(record.saleId||record.billId)||(record.lines || []).some(line=>line.account||line.party||line.remarks||line.debit||line.credit)||(record.attachments || []).length>0;
    }
    static confirmDelete(action,number='this new entry',options={}) {
        const dialog=document.createElement('dialog');
        dialog.className='draft-leave-dialog draft-delete-dialog';
        dialog.setAttribute('aria-label',options.title || 'Delete new entry?');
        dialog.innerHTML='<span class="material-symbols-outlined draft-delete-icon" aria-hidden="true">delete</span><h2>Delete new entry?</h2><p></p><div class="draft-leave-actions"><button type="button" data-cancel-delete><span class="material-symbols-outlined" aria-hidden="true">close</span>Cancel</button><button type="button" data-confirm-delete><span class="material-symbols-outlined" aria-hidden="true">delete</span>Delete entry</button></div>';
        dialog.querySelector('h2').textContent=options.title || 'Delete new entry?';
        dialog.querySelector('p').textContent=options.description || `Are you sure you want to delete ${number}? This draft will be removed.`;
        dialog.querySelector('[data-cancel-delete]').addEventListener('click',()=>dialog.close());
        dialog.querySelector('[data-confirm-delete]').addEventListener('click',()=>{if(action()!==false)dialog.close();});
        dialog.addEventListener('close',()=>dialog.remove());
        document.body.append(dialog);dialog.showModal();dialog.querySelector('[data-cancel-delete]').focus();
    }
    constructor({hasChanges,discard}) {
        this.hasChanges=hasChanges;
        this.discard=discard;
        this.leaving=false;
        this.dialog=document.createElement('dialog');
        this.dialog.className='draft-leave-dialog';
        this.dialog.setAttribute('aria-labelledby','draft-leave-title');
        this.dialog.setAttribute('aria-describedby','draft-leave-description');
        this.dialog.innerHTML='<span class="material-symbols-outlined draft-leave-icon" aria-hidden="true">edit_note</span><h2 id="draft-leave-title">Discard unfinished transaction?</h2><p id="draft-leave-description">You haven’t finished this draft. Discard it and leave, or stay to continue filling it out.</p><div class="draft-leave-actions"><button type="button" data-stay>Stay</button><button type="button" data-discard-leave>Discard and leave</button></div>';
        document.body.append(this.dialog);
        this.dialog.querySelector('[data-stay]').addEventListener('click',()=>this.dialog.close());
        this.dialog.querySelector('[data-discard-leave]').addEventListener('click',()=>{
            const action=this.pending;
            this.dialog.close();
            if(action)action();
        });
        this.dialog.addEventListener('close',()=>{this.pending=null;});
        document.addEventListener('click',event=>{
            const link=event.target.closest('a[href]');
            if(!link||event.defaultPrevented||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button!==0||link.target==='_blank'||link.hasAttribute('download'))return;
            const url=new URL(link.href,location.href);
            if(!['http:','https:'].includes(url.protocol)||url.origin===location.origin&&url.pathname===location.pathname&&url.search===location.search)return;
            if(!this.hasChanges())return;
            event.preventDefault();event.stopImmediatePropagation();
            this.confirm(()=>{this.discard();this.leaving=true;location.assign(url.href);});
        },true);
        window.addEventListener('beforeunload',event=>{
            if(!this.leaving&&this.hasChanges()){event.preventDefault();event.returnValue='';}
        });
    }
    confirm(action,force=false) {
        if(!force&&!this.hasChanges()){action();return;}
        this.pending=action;
        if(!this.dialog.open)this.dialog.showModal();
        this.dialog.querySelector('[data-stay]').focus();
    }
};
