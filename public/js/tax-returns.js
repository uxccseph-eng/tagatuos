(() => {
    const main=document.getElementById('tax-returns-main');if(!main)return;
    const q=id=>document.getElementById(id),form=q('return-prepare-form');let preview=null,selection=null,requestId=null,busy=false,filingUrl=null,wasXml=false,previousCredits='',previousTax='';
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(value/100);
    const decimal=value=>(value<0?'-':'')+Math.floor(Math.abs(value)/100)+'.'+String(Math.abs(value)%100).padStart(2,'0');
    const cents=value=>{if(!/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/.test(value))return null;const [whole,fraction='']=value.split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));};
    function syncXml(){
        const enabled=Boolean(preview?.xml_capable&&form.elements.xml_requested.checked),vat=selection?.form==='2550Q';
        if(enabled&&!wasXml){previousCredits=form.elements.credits.value;previousTax=form.elements.reviewed_tax.value;}
        if(!enabled&&wasXml){form.elements.credits.value=previousCredits;form.elements.reviewed_tax.value=previousTax;}
        wasXml=enabled;q('return-xml-vat').hidden=!enabled||!vat;q('return-xml-ewt').hidden=!enabled||vat;
        for(const key of ['xml_carryover_input','xml_vat_reviewed'])form.elements[key].disabled=!enabled||!vat;
        for(const key of ['xml_remittance_month1','xml_remittance_month2','xml_prior_overremittance','xml_overpayment_action'])form.elements[key].disabled=!enabled||vat;
        form.elements.xml_vat_reviewed.required=enabled&&vat;form.elements.credits.readOnly=enabled&&!vat;form.elements.reviewed_tax.readOnly=enabled&&vat;
        if(!enabled)return;
        if(vat){const v=preview.xml_vat_ledger.values,carry=cents(form.elements.xml_carryover_input.value);q('return-xml-vat-summary').textContent='Mapped output VAT '+money(v.outputVatSales)+' · Input VAT '+money(v.domesticInputTax+v.serviceInputTax+v.importInputTax)+' · Non-creditable VAT '+money(v.non_creditable)+'.';if(carry!==null)form.elements.reviewed_tax.value=decimal(v.outputVatSales-v.domesticInputTax-v.serviceInputTax-v.importInputTax+v.non_creditable-carry);}
        else{const values=['xml_remittance_month1','xml_remittance_month2','xml_prior_overremittance'].map(key=>cents(form.elements[key].value));if(values.every(value=>value!==null))form.elements.credits.value=decimal(values.reduce((sum,value)=>sum+value,0));}
    }
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
            wasXml=false;previousCredits='';previousTax='';q('return-xml-options').hidden=!data.xml_capable;q('return-xml-unavailable').hidden=data.xml_capable;form.elements.xml_requested.disabled=!data.xml_capable;form.elements.reviewed_tax.pattern=code==='2550Q'?'-?[0-9]+(\\.[0-9]{1,2})?':'[0-9]+(\\.[0-9]{1,2})?';
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
            q('return-warnings').replaceChildren();for(const message of [...data.issues,...data.warnings,...(data.xml_vat_ledger?.issues || [])]){const item=document.createElement('li');item.textContent=message;q('return-warnings').append(item);}
            q('reviewed-tax-label').hidden=!data.manual;form.elements.reviewed_tax.disabled=!data.manual;form.elements.reviewed_tax.required=data.manual;
            for(const key of ['additional_tax','credits','surcharge','interest','compromise']){form.elements[key].disabled=data.information_return;if(data.information_return)form.elements[key].value='0.00';}
            form.elements.reviewed.checked=false;q('return-error').textContent='';q('return-save').disabled=data.issues.length>0;
            syncXml();
            if(!q('return-dialog').open)q('return-dialog').showModal();
        }catch(error){q('returns-status').textContent=error.message;if(q('return-dialog').open)q('return-error').textContent=error.message;}
        finally{busy=false;}
    }
    document.querySelectorAll('[data-prepare-return]').forEach(button=>button.addEventListener('click',()=>loadPreview(button.dataset.prepareReturn)));
    q('return-xml-options').addEventListener('input',syncXml);q('return-xml-options').addEventListener('change',syncXml);
    document.querySelectorAll('[data-xml-download]').forEach(button=>button.addEventListener('click',async()=>{
        if(busy)return;busy=true;button.disabled=true;q('returns-status').textContent='Preparing the archived XML save file…';
        try{const response=await fetch(button.dataset.xmlDownload,{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok){const problem=await response.json().catch(()=>({}));throw Error(Object.values(problem.errors || {}).flat()[0] || problem.message || 'Unable to export XML.');}
            const filename=(response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/i)?.[1];if(!filename||!/^\d{14}-(?:2550Qv2024-\d{6}|1601EQ-\d{4})Q[1-4]\.xml$/.test(filename))throw Error('Invalid XML filename returned by the server.');
            const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);q('returns-status').textContent='XML save file downloaded. Import and validate it in eBIRForms before submission. No return has been filed by this app.';
        }catch(error){q('returns-status').textContent=error.message;}finally{busy=false;button.disabled=false;}
    }));
    q('return-refresh').addEventListener('click',()=>selection&&loadPreview(selection.form,false));
    document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>{if(!busy)button.closest('dialog').close();}));
    document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();}));
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!preview)return;busy=true;q('return-save').disabled=true;q('return-error').textContent='';
        try{
            const input={...selection,request_id:requestId,ledger_revision:preview.ledger_revision,source_hash:preview.source_hash,review_note:form.elements.review_note.value.trim(),reviewed:form.elements.reviewed.checked,reviewed_tax:preview.manual?form.elements.reviewed_tax.value.trim():null};
            for(const key of ['additional_tax','credits','surcharge','interest','compromise'])input[key]=form.elements[key].value.trim();
            input.xml_requested=Boolean(preview.xml_capable&&form.elements.xml_requested.checked);
            if(input.xml_requested){input.xml_vat_reviewed=form.elements.xml_vat_reviewed.checked;for(const key of ['xml_carryover_input','xml_remittance_month1','xml_remittance_month2','xml_prior_overremittance'])input[key]=form.elements[key].value.trim();input.xml_overpayment_action=form.elements.xml_overpayment_action.value||null;}
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
