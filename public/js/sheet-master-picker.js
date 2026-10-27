(() => {
    const masters=window.SheetMasters,catalog=window.WorkspaceMasters;
    if(!masters||!catalog)return;
    const enhanced=new WeakMap(),pickers=new Set();
    let popup=null,active=null,matches=[],highlight=-1,sequence=0;
    const labels={accounts:'account',banks:'bank account',tax:'tax code',ewt:'ATC code',wvat:'ATC code',parties:'customer / supplier'};
    function kindFor(select){
        if(select.dataset.masterKind)return ({bank:'banks',tax:'tax',ewt:'ewt',wvat:'wvat'})[select.dataset.masterKind] || null;
        if(select.dataset.masterPickerKind)return select.dataset.masterPickerKind;
        return Array.from(select.options).some(option=>/^\d{4} · /.test(option.value))?'accounts':null;
    }
    function close(){const previous=active;active=null;if(previous){previous.input.setAttribute('aria-expanded','false');previous.input.removeAttribute('aria-activedescendant');previous.input.removeAttribute('aria-controls');previous.sync();}if(popup){popup.remove();popup=null;}active=null;matches=[];highlight=-1;}
    function position(){if(!active||!popup)return;const box=active.input.getBoundingClientRect(),width=Math.min(Math.max(box.width,300),520,innerWidth-20),up=innerHeight-box.bottom<200&&box.top>innerHeight-box.bottom;Object.assign(popup.style,{width:width+'px',maxHeight:Math.max(100,Math.min(320,up?box.top-18:innerHeight-box.bottom-18))+'px',left:Math.max(10,Math.min(box.left,innerWidth-width-10))+'px',top:(up?Math.max(10,box.top-popup.getBoundingClientRect().height-6):box.bottom+6)+'px'});}
    function mark(index){highlight=index;popup.querySelectorAll('[role=option]').forEach((option,i)=>{option.setAttribute('aria-selected',String(i===index));if(i===index){active.input.setAttribute('aria-activedescendant',option.id);option.scrollIntoView({block:'nearest'});}});}
    function choose(index){const current=active,option=matches[index];if(!current||!option)return;option.selected=true;close();current.select.dispatchEvent(new Event('input',{bubbles:true}));current.select.dispatchEvent(new Event('change',{bubbles:true}));current.sync();}
    function show(picker,query=''){
        if(picker.select.disabled)return;
        if(active!==picker)close();active=picker;highlight=-1;
        const term=query.trim().toLowerCase();matches=Array.from(picker.select.options).filter(option=>!option.disabled&&(!term||option.textContent.toLowerCase().includes(term)));
        if(!popup){popup=document.createElement('div');popup.className='sheet-party-options sheet-master-options';popup.id='sheet-master-options-'+(++sequence);popup.setAttribute('popover','manual');popup.setAttribute('role','listbox');popup.setAttribute('aria-label','Search '+labels[picker.kind]+'s from Masters');(picker.select.closest('dialog')||document.body).append(popup);popup.addEventListener('pointerdown',event=>event.preventDefault());}
        popup.replaceChildren();
        if(!matches.length){const empty=document.createElement('div');empty.className='sheet-party-empty';empty.textContent='No matching records in Masters.';popup.append(empty);}
        matches.forEach((option,index)=>{const item=document.createElement('div');item.className='sheet-party-option';item.id=popup.id+'-'+index;item.setAttribute('role','option');item.setAttribute('aria-selected','false');item.textContent=option.textContent;item.addEventListener('click',()=>choose(index));item.addEventListener('pointermove',()=>mark(index));popup.append(item);});
        const add=document.createElement('button');add.type='button';add.className='sheet-master-new';add.textContent='+ New '+labels[picker.kind];add.addEventListener('click',()=>{const current=active;close();openCreator(current.select,current.kind,query);});popup.append(add);
        picker.input.setAttribute('aria-controls',popup.id);picker.input.setAttribute('aria-expanded','true');if(!popup.matches(':popover-open'))popup.showPopover();position();
    }
    function enhance(select){
        if(enhanced.has(select))return;const kind=kindFor(select);if(!kind)return;
        const wrapper=document.createElement('span');wrapper.className='sheet-master-picker';const input=document.createElement('input');input.type='text';input.className='sheet-party-input sheet-master-search';input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-haspopup','listbox');input.setAttribute('aria-expanded','false');input.setAttribute('aria-label',select.getAttribute('aria-label') || 'Search '+labels[kind]);input.autocomplete='off';
        select.before(wrapper);wrapper.append(select,input);select.classList.add('sheet-master-native');
        const picker={select,input,kind,sync(){if(input.disabled!==select.disabled)input.disabled=select.disabled;if(active!==picker)input.value=select.selectedOptions[0]?.textContent || '';}};enhanced.set(select,picker);pickers.add(picker);
        const descriptor=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value');Object.defineProperty(select,'value',{configurable:true,get(){return descriptor.get.call(this);},set(value){descriptor.set.call(this,value);picker.sync();}});
        select.focus=options=>input.focus(options);select.addEventListener('invalid',event=>{event.preventDefault();input.focus();input.setCustomValidity(select.validationMessage);input.reportValidity();});input.addEventListener('input',()=>input.setCustomValidity(''));select.addEventListener('change',()=>input.setCustomValidity(''));
        picker.sync();input.addEventListener('focus',()=>show(picker));input.addEventListener('click',()=>{if(active!==picker)show(picker);});input.addEventListener('input',()=>show(picker,input.value));input.addEventListener('blur',()=>{if(active===picker)close();});
        input.addEventListener('keydown',event=>{if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();event.stopPropagation();if(active!==picker)show(picker);if(matches.length)mark(highlight<0?0:(highlight+(event.key==='ArrowDown'?1:-1)+matches.length)%matches.length);}else if(event.key==='Enter'&&active===picker){event.preventDefault();event.stopPropagation();if(matches.length)choose(highlight<0?0:highlight);}else if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();}else if(event.key==='Tab')close();},true);
        select.addEventListener('change',picker.sync);select.addEventListener('masters:sync',picker.sync);
    }
    function choices(values){return values.map(value=>Array.isArray(value)?value:[value,value]);}
    function openCreator(target,kind,query='',partyKind='Customer'){
        const dialog=document.createElement('dialog');dialog.className='sheet-master-create';dialog.setAttribute('aria-label','New '+labels[kind]);
        const form=document.createElement('form'),title=document.createElement('h2');title.textContent='New '+labels[kind];form.append(title);
        const description=document.createElement('p');description.textContent='Save to Masters and use it in this entry.';form.append(description);
        const fields={};
        function field(name,label,value='',items=null,type='text',required=true){const row=document.createElement('label');row.textContent=label;const input=items?document.createElement('select'):document.createElement('input');input.name=name;input.setAttribute('aria-label',label);if(items)for(const [id,text] of choices(items))input.add(new Option(text,id));else input.type=type;input.required=required;input.value=value;row.append(input);form.append(row);fields[name]=input;return input;}
        if(kind==='parties'){field('code','Code (optional)','',null,'text',false).placeholder='Auto-generated if blank';const partyType=field('entity_type','Party type','corporation',[['corporation','Non-individual'],['individual','Individual']]);const entityName=field('name','Registered entity name',query);entityName.maxLength=150;
            for(const [key,label] of [['last_name','Last Name'],['first_name','First Name'],['middle_name','Middle Name']])field(key,label,'',null,'text',false).maxLength=30;
            const updateType=()=>{const individual=partyType.value==='individual';entityName.disabled=individual;entityName.required=!individual;entityName.parentElement.hidden=individual;for(const key of ['last_name','first_name','middle_name']){fields[key].disabled=!individual;fields[key].required=individual&&key!=='middle_name';fields[key].parentElement.hidden=!individual;}};partyType.addEventListener('change',updateType);updateType();
            field('classification','Classification',partyKind,['Customer','Supplier','Both']);const tin=field('tin','TIN','',null,'text',true);tin.placeholder='000-000-000-00000';tin.maxLength=17;tin.pattern='[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5}';field('building','Unit / Floor / Building','',null,'text',false);field('street','Street','',null,'text',false);field('barangay','Barangay','',null,'text',false);field('city','City / Municipality');field('province','Province');const zip=field('zip_code','ZIP code','',null,'text',false);zip.maxLength=4;zip.pattern='[0-9]{4}';}
        if(kind==='accounts'){field('code','Account code (optional)','',null,'text',false).placeholder='Auto-generated if blank';field('name','Account name',query);const income=/revenue/i.test(target.getAttribute('aria-label') || ''),cash=Boolean(target.dataset.masterIds),expense=target.dataset.purchaseField==='account'||target.dataset.paymentField==='account',types=income?['Income']:cash?['Asset']:expense?['Asset','Expense']:['Asset','Liability','Equity','Income','Expense'];field('type','Account type',types[0],types);field('normal_balance','Normal balance',income?'Credit':'Debit',['Debit','Credit']);field('statement_section','Statement section',income?'Revenue':'Current Assets',['Current Assets','Non-Current Assets','Current Liabilities','Non-Current Liabilities','Capital','Retained Earnings','Revenue','Cost of Goods Sold','General and Administrative Expenses','Depreciation and Amortization','Income Tax Expense']);field('is_cash_bank','Cash / bank account',cash?'1':'0',cash?[['1','Yes']]:[['0','No'],['1','Yes']]);fields.type.addEventListener('change',()=>{const type=fields.type.value;fields.normal_balance.value=['Asset','Expense'].includes(type)?'Debit':'Credit';fields.statement_section.value=({Asset:'Current Assets',Liability:'Current Liabilities',Equity:'Capital',Income:'Revenue',Expense:'General and Administrative Expenses'})[type];});}
        if(kind==='banks'){field('name','Account name',query);field('bank','Bank','',null,'text',false);field('account_number','Account number','',null,'text',false);field('currency','Currency','PHP');const gl=field('chart_account_id','Cash / bank GL account','',window.WorkspaceAccounts.filter(account=>account.is_cash_bank&&!account.archived).map(account=>[String(account.id),account.code+' · '+account.name]));gl.dataset.masterPickerKind='accounts';gl.dataset.masterIds='true';}
        if(kind==='tax'){field('code','Tax code (optional)','',null,'text',false).placeholder='Auto-generated if blank';field('name','Tax name',query);field('kind','Tax kind','VAT',['VAT','Exempt','Zero Rated','Percentage Tax','Withholding']);field('rate','Rate (%)','0',null,'number');fields.rate.step='0.0001';field('atc','ATC (optional)','',null,'text',false);}
        if(['ewt','wvat'].includes(kind)){field('code','ATC code');field('name','ATC description',query);field('rate','Rate (%)','0',null,'number');fields.rate.step='0.0001';const note=document.createElement('p');note.textContent='Custom '+(kind==='wvat'?'VAT withholding':'income withholding')+' code. Use a code and rate appropriate to your transaction.';form.append(note);}
        const error=document.createElement('p');error.className='sheet-master-error';error.setAttribute('role','alert');form.append(error);
        const actions=document.createElement('div');actions.className='sheet-master-create-actions';const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Close';cancel.addEventListener('click',()=>dialog.close());const save=document.createElement('button');save.type='submit';save.textContent='Save and select';actions.append(cancel,save);form.append(actions);dialog.append(form);document.body.append(dialog);
        dialog.addEventListener('close',()=>{if(active&&dialog.contains(active.select))close();dialog.remove();if(target.isConnected)target.focus();});
        form.addEventListener('submit',async event=>{
            event.preventDefault();error.textContent='';save.disabled=true;const payload=Object.fromEntries(Object.entries(fields).filter(([,input])=>!input.disabled).map(([key,input])=>[key,input.value.trim()]));
            if(kind==='accounts')Object.assign(payload,{is_cash_bank:payload.is_cash_bank==='1',is_advance:false,is_control:false,relief:false,percentage_tax:false,ewt:false});
            if(['ewt','wvat'].includes(kind)){payload.code=payload.code.toUpperCase();if(!(kind==='wvat'?/^W[VB]\d{3}$/:/^W[IC]\d{3}$/).test(payload.code)){error.textContent='Use '+(kind==='wvat'?'WB or WV':'WI or WC')+' followed by three digits.';save.disabled=false;return;}payload.kind='Withholding';payload.atc=payload.code;}
            const endpoint=catalog.endpoints[kind==='ewt'||kind==='wvat'?'tax':kind];
            try{
                const response=await fetch(endpoint,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':catalog.csrfToken,...(window.CompanyLedgerDatabase?.company_id?{'X-Company-ID':String(window.CompanyLedgerDatabase.company_id)}:{})},body:JSON.stringify(payload)});if(response.status===419)throw new Error('Your session expired. Refresh the page before saving.');const data=await response.json();if(!response.ok)throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to save. Refresh your session and try again.');
                const record=data.account || data.record;remember(kind,record);refresh(kind,record);selectCreated(target,kind,record);dialog.close();
            }catch(problem){error.textContent=problem.message;}finally{save.disabled=false;}
        });dialog.showModal();form.querySelector('input')?.focus();
    }
    function remember(kind,record){if(kind==='accounts'){window.WorkspaceAccounts.push(record);window.dispatchEvent(new CustomEvent('masters:account-created',{detail:record}));}else if(kind==='parties')catalog.parties.push(record);else if(kind==='banks')catalog.banks.push(record);else {catalog.taxCodes.push(record);if(['ewt','wvat'].includes(kind))catalog.atcCodes.push({code:record.code,name:record.name,rate:Number(record.rate),category:kind==='wvat'?'VAT Withholding':'Income-tax CWT',custom:true});}}
    function refresh(kind,record){
        document.querySelectorAll('select').forEach(select=>{
            const pickerKind=kindFor(select);if(!pickerKind)return;
            if(kind==='accounts'&&pickerKind==='accounts'){
                const retained=select.value,label=select.getAttribute('aria-label') || '',restricted=/revenue/i.test(label)?record.type==='Income':select.dataset.masterIds?record.is_cash_bank:['account'].includes(select.dataset.purchaseField)||select.dataset.paymentField==='account'?['Asset','Expense'].includes(record.type):true;
                const value=select.dataset.masterIds?String(record.id):record.code+' · '+record.name;if(restricted&&!Array.from(select.options).some(option=>option.value===value))select.add(new Option(record.code+' · '+record.name,value));select.value=retained;
            }
        });masters.refreshChoices?.();pickers.forEach(picker=>{if(picker.select.isConnected)picker.sync();else pickers.delete(picker);});
    }
    function selectCreated(target,kind,record){
        if(!target.isConnected)return;
        if(kind==='parties')target.value=record.name;
        else {let value=kind==='accounts'?(target.dataset.masterIds?String(record.id):record.code+' · '+record.name):kind==='banks'?window.WorkspaceAccounts.find(account=>account.id===Number(record.chart_account_id))?.code+' · '+window.WorkspaceAccounts.find(account=>account.id===Number(record.chart_account_id))?.name:kind==='tax'?masters.taxChoices(Boolean(target.closest('#sales-draft'))||/Tax type/.test(target.getAttribute('aria-label') || '')).find(item=>item.code===record.code)?.value:((target.dataset.purchaseField||target.dataset.wtpField||/ (ewt|wvat)$/.test(target.getAttribute('aria-label') || ''))?String(Number(record.rate)):record.code+' · '+Number(record.rate)+'%');
            const option=Array.from(target.options).find(option=>['ewt','wvat'].includes(kind)?option.dataset.masterCode===record.code:option.value===value);if(option)option.selected=true;else {const option=new Option(record.name,value);if(['ewt','wvat'].includes(kind))option.dataset.masterCode=record.code;target.add(option);option.selected=true;}
        }
        target.dispatchEvent(new Event('input',{bubbles:true}));target.dispatchEvent(new Event('change',{bubbles:true}));target.dispatchEvent(new Event('masters:sync'));
    }
    masters.openCreator=openCreator;
    document.addEventListener('click',event=>{if(popup&&!popup.contains(event.target)&&event.target!==active?.input)close();});document.addEventListener('scroll',position,true);window.addEventListener('resize',position);
    const scan=()=>document.querySelectorAll('select').forEach(select=>{enhance(select);enhanced.get(select)?.sync();});
    document.addEventListener('DOMContentLoaded',()=>{scan();new MutationObserver(scan).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','readonly']});});
})();
