(() => {
    let menu=null,trigger=null;
    function close(restore=false){if(menu){menu.remove();menu=null;}if(trigger){trigger.setAttribute('aria-expanded','false');if(restore)trigger.focus();trigger=null;}}
    window.SheetEntryActions={open(anchor,record,actions){
        const same=anchor===trigger;close();if(same)return;
        trigger=anchor;anchor.setAttribute('aria-haspopup','menu');anchor.setAttribute('aria-expanded','true');
        menu=document.createElement('div');menu.className='sheet-entry-actions';menu.setAttribute('role','menu');menu.setAttribute('aria-label','Actions for '+record.number);
        const items=[record.status==='draft'&&actions.post?['Post run','done_all',actions.post]:null,record.status==='draft'&&actions.delete?['Delete entry','delete',actions.delete]:null,record.status!=='void'&&actions.edit&&(!window.CompanyLedger||record.status==='draft'||/memo/i.test(actions.editLabel||''))?[actions.editLabel || 'Edit entry','edit',actions.edit]:null,actions.duplicate?['Duplicate transaction','content_copy',actions.duplicate]:null,actions.print?['Print voucher','print',actions.print]:null,record.status==='posted'&&actions.void?['Void transaction','block',actions.void]:null,record.status==='void'&&actions.view?['View void details','visibility',actions.view]:null,record.status==='void'&&actions.deleteVoid?['Delete entry','delete',actions.deleteVoid]:null].filter(Boolean);
        const toolbar=document.querySelector('[data-sheet-toolbar]'),sheet=({cwt:'cwtCertificates',wtp:'wtpCertificates',journal:'journalEntries'})[toolbar?.dataset.sheetToolbar]||toolbar?.dataset.sheetToolbar;
        if(toolbar?.dataset.sheetFollowUpUrl&&window.CompanyLedgerDatabase&&record.id!==undefined&&['draft','posted'].includes(record.status))items.push(['Request client details','assignment_late',async()=>{
            try{await window.CompanyLedger.flush();if(window.CompanyLedger.pending())throw Error('Finish the pending company database transfer before requesting client details.');const url=new URL(toolbar.dataset.sheetFollowUpUrl,location.href);url.searchParams.set('transaction',sheet+':'+record.id);url.searchParams.set('txn_q',record.number||'');location.assign(url.href+'#follow-up-form');}catch(error){window.SheetBulk?.announce(error.message);}
        }]);
        for(const [label,icon,run] of items){const button=document.createElement('button');button.type='button';button.setAttribute('role','menuitem');if(['block','delete'].includes(icon))button.className='sheet-entry-danger';const symbol=document.createElement('span');symbol.className='material-symbols-outlined';symbol.setAttribute('aria-hidden','true');symbol.textContent=icon;const text=document.createElement('span');text.textContent=label;button.append(symbol,text);button.addEventListener('click',event=>{event.stopPropagation();close();run();});menu.append(button);}
        document.body.append(menu);const rect=anchor.getBoundingClientRect(),bounds=menu.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(rect.right-bounds.width,innerWidth-bounds.width-8))+'px';menu.style.top=Math.max(8,rect.bottom+bounds.height+8>innerHeight?rect.top-bounds.height-4:rect.bottom+4)+'px';menu.querySelector('button')?.focus();
    }};
    document.addEventListener('click',event=>{if(menu&&!menu.contains(event.target)&&!trigger?.contains(event.target))close();});
    document.addEventListener('keydown',event=>{if(!menu)return;if(event.key==='Escape'){event.preventDefault();close(true);}else if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();const items=[...menu.querySelectorAll('button')],index=items.indexOf(document.activeElement);items[event.key==='Home'?0:event.key==='End'?items.length-1:(index+(event.key==='ArrowDown'?1:-1)+items.length)%items.length].focus();}else if(event.key==='Tab')close();});
    window.addEventListener('resize',()=>close());document.addEventListener('scroll',()=>close(),true);
})();
(() => {
    const toolbar=document.querySelector('[data-sheet-toolbar]');
    if(!toolbar)return;
    const sheet=toolbar.dataset.sheetToolbar;
    const main=toolbar.closest('main');
    const navigation=main.querySelector('.sheet-tabs');
    const activeTab=navigation?.querySelector('[aria-current="page"]');
    if(activeTab){
        const bounds=navigation.getBoundingClientRect(),activeBounds=activeTab.getBoundingClientRect();
        if(activeBounds.right>bounds.right)navigation.scrollLeft+=activeBounds.right-bounds.right+8;
        else if(activeBounds.left<bounds.left)navigation.scrollLeft-=bounds.left-activeBounds.left+8;
    }
    const select=document.getElementById(sheet==='collections'?'collection-status-filter':'sales-status-filter');
    const statusControl=document.getElementById(sheet+'-filter');
    const statusLabel=document.getElementById(sheet+'-filter-label');
    const currentStatus=()=>select?.value || ({'All statuses':'all','Drafts':'draft','Posted':'posted','Voided':'void'}[statusLabel?.textContent] || 'all');
    function setStatus(status){
        if(select){select.value=status;select.dispatchEvent(new Event('change',{bubbles:true}));}
        else for(let step=0;step<4&&currentStatus()!==status;step++)statusControl.click();
        update();
    }
    function update(){
        const method={sales:'sales',collections:'collections',cwt:'cwtCertificates',purchases:'purchases',payments:'payments',journal:'journalEntries',wtp:'wtpCertificates'}[sheet];
        const records=window.KontaduryaLedger[method]().filter(record=>record.status!=='archived'&&window.WorkingPeriod.matches(record.date));
        for(const button of toolbar.querySelectorAll('[data-sheet-status]')){
            const status=button.dataset.sheetStatus;
            const count=records.filter(record=>status==='all'||record.status===status).length;
            const text=({all:'All',draft:'Drafts',posted:'Posted'}[status])+` (${count})`;
            if(button.textContent!==text)button.textContent=text;
            const pressed=String(currentStatus()===status);
            if(button.getAttribute('aria-pressed')!==pressed)button.setAttribute('aria-pressed',pressed);
        }
        const filterSelect=toolbar.querySelector('[data-sheet-status-select]');
        if(filterSelect)filterSelect.value=currentStatus();
    }
    toolbar.querySelectorAll('[data-sheet-status]').forEach(button=>button.addEventListener('click',()=>{if(sheet!=='collections')setStatus(button.dataset.sheetStatus);}));
    const filterToggle=toolbar.querySelector('[data-sheet-filter-toggle]');
    filterToggle?.addEventListener('click',()=>{const panel=toolbar.querySelector('[data-sheet-filter-panel]');panel.hidden=!panel.hidden;filterToggle.setAttribute('aria-expanded',String(!panel.hidden));});
    toolbar.querySelector('[data-sheet-status-select]')?.addEventListener('change',event=>setStatus(event.target.value));
    const bulkToggle=toolbar.querySelector('[data-sheet-bulk-toggle]');
    const menu=toolbar.querySelector('[data-sheet-bulk-menu]');
    const closeMenu=()=>{menu.hidden=true;bulkToggle.setAttribute('aria-expanded','false');};
    bulkToggle.addEventListener('click',()=>{menu.hidden=!menu.hidden;bulkToggle.setAttribute('aria-expanded',String(!menu.hidden));});
    function selectAll(checked){const checkbox=main.querySelector('table thead input[type="checkbox"]');if(checkbox){checkbox.checked=checked;checkbox.dispatchEvent(new Event('change',{bubbles:true}));}closeMenu();}
    toolbar.querySelector('[data-sheet-select-all]').addEventListener('click',()=>selectAll(true));
    toolbar.querySelector('[data-sheet-clear-selection]').addEventListener('click',()=>selectAll(false));
    toolbar.querySelector('[data-sheet-post-selected]').addEventListener('click',()=>{closeMenu();const selected=window.SheetBulk?.selected() || [];if(!selected.some(record=>record.status==='draft')){window.SheetBulk?.announce('Select a draft row before using Post selected drafts.');return;}toolbar.querySelector('.sheet-tool-post').click();});
    toolbar.querySelectorAll('[data-sheet-bulk-action]').forEach(button=>button.addEventListener('click',()=>{
        closeMenu();const action=button.dataset.sheetBulkAction,adapter=window.SheetBulk;
        if(!adapter)return;
        const status={void:'posted','delete-drafts':'draft','delete-voids':'void'}[action],selected=adapter.selected(),eligible=selected.filter(record=>record.status===status);
        const dialog=document.createElement('dialog');dialog.className='draft-leave-dialog sheet-bulk-dialog';dialog.setAttribute('aria-label',button.textContent.trim());
        const form=document.createElement('form'),title=document.createElement('h2'),description=document.createElement('p');title.textContent=button.textContent.trim();
        description.textContent=eligible.length?`${eligible.length} selected ${status==='void'?'voided':status} transaction(s) will be affected.${selected.length>eligible.length?' Other selected statuses will be skipped.':''}`:`Select ${status==='void'?'voided':status} entries first.`;
        form.append(title,description);
        if(eligible.length){const list=document.createElement('p');list.className='sheet-bulk-numbers';list.textContent=eligible.slice(0,20).map(record=>record.number).join(', ')+(eligible.length>20?'…':'');form.append(list);}
        if(action==='delete-voids'&&eligible.length){const note=document.createElement('p');note.textContent='These transactions will be removed from the sheet. Their old numbers remain in audit history. Later transaction numbers will be adjusted. Undo requires that the old number is still available.';form.append(note);}
        const reason=document.createElement('textarea');reason.setAttribute('aria-label','Reason for voiding');reason.placeholder='Reason for voiding the selected transactions';reason.required=action==='void'&&eligible.length>0;reason.maxLength=1000;
        if(action==='void'&&eligible.length)form.append(reason);
        const error=document.createElement('p');error.setAttribute('role','alert');error.className='sheet-bulk-error';form.append(error);
        const controls=document.createElement('div');controls.className='draft-leave-actions';const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';cancel.addEventListener('click',()=>dialog.close());const confirm=document.createElement('button');confirm.type='submit';confirm.disabled=!eligible.length;confirm.textContent=action==='void'?'Void selected':action==='delete-drafts'?'Delete drafts':'Delete void transactions';controls.append(cancel,confirm);form.append(controls);dialog.append(form);
        dialog.addEventListener('close',()=>dialog.remove());form.addEventListener('submit',event=>{
            event.preventDefault();if(action==='void'&&!reason.value.trim()){reason.setCustomValidity('Enter a reason for voiding.');reason.reportValidity();return;}reason.setCustomValidity('');
            try{
                const result=window.KontaduryaLedger.bulkTransactions(sheet,eligible.map(record=>record.id),action,reason.value.trim(),toolbar.dataset.sheetBulkUser || 'Demo user');adapter.refresh();dialog.close();
                adapter.announce(`${result.count} transaction(s) ${action==='void'?'voided':'deleted'}.`);
                if(action==='delete-voids'){
                    const undo=document.createElement('button');undo.type='button';undo.className='sheet-bulk-undo';undo.textContent='Undo delete';
                    undo.addEventListener('click',()=>{try{window.KontaduryaLedger.bulkTransactions(sheet,result.ids,'undo-delete-voids');adapter.refresh();adapter.announce('Voided transactions restored.');undo.remove();}catch(problem){adapter.announce(problem.message);}});
                    const message=document.getElementById(sheet==='collections'?'collection-status':sheet==='sales'?'sales-status':sheet==='purchases'?'purchases-status':sheet==='payments'?'payments-status':sheet+'-status');message?.append(' ',undo);
                }
                update();
            }catch(problem){error.textContent=problem.message;}
        });reason.addEventListener('input',()=>reason.setCustomValidity(''));document.body.append(dialog);dialog.showModal();cancel.focus();
    }));
    document.addEventListener('click',event=>{if(!event.target.closest('.sheet-toolbar-bulk'))closeMenu();});
    toolbar.addEventListener('keydown',event=>{if(event.key==='Escape'){closeMenu();bulkToggle.focus();}});
    if(['sales','collections'].includes(sheet))toolbar.querySelectorAll('.sheet-tool-icon:not(.sheet-tool-summary)').forEach(button=>{if(sheet==='sales'&&button.dataset.salesAction==='freeze')return;button.addEventListener('click',()=>button.setAttribute('aria-pressed',String(button.getAttribute('aria-pressed')!=='true')));});
    new MutationObserver(update).observe(main,{subtree:true,childList:true,characterData:true});
    update();
})();
