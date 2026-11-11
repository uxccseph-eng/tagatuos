(() => {
    const main=document.getElementById('tax-returns-main');if(!main)return;
    const q=id=>document.getElementById(id),form=q('return-prepare-form');let preview=null,selection=null,requestId=null,busy=false,filingUrl=null,filingRevision=null,filingAnchor='filing-archive',deleteSelection=null,wasXml=false,previousCredits='',previousTax='';
    const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(value/100);
    const printDialog=q('return-print-dialog'),printFrame=q('return-print-frame'),printButton=q('return-print-pdf');
    let printRequest=0;
    printDialog.addEventListener('close',()=>{printRequest++;printFrame.srcdoc='';printFrame.hidden=true;printButton.disabled=true;});
    document.querySelectorAll('[data-print-preview]').forEach(button=>button.addEventListener('click',async()=>{
        const current=++printRequest;
        q('return-print-title').textContent=button.dataset.printLabel;
        q('return-print-status').textContent='Loading the saved preparation…';printFrame.hidden=true;printButton.disabled=true;
        if(!printDialog.open)printDialog.showModal();
        try{
            const response=await fetch(button.dataset.printPreview,{credentials:'same-origin',headers:{Accept:'text/html'},cache:'no-store'});
            if(!response.ok)throw Error('This saved preparation is unavailable or you do not have access. Close this preview and refresh the filing archive.');
            const html=await response.text();if(current!==printRequest||!printDialog.open)return;
            printFrame.onload=()=>{if(current!==printRequest||!printDialog.open)return;printButton.disabled=false;q('return-print-status').textContent='Saved preparation preview. Choose Print / Save as PDF to open your browser’s print dialog.';};
            printFrame.srcdoc=html;printFrame.hidden=false;
        }catch(error){if(current===printRequest)q('return-print-status').textContent=error.message;}
    }));
    printButton.addEventListener('click',()=>{if(!printButton.disabled){printFrame.contentWindow.focus();printFrame.contentWindow.print();}});
    const decimal=value=>(value<0?'-':'')+Math.floor(Math.abs(value)/100)+'.'+String(Math.abs(value)%100).padStart(2,'0');
    const cents=value=>{if(!/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/.test(value))return null;const [whole,fraction='']=value.split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));};
    function updateSaveReadiness(){
        const issues=preview?.issues || [],button=q('return-save');
        button.disabled=busy||!preview||issues.length>0;
        const message=busy?'Loading or saving…':!preview?'Refresh sources to review the latest records.':issues.length?'Cannot save: '+issues.join(' '):form.elements.review_note.value.trim().length<3?'Required: add a review note of at least 3 characters.':!form.elements.reviewed.checked?'Required: confirm that you reviewed the sources and computation.':'Ready to save this preparation. Saving does not file or pay the return.';
        q('return-save-status').textContent=message;
        button.title=issues.length?issues.join(' '):'';
        if(q('return-settings-link'))q('return-settings-link').hidden=issues.length===0;
    }
    function corporateInput(){
        const input={method:preview?.annual_settings?.settings?.deduction_method||'itemized'};
        for(const field of q('return-xml-corporate').querySelectorAll('[data-corporate-input]')){
            if(field.dataset.corporateInput==='incorporation_date'&&selection?.form!=='1702-RT')continue;
            input[field.dataset.corporateInput]=field.type==='checkbox'?field.checked:field.value.trim();
        }
        return input;
    }
    function renderCorporate(data){
        const root=q('corporate-inputs');root.replaceChildren();
        const saved=data.corporate_saved||{},groups=new Map();
        for(const field of q('return-xml-corporate').querySelectorAll('[data-corporate-input]')){
            if(field.type==='checkbox')field.checked=Boolean(saved[field.dataset.corporateInput]);else field.value=saved[field.dataset.corporateInput]||'';
        }
        for(const [key,definition] of Object.entries(data.corporate_inputs||{})){
            if(!groups.has(definition.group)){
                const details=document.createElement('details'),summary=document.createElement('summary'),grid=document.createElement('div');
                details.className='corporate-input-group';details.open=definition.group==='Income';summary.textContent=definition.group;grid.className='return-review-grid';details.append(summary,grid);root.append(details);groups.set(definition.group,grid);
            }
            const label=document.createElement('label'),input=document.createElement('input');label.textContent=definition.label;input.dataset.corporateInput=key;input.type='text';input.value=saved[key]??(definition.type==='text'?'':'0.00');
            if(definition.type==='text')input.maxLength=150;else{input.inputMode='decimal';input.pattern=definition.signed?'-?[0-9]{1,9}(\\.00)?':'[0-9]{1,9}(\\.00)?';input.required=true;}
            label.append(input);groups.get(definition.group).append(label);
        }
        q('corporate-method').textContent='Deduction method from year settings: '+(data.annual_settings?.settings?.deduction_method||'itemized')+'. Change year settings first if the election is incorrect.';
        q('corporate-incorporation').hidden=selection?.form!=='1702-RT';q('corporate-overpayment').hidden=selection?.form!=='1702-RT';form.elements.xml_corporate_reviewed.checked=false;
    }
    function syncCorporate(enabled){
        q('return-xml-corporate').hidden=!enabled;
        for(const field of q('return-xml-corporate').querySelectorAll('input,select'))field.disabled=!enabled;
        form.elements.xml_corporate_reviewed.required=enabled;
        const date=q('corporate-incorporation').querySelector('input');date.required=enabled&&selection.form==='1702-RT';date.disabled=!date.required;
        if(!enabled)return;
        for(const key of ['rate','mcit'])q('return-xml-corporate').querySelector('[data-corporate-input="'+key+'"]').required=true;
        try{
            const result=window.CorporateIncomeWorksheet.calculate(selection.form,corporateInput(),selection.period,preview.annual_settings?.settings?.deduction_method||'itemized',preview.corporate_inputs);
            form.elements.reviewed_tax.value=decimal(result.tax);form.elements.credits.value=decimal(result.credits);
            q('corporate-calculation').textContent='Gross income '+money(result.gross*100)+' · Taxable income / loss '+money(result.taxable*100)+' · RCIT '+money(result.rcit*100)+' · MCIT '+money(result.mcit*100)+' · Tax due '+money(result.tax)+'.';
        }catch(error){form.elements.reviewed_tax.value='';q('corporate-calculation').textContent=error.message;}
        form.elements.additional_tax.value='0.00';
    }
    function syncXml(){
        q('return-save').textContent=form.elements.amended.checked?'Save amendment':(preview?.original_id?'Update original':'Save original');
        if(form.elements.amended.checked)form.elements.xml_requested.checked=false;
        const enabled=Boolean(preview?.xml_capable&&form.elements.xml_requested.checked&&!form.elements.amended.checked),vat=selection?.form==='2550Q',eq=selection?.form==='1601-EQ',payroll=selection?.form==='1601-C',monthly=['0619-E','1601-C'].includes(selection?.form),corporate=['1702Q','1702-RT'].includes(selection?.form);
        if(enabled&&!wasXml){previousCredits=form.elements.credits.value;previousTax=form.elements.reviewed_tax.value;}
        if(!enabled&&wasXml){form.elements.credits.value=previousCredits;form.elements.reviewed_tax.value=previousTax;}
        wasXml=enabled;q('return-xml-vat').hidden=!enabled||!vat;q('return-xml-ewt').hidden=!enabled||!eq;q('return-xml-monthly').hidden=!enabled||!monthly;q('return-xml-payroll').hidden=!enabled||!payroll;
        form.elements.xml_line_of_business.disabled=!enabled||!monthly;form.elements.xml_line_of_business.required=enabled&&selection?.form==='0619-E';
        for(const field of q('return-xml-payroll').querySelectorAll('input')){field.disabled=!enabled||!payroll;field.required=enabled&&payroll&&field.name!=='xml_payroll_other_description';}
        for(const key of ['xml_carryover_input','xml_vat_reviewed'])form.elements[key].disabled=!enabled||!vat;
        for(const key of ['xml_remittance_month1','xml_remittance_month2','xml_prior_overremittance','xml_overpayment_action'])form.elements[key].disabled=!enabled||!eq;
        form.elements.xml_vat_reviewed.required=enabled&&vat;form.elements.credits.readOnly=enabled&&eq;form.elements.reviewed_tax.readOnly=enabled&&vat;
        form.elements.additional_tax.readOnly=enabled&&corporate;
        if(corporate&&enabled){form.elements.reviewed_tax.readOnly=true;form.elements.credits.readOnly=true;}
        syncCorporate(enabled&&corporate);
        if(!enabled)return;
        if(vat){const v=preview.xml_vat_ledger.values,carry=cents(form.elements.xml_carryover_input.value);q('return-xml-vat-summary').textContent='Mapped output VAT '+money(v.outputVatSales)+' · Input VAT '+money(v.domesticInputTax+v.serviceInputTax+v.importInputTax)+' · Non-creditable VAT '+money(v.non_creditable)+'.';if(carry!==null)form.elements.reviewed_tax.value=decimal(v.outputVatSales-v.domesticInputTax-v.serviceInputTax-v.importInputTax+v.non_creditable-carry);}
        else if(eq){const values=['xml_remittance_month1','xml_remittance_month2','xml_prior_overremittance'].map(key=>cents(form.elements[key].value));if(values.every(value=>value!==null))form.elements.credits.value=decimal(values.reduce((sum,value)=>sum+value,0));}
    }
    const toggle=q('sales-menu-toggle'),backdrop=q('sales-backdrop');
    function closeMenu(){document.body.classList.remove('sales-menu-open');backdrop.hidden=true;toggle.setAttribute('aria-expanded','false');}
    toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');backdrop.hidden=!open;toggle.setAttribute('aria-expanded',String(open));});backdrop.addEventListener('click',closeMenu);document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMenu();});
    document.querySelector('[data-journal-add]')?.addEventListener('click',()=>main.querySelector('[data-prepare-return]:not(:disabled)')?.click());
    q('sales-global-search')?.addEventListener('input',event=>{const value=event.target.value.trim().toLowerCase();main.querySelectorAll('.return-card').forEach(card=>{card.hidden=!card.textContent.toLowerCase().includes(value);});});
    async function request(url,body){
        const options={credentials:'same-origin',headers:{Accept:'application/json'}};
        if(body){options.method='POST';if(!(body instanceof FormData))options.headers['Content-Type']='application/json';options.headers['X-CSRF-TOKEN']=window.WorkspaceMasters?.csrfToken || document.querySelector('meta[name=csrf-token]')?.content || '';options.body=body instanceof FormData?body:JSON.stringify(body);}
        const response=await fetch(url,options);const data=await response.json().catch(()=>({}));
        if(!response.ok){if(response.status===403)window.RoleAccessFeedback?.show(data);const error=Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Unable to complete this action. Try again.');error.status=response.status;throw error;}return data;
    }
    async function loadPreview(code,reset=true){
        if(busy)return;busy=true;
        try{
            await window.CompanyLedger?.flush();if(window.CompanyLedger?.pending())throw Error('Finish saving your pending records before preparing a return.');
            const input={form:code,year:Number(main.dataset.year),period:Number(main.dataset.period)};
            const data=await request(main.dataset.preview+'&'+new URLSearchParams(input));
            selection=input;preview=data;requestId=crypto.randomUUID();
            if(reset){
                form.reset();
                const card=document.getElementById('return-'+code);
                for(const field of card?.querySelectorAll('[data-adjustment]') || []){
                    const target=form.elements[field.dataset.adjustment];if(!target)continue;
                    if(field.type==='checkbox')target.checked=field.checked;else target.value=field.value;
                }
            }
            if(reset&&data.xml_payroll_ledger){
                for(const [key,value] of Object.entries(data.xml_payroll_ledger.values)){
                    if(form.elements['xml_payroll_'+key])form.elements['xml_payroll_'+key].value=decimal(value);
                }
            }
            if(reset && data.suggested_vat && !data.original_id){
                if(data.suggested_vat.carryover !== null) form.elements.xml_carryover_input.value=decimal(data.suggested_vat.carryover);
                form.elements.credits.value=decimal(data.suggested_vat.credits);
            }
            form.elements.xml_payroll_reviewed.checked=false;
            if(reset)form.elements.xml_line_of_business.value=data.taxpayer.line_of_business || '';
            if(data.xml_payroll_ledger)q('return-xml-payroll-summary').textContent='Posted compensation '+money(data.xml_payroll_ledger.values.total_compensation)+' · Actual withholding '+money(data.xml_payroll_ledger.values.tax_withheld)+'.';
            if(reset)renderCorporate(data);
            else q('corporate-method').textContent='Deduction method from year settings: '+(data.annual_settings?.settings?.deduction_method||'itemized')+'. Change year settings first if the election is incorrect.';
            wasXml=false;previousCredits='';previousTax='';q('return-xml-options').hidden=!data.xml_capable;q('return-xml-unavailable').hidden=data.xml_capable;form.elements.xml_requested.disabled=!data.xml_capable;form.elements.reviewed_tax.pattern=code==='2550Q'?'-?[0-9]+(\\.[0-9]{1,2})?':'[0-9]+(\\.[0-9]{1,2})?';
            q('return-title').textContent=code+' — '+data.definition.label;q('return-period').textContent=data.period.label+' · '+data.period.from+' to '+data.period.to;
            q('return-summary').textContent=data.information_return&&data.definition.source==='manual'?'Reconcile the annual remittance schedule separately and record the review below. This information return creates no additional tax payable.':(data.manual||data.definition.source==='manual')?'Source totals are not automatically calculated for this form. Enter the tax due from a separately reviewed computation.':data.source_count+' posted source record(s) · Gross / income payments '+money(data.source_totals.gross)+' · Recorded withholding '+money(data.source_totals.withheld)+' · Tax before credits '+money(data.tax_before_credits);
            const breakdown=q('return-breakdown');breakdown.replaceChildren();
            const table=document.createElement('table'),labels={basic:'Basic compensation',taxableAllowance:'Taxable allowances',deMinimis:'De minimis benefits',benefits:'Other benefits'};
            for(const [key,value] of Object.entries(data.source_totals.details)){
                const row=document.createElement('tr');
                for(const text of typeof value==='object'?[key,money(value.base),money(value.withheld)]:[labels[key] || key,money(value)]){const cell=document.createElement('td');cell.textContent=text;row.append(cell);}
                table.append(row);
            }
            breakdown.append(table);
            q('return-warnings').replaceChildren();for(const message of [...data.issues,...data.warnings,...(data.xml_vat_ledger?.issues || []),...(data.xml_payroll_ledger?.issues || []),...(data.xml_payroll_ledger?.warnings || [])]){const item=document.createElement('li');item.textContent=message;q('return-warnings').append(item);}
            q('reviewed-tax-label').hidden=!data.manual;form.elements.reviewed_tax.disabled=!data.manual;form.elements.reviewed_tax.required=data.manual;
            for(const key of ['additional_tax','credits','surcharge','interest','compromise']){form.elements[key].disabled=data.information_return;if(data.information_return)form.elements[key].value='0.00';}
            form.elements.reviewed.checked=false;q('return-error').textContent='';q('return-save').disabled=data.issues.length>0;
            syncXml();
            if(!q('return-dialog').open)q('return-dialog').showModal();
        }catch(error){q('returns-status').textContent=error.message;if(q('return-dialog').open)q('return-error').textContent=error.message;}
        finally{busy=false;updateSaveReadiness();}
    }
    document.querySelectorAll('[data-prepare-return]').forEach(button=>button.addEventListener('click',()=>{const card=button.closest('.return-card');for(const input of card.querySelectorAll('[data-adjustment]')){if(!input.checkValidity()){input.closest('details').open=true;input.reportValidity();return;}}loadPreview(button.dataset.prepareReturn);}));
    form.elements.amended.addEventListener('change',syncXml);
    form.addEventListener('input',()=>{q('return-error').textContent='';updateSaveReadiness();});
    form.addEventListener('change',updateSaveReadiness);
    form.addEventListener('invalid',event=>{
        const details=event.target.closest('details');if(details)details.open=true;
        q('return-error').textContent=event.target.name==='review_note'?'Add a review note of at least 3 characters before saving.':event.target.name==='reviewed'?'Confirm that you reviewed the sources and tax computation before saving.':'Complete the highlighted required field before saving: '+(event.target.closest('label')?.textContent.trim() || event.target.name)+'.';
    },true);
    q('return-xml-options').addEventListener('input',syncXml);q('return-xml-options').addEventListener('change',syncXml);
    document.querySelectorAll('[data-xml-download]').forEach(button=>button.addEventListener('click',async()=>{
        if(busy)return;busy=true;button.disabled=true;q('returns-status').textContent='Preparing the archived XML save file…';
        try{const response=await fetch(button.dataset.xmlDownload,{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok){const problem=await response.json().catch(()=>({}));throw Error(Object.values(problem.errors || {}).flat()[0] || problem.message || 'Unable to export XML.');}
            const filename=(response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/i)?.[1];if(!filename||!/^\d{14}-(?:(?:2550Qv2024-\d{6}|1601EQ-\d{4})Q[1-4]|1702Qv2018C-\d{4}Q[1-3]|(?:0619E|1601Cv2018|1702RTv2018C)-(?:0[1-9]|1[0-2])\d{4})\.xml$/.test(filename))throw Error('Invalid XML filename returned by the server.');
            const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);q('returns-status').textContent='XML save file downloaded. Import and validate it in eBIRForms before submission. No return has been filed by this app.';
        }catch(error){q('returns-status').textContent=error.message;}finally{busy=false;button.disabled=false;}
    }));
    q('return-refresh').addEventListener('click',()=>selection&&loadPreview(selection.form,false));
    document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>{if(!busy)button.closest('dialog').close();}));
    document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();}));
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!preview)return;busy=true;q('return-save').disabled=true;q('return-error').textContent='';
        try{
            const input={...selection,request_id:requestId,ledger_revision:preview.ledger_revision,source_hash:preview.source_hash,original_id:preview.original_id,original_revision:preview.original_revision,review_note:form.elements.review_note.value.trim(),reviewed:form.elements.reviewed.checked,reviewed_tax:preview.manual?form.elements.reviewed_tax.value.trim():null};
            for(const key of ['additional_tax','credits','surcharge','interest','compromise'])input[key]=form.elements[key].value.trim();
            input.amended=form.elements.amended.checked;
            input.xml_requested=Boolean(preview.xml_capable&&form.elements.xml_requested.checked);
            if(input.xml_requested){input.xml_vat_reviewed=form.elements.xml_vat_reviewed.checked;for(const key of ['xml_carryover_input','xml_remittance_month1','xml_remittance_month2','xml_prior_overremittance'])input[key]=form.elements[key].value.trim();input.xml_overpayment_action=['1702Q','1702-RT'].includes(selection.form)?(q('return-xml-corporate').querySelector('[data-corporate-action]').value||null):(form.elements.xml_overpayment_action.value||null);if(['1702Q','1702-RT'].includes(selection.form)){input.xml_corporate=corporateInput();input.xml_corporate_reviewed=form.elements.xml_corporate_reviewed.checked;}if(['0619-E','1601-C'].includes(selection.form))input.xml_line_of_business=form.elements.xml_line_of_business.value.trim();if(selection.form==='1601-C'){input.xml_payroll_reviewed=form.elements.xml_payroll_reviewed.checked;input.xml_payroll_other_description=form.elements.xml_payroll_other_description.value.trim();for(const field of q('return-xml-payroll').querySelectorAll('input[inputmode=decimal]'))input[field.name]=field.value.trim();}}
            await request(main.dataset.save,input);location.hash='return-'+selection.form;location.reload();
        }catch(error){q('return-error').textContent=error.message;if(error.status===409){q('return-error').textContent+=' Use Refresh sources, review the updated totals, and confirm again.';preview=null;}}
        finally{busy=false;updateSaveReadiness();}
    });
    document.querySelectorAll('[data-record-filing]').forEach(button=>button.addEventListener('click',()=>{if(busy)return;filingUrl=button.dataset.recordFiling;filingAnchor=button.closest('.return-card')?.id || 'filing-archive';filingRevision=Number(button.dataset.revision);q('filing-form').reset();q('filing-label').textContent=button.dataset.filingLabel;q('filing-error').textContent='';q('filing-dialog').showModal();}));
    for(const button of document.querySelectorAll('[data-delete-preparation]'))button.addEventListener('click',()=>{
        if(busy)return;deleteSelection={url:button.dataset.deletePreparation,revision:Number(button.dataset.revision),anchor:button.closest('.return-card')?.id || 'filing-archive'};q('return-delete-label').textContent=button.dataset.deleteLabel;q('return-delete-error').textContent='';q('return-delete-dialog').showModal();
    });
    q('return-delete-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!deleteSelection)return;busy=true;const button=event.submitter;button.disabled=true;
        try{await request(deleteSelection.url,{confirmed:true,revision:deleteSelection.revision});location.hash=deleteSelection.anchor;location.reload();}
        catch(error){q('return-delete-error').textContent=error.message;}finally{busy=false;button.disabled=false;}
    });
    function reloadCard(target){location.hash=target.closest('.return-card')?.id || 'year-settings-title';location.reload();}
    for(const cardForm of document.querySelectorAll('.return-period-filing,.return-proof-upload,#return-year-settings')){
        cardForm.addEventListener('submit',async event=>{
            event.preventDefault();if(busy)return;busy=true;
            const button=event.submitter,status=cardForm.querySelector('.return-inline-status');button.disabled=true;status.textContent='Saving…';
            try{
                const data=new FormData(cardForm);let payload;
                if(cardForm.matches('.return-proof-upload')){
                    const file=data.get('file');if(!file?.size)throw Error('Choose a proof file first.');if(file.size>10*1024*1024)throw Error('Choose a file no larger than 10 MB.');
                    data.set('year',main.dataset.year);data.set('period',main.dataset.period);payload=data;
                }else{
                    payload=Object.fromEntries(data.entries());
                    if(cardForm.id==='return-year-settings'){
                        payload.year=Number(payload.year);payload.revision=Number(payload.revision);payload.mcit_start_year=payload.mcit_start_year?Number(payload.mcit_start_year):null;payload.reviewed=data.get('reviewed')==='on';
                    }else{payload.year=Number(main.dataset.year);payload.period=Number(main.dataset.period);payload.confirmed=data.get('confirmed')==='on';}
                }
                await request(cardForm.dataset.url,payload);reloadCard(cardForm);
            }catch(error){status.textContent=error.message;}finally{busy=false;button.disabled=false;}
        });
    }
    document.querySelector('[data-return-guidance]')?.addEventListener('click',event=>{event.preventDefault();q('return-guidance').open=!q('return-guidance').open;if(q('return-guidance').open)q('return-guidance').scrollIntoView({block:'nearest'});});
    if(location.hash==='#filing-archive')q('filing-archive').open=true;
    const selectedCard=location.hash.startsWith('#return-')?document.getElementById(location.hash.slice(1)):null;
    if(selectedCard?.matches('.return-card'))selectedCard.querySelector('.return-card-archive').open=true;
    q('filing-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!filingUrl)return;busy=true;const button=event.submitter;button.disabled=true;
        try{const data=new FormData(event.currentTarget);await request(filingUrl,{reference:String(data.get('reference')).trim(),filed_on:data.get('filed_on'),confirmed:data.get('confirmed')==='on',revision:filingRevision});location.hash=filingAnchor;location.reload();}
        catch(error){q('filing-error').textContent=error.message;}finally{busy=false;button.disabled=false;}
    });
})();
