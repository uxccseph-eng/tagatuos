(() => {
    const main=document.getElementById('tax-returns-main');if(!main)return;
    const q=id=>document.getElementById(id),form=q('return-prepare-form');let preview=null,selection=null,requestId=null,busy=false,filingUrl=null;
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(value/100);
    const toggle=q('sales-menu-toggle'),backdrop=q('sales-backdrop');
    function closeMenu(){document.body.classList.remove('sales-menu-open');backdrop.hidden=true;toggle.setAttribute('aria-expanded','false');}
    toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');backdrop.hidden=!open;toggle.setAttribute('aria-expanded',String(open));});backdrop.addEventListener('click',closeMenu);document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMenu();});
    document.querySelector('[data-journal-add]')?.addEventListener('click',()=>main.querySelector('[data-prepare-return]:not(:disabled)')?.click());
    q('sales-global-search')?.addEventListener('input',event=>{const value=event.target.value.trim().toLowerCase();main.querySelectorAll('.return-card').forEach(card=>{card.hidden=!card.textContent.toLowerCase().includes(value);});});
    async function request(url,body){
        const options={credentials:'same-origin',headers:{Accept:'application/json'}};
        if(body){options.method='POST';options.headers['Content-Type']='application/json';options.headers['X-CSRF-TOKEN']=window.WorkspaceMasters?.csrfToken || document.querySelector('meta[name=csrf-token]')?.content || '';options.body=JSON.stringify(body);}
        const response=await fetch(url,options);const data=await response.json().catch(()=>({}));
        if(!response.ok){if(response.status===403)window.RoleAccessFeedback?.show(data);const error=Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Unable to complete this action. Try again.');error.status=response.status;throw error;}return data;
    }
    async function loadPreview(code,reset=true){
        if(busy)return;busy=true;
        try{
            await window.CompanyLedger?.flush();if(window.CompanyLedger?.pending())throw Error('Finish saving your pending records before preparing a return.');
            const input={form:code,year:Number(main.dataset.year),period:Number(main.dataset.period)};
            const data=await request(main.dataset.preview+'&'+new URLSearchParams(input));
            selection=input;preview=data;requestId=crypto.randomUUID();if(reset)form.reset();
            q('return-title').textContent=code+' — '+data.definition.label;q('return-period').textContent=data.period.label+' · '+data.period.from+' to '+data.period.to;
            q('return-summary').textContent=data.manual?'Source totals are not automatically calculated for this form. Enter the tax due from a separately reviewed computation.':data.source_count+' posted source record(s) · Gross / income payments '+money(data.source_totals.gross)+' · Recorded withholding '+money(data.source_totals.withheld)+' · Tax before credits '+money(data.tax_before_credits);
            const breakdown=q('return-breakdown');breakdown.replaceChildren();
            const table=document.createElement('table'),labels={basic:'Basic compensation',taxableAllowance:'Taxable allowances',deMinimis:'De minimis benefits',benefits:'Other benefits'};
            for(const [key,value] of Object.entries(data.source_totals.details)){
                const row=document.createElement('tr');
                for(const text of typeof value==='object'?[key,money(value.base),money(value.withheld)]:[labels[key] || key,money(value)]){const cell=document.createElement('td');cell.textContent=text;row.append(cell);}
                table.append(row);
            }
            breakdown.append(table);
            q('return-warnings').replaceChildren();for(const message of [...data.issues,...data.warnings]){const item=document.createElement('li');item.textContent=message;q('return-warnings').append(item);}
            q('reviewed-tax-label').hidden=!data.manual;form.elements.reviewed_tax.disabled=!data.manual;form.elements.reviewed_tax.required=data.manual;
            for(const key of ['additional_tax','credits','surcharge','interest','compromise']){form.elements[key].disabled=data.information_return;if(data.information_return)form.elements[key].value='0.00';}
            form.elements.reviewed.checked=false;q('return-error').textContent='';q('return-save').disabled=data.issues.length>0;
            if(!q('return-dialog').open)q('return-dialog').showModal();
        }catch(error){q('returns-status').textContent=error.message;if(q('return-dialog').open)q('return-error').textContent=error.message;}
        finally{busy=false;}
    }
    document.querySelectorAll('[data-prepare-return]').forEach(button=>button.addEventListener('click',()=>loadPreview(button.dataset.prepareReturn)));
    q('return-refresh').addEventListener('click',()=>selection&&loadPreview(selection.form,false));
    document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>{if(!busy)button.closest('dialog').close();}));
    document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();}));
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!preview)return;busy=true;q('return-save').disabled=true;q('return-error').textContent='';
        try{
            const input={...selection,request_id:requestId,ledger_revision:preview.ledger_revision,source_hash:preview.source_hash,review_note:form.elements.review_note.value.trim(),reviewed:form.elements.reviewed.checked,reviewed_tax:preview.manual?form.elements.reviewed_tax.value.trim():null};
            for(const key of ['additional_tax','credits','surcharge','interest','compromise'])input[key]=form.elements[key].value.trim();
            await request(main.dataset.save,input);location.hash='filing-archive';location.reload();
        }catch(error){q('return-error').textContent=error.message;if(error.status===409){q('return-error').textContent+=' Use Refresh sources, review the updated totals, and confirm again.';preview=null;}}
        finally{busy=false;q('return-save').disabled=!preview||preview.issues.length>0;}
    });
    document.querySelectorAll('[data-record-filing]').forEach(button=>button.addEventListener('click',()=>{if(busy)return;filingUrl=button.dataset.recordFiling;q('filing-form').reset();q('filing-label').textContent=button.dataset.filingLabel;q('filing-error').textContent='';q('filing-dialog').showModal();}));
    q('filing-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!filingUrl)return;busy=true;const button=event.submitter;button.disabled=true;
        try{const data=new FormData(event.currentTarget);await request(filingUrl,{reference:String(data.get('reference')).trim(),filed_on:data.get('filed_on'),confirmed:data.get('confirmed')==='on'});location.hash='filing-archive';location.reload();}
        catch(error){q('filing-error').textContent=error.message;}finally{busy=false;button.disabled=false;}
    });
})();
