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
    const api={candidates,salesError,draftActions};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root)root.SheetPosting=api;
})(typeof window==='undefined'?null:window);
