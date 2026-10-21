(() => {
    'use strict';
    const el=id=>document.getElementById(id),main=el('lapsing-main'),form=el('ppe-form'),editor=el('ppe-editor');
    const money=value=>new Intl.NumberFormat('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100);
    const fields=['opening','additions','disposals','closing','openingDep','periodDep','removedDep','closingDep','net'];
    const headers=['Code','Asset','Category','In service','Life (months)','Opening cost','Additions','Disposals','Closing cost','Opening accumulated depreciation','Period depreciation','Accumulated depreciation removed','Closing accumulated depreciation','Net book value','Status'];
    let records=JSON.parse(el('ppe-data').textContent),editing=null,dirty=false,busy=false;
    const announce=message=>{el('ppe-message').textContent=message;};
    function selected(){
        const range=window.WorkingPeriod.current(),query=(el('ppe-search').value+' '+el('sales-global-search').value).trim().toLowerCase(),category=el('ppe-category').value;
        return records.filter(asset=>(el('ppe-archived').checked||!asset.archived)&&(!category||asset.category===category)&&[asset.code,asset.name,asset.category,asset.serial_number].join(' ').toLowerCase().includes(query)).map(asset=>({...asset,amounts:window.PpeSchedule.calculate(asset,range.from,range.to)}));
    }
    const cell=(row,text,className='')=>{const item=row.insertCell();item.textContent=text;item.className=className;return item;};
    const status=asset=>asset.archived?'Archived':asset.disposal_date&&asset.disposal_date<=window.WorkingPeriod.current().to?'Disposed':(asset.posted_net_cents ?? asset.cost_cents)===asset.residual_cents?'Fully depreciated':'Active';
    function render(){
        const category=el('ppe-category').value,categories=[...new Set(records.map(asset=>asset.category))].sort();
        el('ppe-category').replaceChildren(new Option('All categories',''),...categories.map(value=>new Option(value,value)));el('ppe-category').value=category;
        el('ppe-categories').replaceChildren(...categories.map(value=>new Option(value,value)));
        const assets=selected(),totals=Object.fromEntries(fields.map(field=>[field,0]));el('ppe-rows').replaceChildren();
        for(const asset of assets){
            const row=document.createElement('tr');cell(row,asset.code,'registry-code');cell(row,asset.name,'ppe-name');cell(row,asset.category);
            for(const value of [asset.cost_cents,asset.residual_cents,asset.posted_net_cents ?? asset.cost_cents])cell(row,money(value),'ppe-money');
            cell(row,asset.service_date);cell(row,asset.life_months);
            cell(row,asset.months_remaining ?? asset.life_months);cell(row,'Manual');cell(row,asset.capitalization_reference?'Capitalized · '+asset.capitalization_reference:'Needs capitalization');cell(row,status(asset));
            const actions=cell(row,'');actions.className='lapsing-actions';
            for(const [label,icon,callback] of [['Edit','edit',()=>open(asset)],[asset.archived?'Restore':'Archive',asset.archived?'restore':'inventory_2',()=>confirmArchive(asset)]]){
                const button=document.createElement('button');button.type='button';button.className='masters-row-action';button.setAttribute('aria-label',label+' '+asset.code);button.title=label;const symbol=document.createElement('span');symbol.className='material-symbols-outlined';symbol.setAttribute('aria-hidden','true');symbol.textContent=icon;button.append(symbol);button.addEventListener('click',callback);actions.append(button);
            }
            el('ppe-rows').append(row);
        }
        if(!assets.length){const row=document.createElement('tr'),empty=cell(row,'No assets in this view. Add an asset or adjust the working period and filters.','registry-empty');empty.colSpan=16;el('ppe-rows').append(row);}
        const total=document.createElement('tr'),label=cell(total,assets.length+' asset(s)');label.colSpan=5;for(const field of fields)cell(total,money(totals[field]),'ppe-money');const tail=cell(total,'');tail.colSpan=2;el('ppe-totals').replaceChildren(total);
        el('ppe-summary').replaceChildren();for(const [label,value] of [['Closing cost',totals.closing],['Period depreciation',totals.periodDep],['Net book value',totals.net]]){const item=document.createElement('span');item.textContent=label;const valueNode=document.createElement('strong');valueNode.textContent='₱'+money(value);item.append(valueNode);el('ppe-summary').append(item);}
    }
    function open(asset=null){
        editing=asset;dirty=false;form.querySelectorAll('[data-lock-field]').forEach(node=>node.remove());form.querySelectorAll('input,select').forEach(node=>{node.readOnly=false;node.disabled=false;});form.reset();form.querySelectorAll('[data-saved-account]').forEach(node=>node.remove());el('ppe-error').textContent='';
        el('ppe-title').textContent=asset?'Edit asset · '+asset.code:'New PPE item';
        if(asset){
            for(const [key,value] of Object.entries(asset)){const input=form.elements.namedItem(key);if(input){if(input.tagName==='SELECT'&&value&&!Array.from(input.options).some(option=>option.value===String(value))){const option=new Option('Saved account (archived)',String(value));option.dataset.savedAccount='true';input.add(option);}input.value=value??'';}}
            form.elements.cost.value=(asset.cost_cents/100).toFixed(2);form.elements.residual.value=(asset.residual_cents/100).toFixed(2);
        }else{form.elements.acquired_date.value=window.WorkingPeriod.entryDate();form.elements.service_date.value=window.WorkingPeriod.entryDate();}
        if(asset?.basis_locked){
            el('ppe-error').textContent='Depreciation has been posted. Cost, useful life, in-service dates and account tags are locked; changes require a separate adjustment.';
            for(const name of ['acquired_date','service_date','cost','residual','life_months','asset_account_id','accumulated_account_id','expense_account_id']){const field=form.elements.namedItem(name);if(field.tagName==='SELECT'){const value=document.createElement('input');value.type='hidden';value.name=name;value.value=field.value;value.dataset.lockField='true';form.append(value);field.disabled=true;}else field.readOnly=true;}
        }
        editor.showModal();form.elements.name.focus();
    }
    const guard=new window.DraftLeaveGuard({hasChanges:()=>dirty,discard:()=>{dirty=false;editor.close();}});
    form.addEventListener('input',()=>{dirty=true;});form.addEventListener('change',()=>{dirty=true;});
    document.querySelectorAll('[data-ppe-close]').forEach(button=>button.addEventListener('click',()=>{if(busy)return;guard.confirm(()=>{dirty=false;editor.close();});}));
    editor.addEventListener('cancel',event=>{event.preventDefault();if(!busy)guard.confirm(()=>{dirty=false;editor.close();});});
    async function request(url,method,data){
        const response=await fetch(url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:data?JSON.stringify(data):undefined});
        let payload;try{payload=await response.json();}catch{throw Error('Could not read the response. Refresh and try again.');}
        if(!response.ok)throw Error(response.status===422?Object.values(payload.errors||{}).flat().join(' '):response.status===419?'Your session expired. Refresh before saving.':'Unable to save this asset. Please try again.');
        return payload.record;
    }
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!form.reportValidity())return;busy=true;el('ppe-save').disabled=true;el('ppe-error').textContent='';
        const data=Object.fromEntries(new FormData(form));for(const key of Object.keys(data))data[key]=data[key].trim();
        try{const asset=await request(main.dataset.storeUrl+(editing?'/'+editing.id:''),editing?'PUT':'POST',data);records=records.filter(item=>item.id!==asset.id);records.push(asset);dirty=false;editor.close();render();announce(asset.code+' saved. No journal entry was posted.');}
        catch(error){el('ppe-error').textContent=error.message;}finally{busy=false;el('ppe-save').disabled=false;}
    });
    function confirmArchive(asset){
        const dialog=document.createElement('dialog');dialog.className='masters-dialog masters-small';dialog.setAttribute('aria-label',(asset.archived?'Restore ':'Archive ')+asset.code);
        const heading=document.createElement('h2');heading.textContent=(asset.archived?'Restore ':'Archive ')+asset.code+'?';const description=document.createElement('p');description.textContent='This changes visibility in the register and preserves the asset details. Use a disposal date to record an actual disposal.';const error=document.createElement('p');error.setAttribute('role','alert');const footer=document.createElement('footer');
        const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';cancel.addEventListener('click',()=>dialog.close());const confirm=document.createElement('button');confirm.type='button';confirm.className='masters-primary';confirm.textContent=asset.archived?'Restore':'Archive';confirm.addEventListener('click',async()=>{confirm.disabled=true;try{const saved=await request(main.dataset.storeUrl+'/'+asset.id+'/archive','POST');records=records.map(item=>item.id===saved.id?saved:item);render();dialog.close();announce(saved.code+(saved.archived?' archived.':' restored.'));}catch(problem){error.textContent=problem.message;confirm.disabled=false;}});
        footer.append(cancel,confirm);dialog.append(heading,description,error,footer);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();cancel.focus();
    }
    el('ppe-add').addEventListener('click',()=>open());document.querySelector('#sales-header button')?.addEventListener('click',()=>open());
    for(const id of ['ppe-search','sales-global-search'])el(id).addEventListener('input',render);for(const id of ['ppe-category','ppe-archived'])el(id).addEventListener('change',render);document.addEventListener('working-period:change',render);
    el('ppe-export').addEventListener('click',()=>{
        const headers=['code','name','category','cost','salvage','net_book_value','service_date','life_months','months_left','source','capitalization_reference','status','serial_number'];
        const quote=value=>'"'+String(value??'').replace(/^[=+@\-\t\r]/,"'$&").replace(/"/g,'""')+'"';
        const rows=[headers,...selected().map(asset=>[asset.code,asset.name,asset.category,(asset.cost_cents/100).toFixed(2),(asset.residual_cents/100).toFixed(2),((asset.posted_net_cents??asset.cost_cents)/100).toFixed(2),asset.service_date,asset.life_months,asset.months_remaining??asset.life_months,'Manual',asset.capitalization_reference,status(asset),asset.serial_number])];
        const url=URL.createObjectURL(new Blob(['\ufeff'+rows.map(row=>row.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'})),link=document.createElement('a');link.href=url;link.download='ppe-items.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    const toggle=el('sales-menu-toggle'),backdrop=el('sales-backdrop');toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');toggle.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;});backdrop.addEventListener('click',()=>{document.body.classList.remove('sales-menu-open');toggle.setAttribute('aria-expanded','false');backdrop.hidden=true;});
    document.querySelectorAll('[data-sales-info],[data-masters-planned]').forEach(node=>node.addEventListener('click',event=>{event.preventDefault();announce('This module is not connected yet.');}));
    render();
    const requested=new URLSearchParams(location.search).get('asset');if(requested){const asset=records.find(item=>item.code===requested);if(asset)open(asset);}
})();
