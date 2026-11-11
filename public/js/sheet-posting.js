(function(root){
    function candidates(records,selectedIds=[]){
        const visible=Array.from(records),ids=new Set(selectedIds),chosen=visible.filter(record=>ids.has(record.id));
        if(chosen.length)return chosen.filter(record=>record.status==='draft');
        const drafts=visible.filter(record=>record.status==='draft');return drafts.length===1?drafts:[];
    }
    function salesError(record){
        const date=String(record.date || '');
        if(!/^\d{4}-\d{2}-\d{2}$/.test(date)||!Number.isFinite(Date.parse(date+'T12:00:00Z'))||new Date(date+'T12:00:00Z').toISOString().slice(0,10)!==date)return 'Enter a valid date.';
        if(!record.customer?.trim())return 'Enter a customer.';
        if(!Number.isSafeInteger(record.gross)||record.gross<=0||record.gross>99999999999)return 'Enter a positive gross amount with at most two decimal places.';
        if(!record.account||!record.tax)return 'Select the revenue account and tax type.';
        if(!['Cash','On Account'].includes(record.terms)||!record.bank)return 'Select Cash or On Account and a deposit account.';
        return root?.SheetMasters?.withholdingError(record,true) || '';
    }
    function draftActions(container,record,actions){
        const group=document.createElement('div');group.className='sheet-draft-actions';group.setAttribute('role','group');group.setAttribute('aria-label','Draft actions for '+record.number);
        for(const [key,label,icon] of [['save','Save draft','save'],['post','Post',''],['delete','Delete','delete'],['more','Options for','more_vert']]){
            if(!actions[key])continue;
            const button=document.createElement('button');button.type='button';button.className='sheet-draft-action sheet-draft-'+key;button.title=key==='more'?'More options':label;button.setAttribute('aria-label',label+' '+record.number);
            if(icon){const symbol=document.createElement('span');symbol.className='material-symbols-outlined';symbol.setAttribute('aria-hidden','true');symbol.textContent=icon;button.append(symbol);}else button.textContent=label;
            if(key==='more'){button.setAttribute('aria-haspopup','menu');button.setAttribute('aria-expanded','false');}
            button.addEventListener('click',event=>{event.stopPropagation();actions[key](event);});group.append(button);
        }
        container.replaceChildren(group);return group;
    }
    function watchPopup({dialog,current,latest,isEditing,refresh}) {
        const update=()=>{
            if(!dialog.open)return;
            const previous=current();if(!previous)return;
            const saved=latest(previous.id);if(!saved)return;
            if(isEditing()&&!(previous.status==='draft'&&saved.status==='posted'))return;
            const body=dialog.querySelector?.('.cwt-entry-body, .overflow-y-auto');
            const scrollTop=body?.scrollTop;
            refresh(saved);
            if(body&&scrollTop!==undefined)body.scrollTop=scrollTop;
        };
        root?.addEventListener('company-ledger:saved',update);
        return update;
    }
    function horizontalNavigation(event) {
        if(event.defaultPrevented||event.isComposing||event.ctrlKey||event.metaKey||event.shiftKey||!['ArrowLeft','ArrowRight'].includes(event.key))return false;
        const field=event.target;
        if(!field?.matches?.('input,select,textarea')||field.disabled||field.readOnly||field.matches('input[type="checkbox"],input[type="radio"],input[type="file"],input[type="hidden"],input[type="button"],input[type="submit"]'))return false;
        const row=field.closest('tr');if(!row)return false;
        if(!event.altKey&&field.tagName!=='SELECT'){
            const start=field.selectionStart,end=field.selectionEnd,length=field.value.length;
            if(start===null||end===null)return false;
            const wholeValue=start===0&&end===length&&length>0;
            if(!wholeValue&&(start!==end||(event.key==='ArrowLeft'?start!==0:end!==length)))return false;
        }
        const fields=Array.from(row.querySelectorAll('input,select,textarea')).filter(candidate=>candidate.closest('tr')===row&&!candidate.disabled&&!candidate.readOnly&&!candidate.matches('input[type="checkbox"],input[type="radio"],input[type="file"],input[type="hidden"],input[type="button"],input[type="submit"]')&&!candidate.closest('[hidden],[aria-hidden="true"]')&&candidate.getClientRects().length>0);
        const index=fields.indexOf(field),next=fields[index+(event.key==='ArrowRight'?1:-1)];
        if(index<0||!next){if(event.altKey)event.preventDefault();return false;}
        event.preventDefault();event.stopPropagation();
        next.focus({preventScroll:true});
        if(['INPUT','TEXTAREA'].includes(next.tagName)&&next.selectionStart!==null)next.select();
        next.scrollIntoView({block:'nearest',inline:'nearest'});
        return true;
    }
    const api={candidates,salesError,draftActions,watchPopup,horizontalNavigation};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root){root.SheetPosting=api;root.document?.addEventListener('keydown',horizontalNavigation);}
})(typeof window==='undefined'?null:window);
