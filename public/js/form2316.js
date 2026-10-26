(() => {
    const dialog=document.getElementById('form2316-dialog');if(!dialog)return;
    const el=id=>document.getElementById('form2316-'+id),form=el('form'),fields=form.elements,company=JSON.parse(document.getElementById('tax-company').textContent),config=JSON.parse(el('config').textContent);
    let employee=null,year=null,timer=null,request=null,revision=0,cached=null,custom={signatory_name:'',signatory_title:'',signatory_tin:''};
    const defaults={signatory_name:company.signatory_name||'',signatory_title:company.signatory_title||'',signatory_tin:company.signatory_tin||''};
    function invalidate(message){revision++;clearTimeout(timer);request?.abort();request=null;el('frame').removeAttribute('srcdoc');el('frame').hidden=true;el('empty').hidden=false;el('open').hidden=true;el('open').removeAttribute('href');el('download').disabled=true;el('refresh').disabled=false;el('status').textContent=message;if(cached)URL.revokeObjectURL(cached.url);cached=null;}
    function syncDefault(){const use=fields.use_default_signatory.checked;for(const key of Object.keys(defaults)){if(use)custom[key]=fields[key].value;fields[key].value=use?defaults[key]:custom[key];fields[key].readOnly=use;}}
    function syncPrevious(){el('previous').hidden=!fields.has_previous.checked;for(const name of ['previous_name','previous_tin','previous_address'])fields[name].required=fields.has_previous.checked;}
    function details(){const payload={...window.AlphalistData.certificatePayload(employee,year)};for(const name of ['signatory_name','signatory_title','signatory_tin','date_signed','employee_name','employee_rdo','employee_zip','employer_type','previous_name','previous_tin','previous_address','previous_zip','previous_taxable','previous_withheld','adjusted_withheld','pera_credit'])payload[name]=fields[name].value.trim();for(const name of ['use_default_signatory','has_previous','reviewed'])payload[name]=fields[name].checked;if(!payload.has_previous)for(const name of ['previous_taxable','previous_withheld'])payload[name]='0.00';return payload;}
    async function preview(reportErrors=false){
        if(!dialog.open)return;clearTimeout(timer);
        if(!form.checkValidity()){el('status').textContent='Complete the signatory and certificate details.';if(reportErrors)form.reportValidity();return;}
        const version=++revision;request?.abort();request=new AbortController();el('refresh').disabled=true;el('download').disabled=true;el('error').textContent='';el('status').textContent='Generating Form 2316 preview…';
        try{
            const payload=details(),body=JSON.stringify({...payload,preview:true}),response=await fetch(config.url.replace('__EMPLOYEE__',employee.employeeId),{method:'POST',credentials:'same-origin',signal:request.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':window.WorkspaceMasters.csrfToken},body});
            const data=await response.json();if(!response.ok)throw Error(Object.values(data.errors||{}).flat()[0]||data.message||'Unable to prepare Form 2316.');
            if(version!==revision||!dialog.open)return;
            if(!data.pdf||!data.html||!data.filename)throw Error('The server returned an incomplete preview.');
            if(cached)URL.revokeObjectURL(cached.url);
            const bytes=Uint8Array.from(atob(data.pdf),character=>character.charCodeAt(0)),url=URL.createObjectURL(new Blob([bytes],{type:'application/pdf'}));cached={url,filename:data.filename,key:JSON.stringify(payload)};
            el('frame').srcdoc=data.html;el('frame').hidden=false;el('empty').hidden=true;el('open').href=url;el('open').hidden=false;el('download').disabled=data.draft;
            const format=value=>Number(value).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
            el('status').textContent=(data.draft?'Draft review · Confirm the review checkbox to enable download. ':'Ready to download · ')+ 'Recorded withholding: ₱'+format(data.recorded_withheld)+' · Annual tax due: ₱'+format(data.tax_due)+'. Signature and substituted-filing declarations are not completed automatically.';
        }catch(error){if(error.name!=='AbortError'&&version===revision){el('error').textContent=error.message;el('status').textContent='Preview could not be generated.';}}
        finally{if(version===revision){el('refresh').disabled=false;request=null;}}
    }
    function schedule(){invalidate('Details changed · Updating preview…');el('error').textContent='';timer=setTimeout(()=>preview(),700);}
    fields.use_default_signatory.addEventListener('change',syncDefault);fields.has_previous.addEventListener('change',syncPrevious);form.addEventListener('input',schedule);form.addEventListener('change',schedule);el('refresh').addEventListener('click',()=>preview(true));
    window.Form2316Print={open(record,selectedYear){
        try{window.AlphalistData.certificatePayload(record,selectedYear);}catch(error){document.getElementById('tax-export-status').textContent=error.message;return;}
        invalidate('Complete the signatory details to preview Form 2316.');employee=record;year=selectedYear;form.reset();custom={signatory_name:'',signatory_title:'',signatory_tin:''};fields.employee_name.value=record.name;fields.adjusted_withheld.value=(record.withheld/100).toFixed(2);fields.use_default_signatory.checked=Boolean(defaults.signatory_name&&defaults.signatory_title);syncDefault();syncPrevious();el('employee').textContent=record.name+' · '+year+' · '+record.lines.length+' posted payroll row(s)';el('error').textContent='';dialog.showModal();fields.signatory_name.focus({preventScroll:true});preview();
    }};
    form.addEventListener('submit',async event=>{event.preventDefault();if(!form.reportValidity())return;if(!fields.reviewed.checked){el('error').textContent='Confirm the annual compensation review before downloading.';return;}try{if(!cached||cached.key!==JSON.stringify(details())){await preview(true);return;}const link=document.createElement('a');link.href=cached.url;link.download=cached.filename;document.body.append(link);link.click();link.remove();}catch(error){el('error').textContent=error.message;}});
    for(const button of dialog.querySelectorAll('[data-form2316-close]'))button.addEventListener('click',()=>dialog.close());dialog.addEventListener('cancel',event=>event.preventDefault());dialog.addEventListener('close',()=>invalidate(''));
})();
