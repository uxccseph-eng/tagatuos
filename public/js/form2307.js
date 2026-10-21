(function(root){
    function supplierFor(record,masters){return (masters.parties || []).find(party=>['Supplier','Both'].includes(party.classification)&&(record.partyId?String(party.id)===String(record.partyId):String(party.name).trim().toLowerCase()===String(record.supplier || '').trim().toLowerCase()));}
    function recordsFor(ledger,masters,supplierId,year,quarter){
        const start=year+'-'+String((quarter-1)*3+1).padStart(2,'0')+'-01',end=new Date(Date.UTC(year,quarter*3,0)).toISOString().slice(0,10);
        return ledger.wtpCertificates().filter(record=>record.status==='posted'&&record.date>=start&&record.date<=end&&String(supplierFor(record,masters)?.id)===String(supplierId)).flatMap(record=>{
            const amounts=ledger.wtpAmounts(record);if(amounts.ewt<=0)return [];
            const bill=ledger.purchases().find(bill=>String(bill.id)===String(record.billId)),code=String(ledger.wtpAtc?.(record) || record.ewtCode || record.atc || bill?.ewtCode || '').match(/^W[IC][0-9]{3}/)?.[0];
            if(!code)throw Error(record.number+': select its income withholding ATC in WTP before printing.');
            return [{source_id:String(record.id),supplier_id:Number(supplierId),status:record.status,transaction_date:record.date,atc_code:code,income_payment:(amounts.base/100).toFixed(2),tax_withheld:(amounts.ewt/100).toFixed(2)}];
        });
    }
    function createPreviewCache(createUrl,revokeUrl){
        let current=null,revision=0;
        return {
            get current(){return current;},
            clear(){revision++;if(current)revokeUrl(current.url);current=null;},
            async load(key,generate){
                if(current?.key===key)return current;
                const version=++revision,result=await generate();
                if(version!==revision)return null;
                if(current)revokeUrl(current.url);
                current={...result,key,url:createUrl(result.blob)};return current;
            }
        };
    }
    const api={supplierFor,recordsFor,createPreviewCache};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(!root)return;
    const dialog=document.getElementById('form2307-dialog');if(!dialog)return;
    const form=document.getElementById('form2307-form'),fields=form.elements,error=document.getElementById('form2307-error'),button=document.getElementById('form2307-download'),company=JSON.parse((document.getElementById('wtp-company') || document.getElementById('tax-company')).textContent),config=JSON.parse(document.getElementById('form2307-config').textContent);
    const frame=document.getElementById('form2307-preview-frame'),empty=document.getElementById('form2307-preview-empty'),status=document.getElementById('form2307-preview-status'),openPreview=document.getElementById('form2307-open-preview'),refresh=document.getElementById('form2307-refresh-preview'),cache=createPreviewCache(blob=>URL.createObjectURL(blob),url=>URL.revokeObjectURL(url));
    let supplier=null,busy=false,timer=null,controller=null,revision=0;
    const defaults={signatory_name:company.signatory_name || '',signatory_title:company.signatory_title || '',signatory_tin:company.signatory_tin || ''};let custom={signatory_name:'',signatory_title:'',signatory_tin:''};
    function syncDefault(){const useDefault=fields.use_default_signatory.checked;for(const key of Object.keys(defaults)){if(useDefault)custom[key]=fields[key].value;fields[key].value=useDefault?defaults[key]:custom[key];fields[key].readOnly=useDefault;}document.getElementById('form2307-default-note').textContent=useDefault?'Using Certificate Signatory from Settings > BIR.':'Enter the signatory for this PDF. Saved defaults will stay unchanged.';}
    function clearPreview(message){
        revision++;clearTimeout(timer);controller?.abort();controller=null;busy=false;frame.removeAttribute('src');frame.removeAttribute('srcdoc');frame.hidden=true;openPreview.hidden=true;openPreview.removeAttribute('href');cache.clear();button.disabled=true;refresh.disabled=false;empty.hidden=false;status.textContent=message;
    }
    function requestDetails(){
        const year=Number(fields.year.value),quarter=Number(fields.quarter.value),records=recordsFor(root.KontaduryaLedger,root.WorkspaceMasters,supplier.id,year,quarter);
        if(!records.length)throw Error('No posted income withholding certificates for this supplier in the selected quarter.');
        const url=new URL(config.url.replace('__SUPPLIER__',String(supplier.id)),location.origin);url.searchParams.set('quarter',String(quarter));url.searchParams.set('year',String(year));
        const payload={preview:true,use_default_signatory:fields.use_default_signatory.checked,signatory_name:fields.signatory_name.value.trim(),signatory_title:fields.signatory_title.value.trim(),signatory_tin:fields.signatory_tin.value.trim(),records};
        const body=JSON.stringify(payload);return {url,body,key:url.href+'|'+body};
    }
    async function fetchPdf(details,signal){
        const response=await fetch(details.url,{method:'POST',signal,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':root.WorkspaceMasters.csrfToken},body:details.body});
        if(!response.ok){const data=await response.json().catch(()=>({}));throw Error(Object.values(data.errors || {}).flat()[0] || (response.status===419||response.status===403?'Your company session expired. Sign in again before printing.':data.message || 'Unable to generate the certificate.'));}
        const result=await response.json();if(!result.pdf||!result.html||!result.filename)throw Error('The server did not return the certificate preview. Refresh and try again.');
        const binary=atob(result.pdf),bytes=Uint8Array.from(binary,character=>character.charCodeAt(0));return {filename:result.filename.replace(/[^a-zA-Z0-9._-]/g,''),blob:new Blob([bytes],{type:'application/pdf'}),html:result.html};
    }
    async function updatePreview(reportErrors=false){
        clearTimeout(timer);if(!dialog.open||busy)return null;
        if(!form.checkValidity()||!fields.signatory_name.value.trim()||!fields.signatory_title.value.trim()){status.textContent='Complete the signatory details to preview the certificate.';if(reportErrors)form.reportValidity();return null;}
        const version=revision;busy=true;refresh.disabled=true;button.disabled=true;error.textContent='';status.textContent='Generating PDF preview…';controller=new AbortController();
        try{
            const details=requestDetails(),preview=await cache.load(details.key,()=>fetchPdf(details,controller.signal));
            if(!preview||version!==revision||!dialog.open)return null;
            frame.srcdoc=preview.html;frame.hidden=false;empty.hidden=true;openPreview.href=preview.url;openPreview.hidden=false;status.textContent='Preview ready · '+fields.year.value+' · Q'+fields.quarter.value;button.disabled=false;return preview;
        }catch(problem){if(version===revision&&problem.name!=='AbortError'){error.textContent=problem.message;status.textContent='Preview could not be generated.';}return null;}
        finally{if(version===revision){busy=false;refresh.disabled=false;controller=null;}}
    }
    function schedulePreview(){
        clearPreview('Details changed · Updating preview…');error.textContent='';timer=setTimeout(()=>updatePreview(),700);
    }
    fields.use_default_signatory.addEventListener('change',syncDefault);
    form.addEventListener('input',schedulePreview);form.addEventListener('change',schedulePreview);
    refresh.addEventListener('click',()=>updatePreview(true));
    root.Form2307Print={open(record){
        if(!record || record.status!=='posted'){(document.getElementById('wtp-status') || document.getElementById('tax-export-status')).textContent='Post the certificate before printing Form 2307.';return;}
        supplier=supplierFor(record,root.WorkspaceMasters);if(!supplier){(document.getElementById('wtp-status') || document.getElementById('tax-export-status')).textContent='Link this supplier to Masters > Parties before printing.';return;}
        clearPreview('Complete the signatory details to preview the certificate.');form.reset();custom={signatory_name:'',signatory_title:'',signatory_tin:''};fields.year.value=record.date.slice(0,4);fields.quarter.value=String(Math.ceil(Number(record.date.slice(5,7))/3));fields.use_default_signatory.checked=Boolean(defaults.signatory_name&&defaults.signatory_title);syncDefault();error.textContent='';document.getElementById('form2307-supplier').textContent='BIR Form 2307 — EWT-IT · '+supplier.name;dialog.showModal();fields.signatory_name.focus({preventScroll:true});updatePreview();
    }};
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!form.reportValidity())return;
        try{
            const details=requestDetails();if(cache.current?.key!==details.key){await updatePreview(true);return;}
            const anchor=document.createElement('a');anchor.href=cache.current.url;anchor.download=cache.current.filename;document.body.append(anchor);anchor.click();anchor.remove();
        }catch(problem){error.textContent=problem.message;}
    });
    dialog.querySelectorAll('[data-form2307-close]').forEach(button=>button.addEventListener('click',()=>dialog.close()));dialog.addEventListener('cancel',event=>event.preventDefault());dialog.addEventListener('close',()=>clearPreview('Complete the signatory details to preview the certificate.'));
})(typeof window==='undefined'?null:window);
