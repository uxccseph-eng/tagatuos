(() => {
    const catalog=window.WorkspaceMasters;
    const active=items=>(items || []).filter(item=>!item.archived);
    function formatTin(value,branch){const text=String(value || '').trim(),digits=text.replace(/\D/g,'');if(!/^(?:[0-9]{9}|[0-9]{12,14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3,5})?)$/.test(text)||![9,12,13,14].includes(digits.length))return text;return digits.slice(0,3)+'-'+digits.slice(3,6)+'-'+digits.slice(6,9)+'-'+String(branch ?? digits.slice(9)).padStart(5,'0');}
    const aliases={VAT:['12% VAT','VAT (12%)'],NONVAT:['Non-VAT','Non-VAT'],EXEMPT:['VAT Exempt','Exempt'],ZERO:['Zero-Rated (0%)','Zero-Rated']};
    function taxInfo(value){const parts=String(value || '').split('|');if(parts[0]==='TAX'&&parts.length===4){return {code:parts[1]==='NA'&&parts[2]==='Exempt'&&Number(parts[3])===0?'NR':parts[1],kind:parts[2],rate:Number(parts[3])};}if(['NR','NA','No Receipt'].includes(value))return {code:'NR',kind:'Exempt',rate:0};if(['12% VAT','VAT (12%)'].includes(value))return {code:'VAT',kind:'VAT',rate:12};if(['Zero-Rated (0%)','Zero-Rated'].includes(value))return {code:'ZERO',kind:'Zero Rated',rate:0};if(['VAT Exempt','Exempt'].includes(value))return {code:'EXEMPT',kind:'Exempt',rate:0};return {code:'NONVAT',kind:'Exempt',rate:0};}
    function taxAmounts(gross,value){const tax=taxInfo(value),vat=tax.kind==='VAT';const net=vat?Math.round(gross*100/(100+tax.rate)):gross;return {gross,net,vat:gross-net,tax};}
    function taxChoices(sales=false){return active(catalog?.taxCodes).map(item=>{const code=item.code==='NA'&&item.kind==='Exempt'&&Number(item.rate)===0?'NR':item.code,legacy=aliases[code],standard=(code==='VAT'&&item.kind==='VAT'&&Number(item.rate)===12)||(code==='ZERO'&&item.kind==='Zero Rated'&&Number(item.rate)===0)||(['NONVAT','EXEMPT'].includes(code)&&item.kind==='Exempt'&&Number(item.rate)===0);return {value:standard?legacy[sales?0:1]:['TAX',code,item.kind,Number(item.rate)].join('|'),label:code+' · '+(code==='NR'?'No Receipt':item.name)+' · '+Number(item.rate)+'%',code};}).filter((item,index,items)=>items.findIndex(other=>other.value===item.value)===index);}
    const parties=kind=>active(catalog?.parties).filter(party=>!kind||party.classification===kind||party.classification==='Both');
    const party=(name,kind)=>parties(kind).find(item=>item.name.toLowerCase()===String(name || '').trim().toLowerCase());
    function partySnapshot(record,previous){const name=record.customer || record.supplier,oldName=previous?.customer || previous?.supplier,match=party(name,record.customer?'Customer':'Supplier');const next={...record};for(const key of ['partyId','tin','customerTin','customerAddress','supplierTin','supplierAddress']){if(name===oldName&&next[key]===undefined&&previous?.[key]!==undefined)next[key]=previous[key];}if(match&&(!previous||previous.status==='draft'||name!==oldName)){next.partyId=match.id;const prefix=record.customer?'customer':'supplier';next[prefix+'Tin']=match.tin || '';next[prefix+'Address']=match.address || '';next.tin=name!==oldName?match.tin || '':record.tin || match.tin || '';}return next;}
    const searchParties=(query,kind,limit=60)=>{const term=String(query || '').trim().toLowerCase();return parties(kind).filter(item=>[item.name,item.code,item.tin].some(value=>String(value || '').toLowerCase().includes(term))).sort((a,b)=>a.name.localeCompare(b.name)).slice(0,limit);};
    const atcCodes=category=>active(catalog?.atcCodes).filter(item=>!category||item.category===category);
    const rate=value=>{const numeric=Number(value);return Number.isFinite(numeric)?numeric:Number(String(value).match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);};
    const allowedRate=(value,vat=false)=>!catalog?[0,1,2,5,10,15].includes(Number(value)):Number(value)===0||atcCodes(vat?'VAT Withholding':'Income-tax CWT').some(item=>Number(item.rate)===Number(value));
    function atcChoice(value,vat=false){
        const code=String(value || '').trim().match(/^W[ICVB][0-9]{3}(?=$|[ ·(-])/i)?.[0]?.toUpperCase();
        return atcCodes(vat?'VAT Withholding':'Income-tax CWT').find(item=>item.code===code&&!item.archived);
    }
    function withholdingError(record,sales=false){
        if(!catalog)return '';
        for(const vat of [false,true]){
            const value=sales?record[vat?'vatCwt':'cwt']:record[vat?'wvatCode':'ewtCode'],savedRate=sales?rate(value):Number(record[vat?'wvat':'ewt'] || 0);
            if(!value||/^(?:none(?: \(0%\))?|0%?)$/i.test(String(value).trim())){if(savedRate===0)continue;return 'Select the '+(vat?'VAT':'income')+' withholding ATC from Masters; a percentage alone is not an ATC.';}
            const choice=atcChoice(value,vat);
            if(!choice)return 'Select a current '+(vat?'VAT':'income')+' withholding ATC from Masters.';
            if(savedRate!==Number(choice.rate))return choice.code+' uses '+Number(choice.rate)+'% in Masters. Select the ATC again to use its saved rate.';
        }
        return '';
    }
    function applyAccountWithholding(record,previousAccount){
        const code=String(record.account || '').split(' · ')[0],account=active(window.WorkspaceAccounts).find(item=>String(item.code)===code),atc=atcCodes('Income-tax CWT').find(item=>item.code===account?.ewt_atc_code);
        if(atc&&(!record.ewtCode||previousAccount!==undefined&&previousAccount!==record.account)){record.ewtCode=atc.code;record.ewt=Number(atc.rate);}
        return record;
    }
    function partyDefaults(record,sales=false){
        const result={values:{},sourceNumber:''};
        if(!catalog || record.status && record.status!=='draft')return result;
        const name=String(record[sales?'customer':'supplier'] || '').trim().toLowerCase();
        const matches=parties(sales?'Customer':'Supplier').filter(item=>item.name.trim().toLowerCase()===name);
        if(matches.length!==1)return result;
        const selected=matches[0],digits=value=>String(value || '').replace(/\D/g,'');
        if(selected.is_petty_cash)return result;
        const rows=window.CompanyLedgerDatabase?(window.CompanyLedgerDatabase.state?.[sales?'sales':'purchases'] || []):(sales?window.KontaduryaLedger?.sales():window.KontaduryaLedger?.purchases()) || [];
        const candidates=rows.map((row,index)=>({row,index,time:Date.parse(row.postedAt || '') || Date.parse(row.date || '') || 0})).filter(({row})=>{
            if(row.status!=='posted' || record.id!==undefined && String(row.id)===String(record.id))return false;
            if(row.partyId!==undefined && row.partyId!==null && row.partyId!=='')return String(row.partyId)===String(selected.id);
            if(String(row[sales?'customer':'supplier'] || '').trim().toLowerCase()!==name)return false;
            const tin=row[sales?'customerTin':'supplierTin'] || row.tin;
            return !tin || Boolean(selected.tin) && digits(tin)===digits(selected.tin);
        }).sort((a,b)=>b.time-a.time || b.index-a.index);
        const previous=candidates[0]?.row;
        if(!previous)return result;
        const empty=value=>value===undefined || value===null || String(value).trim()==='';
        if(empty(record.account)){
            const code=String(previous.account || '').split(' · ')[0].trim();
            const account=active(window.WorkspaceAccounts).find(item=>String(item.code)===code && (sales?item.type==='Income':['Asset','Expense'].includes(item.type)));
            if(account)result.values.account=account.code+' · '+account.name;
        }
        if(empty(record.tax) && !empty(previous.tax) && (String(previous.tax).startsWith('TAX|') || Object.values(aliases).flat().includes(previous.tax))){
            const code=taxInfo(previous.tax).code;
            const tax=taxChoices(sales).find(item=>item.code===code);
            if(tax)result.values.tax=tax.value;
        }
        for(const vat of [false,true]){
            const field=sales?(vat?'vatCwt':'cwt'):(vat?'wvat':'ewt'),codeField=field+'Code';
            if(!empty(record[field]) || !sales && !empty(record[codeField]))continue;
            const atc=atcChoice(sales?previous[field]:previous[codeField],vat);
            if(atc){result.values[field]=sales?atc.code+' · '+Number(atc.rate)+'%':Number(atc.rate);if(!sales)result.values[codeField]=atc.code;}
        }
        if(Object.keys(result.values).length)result.sourceNumber=String(previous.number || 'last posted entry');
        return result;
    }
    function salesDefaults(){
        const saved=catalog?.preferences?.sales || {},accounts=active(window.WorkspaceAccounts).filter(account=>account.is_cash_bank);
        const fallback=accounts.find(account=>String(account.code)==='1020') || accounts.find(account=>catalog?.preferences?.defaultBank?.startsWith(account.code+' · ')) || accounts[0];
        const bank=saved.bankCode?accounts.find(account=>String(account.code)===String(saved.bankCode)):fallback;
        const withholding=vat=>{const code=saved[vat?'vatCwtCode':'cwtCode'] || '',item=code?atcChoice(code,vat):null;return item?item.code+' · '+Number(item.rate)+'%':code || 'None';};
        return {terms:saved.terms || 'Cash',classification:saved.classification || 'Regular',bank:bank?bank.code+' · '+bank.name:String(saved.bankCode || ''),cwt:withholding(false),vatCwt:withholding(true),cwtCode:saved.cwtCode || '',vatCwtCode:saved.vatCwtCode || '',hideCwt:Boolean(saved.hideCwt),hideVatCwt:Boolean(saved.hideVatCwt)};
    }
    function bankChoices(){const accounts=active(window.WorkspaceAccounts).filter(account=>account.is_cash_bank);return accounts.map(account=>{const banks=active(catalog?.banks).filter(bank=>bank.chart_account_id===account.id);return {value:account.code+' · '+account.name,label:account.code+' · '+account.name+(banks.length?' — '+banks.map(bank=>bank.name+' ('+bank.currency+')').join(', '):'')};});}
    function selectValue(select,value,code){select.querySelectorAll('[data-master-saved]').forEach(option=>option.remove());if(!code&&Number(value)>0&&Array.from(select.options).some(option=>option.dataset.masterCode)){const missing=new Option(Number(value)+'% · ATC not recorded',String(value));missing.dataset.masterSaved='true';select.add(missing);missing.selected=true;return;}let option=Array.from(select.options).find(item=>item.value===String(value)&&(!code||item.dataset.masterCode===code));if(!option&&code){option=new Option(code+' · '+value+'% (saved)',String(value));option.dataset.masterCode=code;option.dataset.masterSaved='true';select.add(option);}if(!option&&value!==undefined&&value!==null&&String(value)!==''){option=new Option(taxLabel(String(value))+' (saved)',String(value));option.dataset.masterSaved='true';select.add(option);}if(option){option.selected=true;}else{select.value=String(value ?? '');}}
    const taxLabel=value=>taxInfo(value).code==='NR'?'NR · No Receipt · 0%':String(value || '').startsWith('TAX|')?taxInfo(value).code+' · '+taxInfo(value).rate+'% '+taxInfo(value).kind:value;
    window.SheetMasters={formatTin,taxLabel,taxInfo,taxAmounts,taxChoices,parties,party,searchParties,partySnapshot,atcCodes,rate,allowedRate,atcChoice,withholdingError,applyAccountWithholding,partyDefaults,salesDefaults,bankChoices,selectValue,hasCatalog:Boolean(catalog)};
    if(!catalog)return;
    const seen=new WeakSet();
    function options(select,choices){const previous=select.value,previousCode=select.selectedOptions[0]?.dataset.masterCode,placeholder=Array.from(select.options).find(option=>option.value==='') || (['tax','ewt','wvat'].includes(select.dataset.masterKind)?new Option('Choose from Masters…',''):null);select.replaceChildren();if(placeholder)select.add(placeholder);for(const item of choices){const option=new Option(item.label,item.value);if(item.code)option.dataset.masterCode=item.code;select.add(option);}selectValue(select,previous,previousCode);}
    function configure(field){if(seen.has(field)||field.dataset.mastersIgnore==='true')return;seen.add(field);const tag=field.tagName,label=field.getAttribute('aria-label') || '',purchase=field.dataset.purchaseField,wtp=field.dataset.wtpField;
        if(tag==='INPUT'&&/tin/i.test([label,field.name,...Object.values(field.dataset)].join(' '))){field.placeholder='000-000-000-00000';field.maxLength=17;field.addEventListener('input',()=>{if(field.readOnly||field.disabled)return;const digits=field.value.replace(/\D/g,'');field.value=[digits.slice(0,3),digits.slice(3,6),digits.slice(6,9),digits.slice(9)].filter(Boolean).join('-');});field.addEventListener('blur',()=>{if(field.readOnly||field.disabled||(field.required&&field.value.replace(/\D/g,'').length<14))return;const formatted=formatTin(field.value);if(formatted!==field.value){field.value=formatted;field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));}});}
        if(tag==='DATALIST'){const id=field.id;let kind=/supplier|payee/.test(id)?'Supplier':/customer/.test(id)?'Customer':null;if(kind||/journal.*part/.test(id)){field.replaceChildren(...parties(kind).map(item=>new Option(item.name,item.name)));}return;}
        if(tag==='SELECT'){
            const sales=Boolean(field.closest('#sales-draft'))||/Tax type|Income withholding|VAT withholding|Deposit account/.test(label);
            const master=field.dataset.masterKind;
            if(purchase==='tax'||master==='tax'||/ tax$/.test(label)||/Tax type/.test(label)||(field.closest('#sales-draft')&&Array.from(field.options).some(option=>option.value==='12% VAT'))){field.dataset.masterKind='tax';options(field,taxChoices(sales));return;}
            const numericWithholding=purchase==='ewt'||purchase==='wvat'||/ (ewt|wvat)$/.test(label)||wtp==='ewt'||wtp==='wvat'||master==='ewt'||master==='wvat';
            const withholding=/Income withholding|VAT withholding/.test(label)||(field.closest('#sales-draft')&&Array.from(field.options).some(option=>/^[A-Z]{2}\d{3} · /.test(option.value)));
            if(numericWithholding||withholding){const vat=purchase==='wvat'||wtp==='wvat'||master==='wvat'||/ wvat$/.test(label)||/VAT withholding/.test(label)||Array.from(field.options).some(option=>/^W[VB]/.test(option.value));field.dataset.masterKind=vat?'wvat':'ewt';const choices=[{value:numericWithholding?'0':'None',label:'None (0%)'},...atcCodes(vat?'VAT Withholding':'Income-tax CWT').map(item=>({value:numericWithholding?String(item.rate):item.code+' · '+item.rate+'%',label:item.code+' · '+item.rate+'% · '+item.name,code:item.code}))];options(field,choices);return;}
            if(/Deposit account|Cash or bank account| bank$/.test(label)||['bank'].includes(purchase)||field.dataset.paymentField==='bank'||field.dataset.collectionField==='bank'||master==='bank'||(Array.from(field.options).filter(option=>option.value).length&&Array.from(field.options).filter(option=>option.value).every(option=>/^10\d{2} · /.test(option.value)))){field.dataset.masterKind='bank';options(field,bankChoices());}
        }
        if(tag==='INPUT'&&/customer|supplier/i.test(label+' '+Object.values(field.dataset).join(' '))){enhancePartyInput(field,/supplier/i.test(label+' '+Object.values(field.dataset).join(' '))?'Supplier':'Customer');}
        if(tag==='INPUT'&&/party/i.test(label)&&field.closest('#journal-entry')){enhancePartyInput(field,null);}
    }
    let partyPopup=null,partyInput=null,partyMatches=[],partyIndex=-1,partySequence=0,selectingParty=false;
    function closePartyPopup(){if(partyInput){partyInput.setAttribute('aria-expanded','false');partyInput.removeAttribute('aria-activedescendant');partyInput.removeAttribute('aria-controls');}if(partyPopup){if(partyPopup.matches(':popover-open'))partyPopup.hidePopover();partyPopup.remove();partyPopup=null;}partyInput=null;partyMatches=[];partyIndex=-1;}
    function positionPartyPopup(){if(!partyInput||!partyPopup)return;const box=partyInput.getBoundingClientRect(),width=Math.min(Math.max(box.width,260),460,window.innerWidth-20),below=window.innerHeight-box.bottom-12,above=box.top-12,up=below<180&&above>below,height=Math.min(300,Math.max(100,up?above:below));partyPopup.style.width=width+'px';partyPopup.style.maxHeight=height+'px';partyPopup.style.left=Math.max(10,Math.min(box.left,window.innerWidth-width-10))+'px';partyPopup.style.top=(up?Math.max(10,box.top-partyPopup.getBoundingClientRect().height-6):box.bottom+6)+'px';}
    function highlightParty(index){partyIndex=index;Array.from(partyPopup?.querySelectorAll('[role="option"]') || []).forEach((option,i)=>{option.setAttribute('aria-selected',String(i===index));if(i===index){partyInput.setAttribute('aria-activedescendant',option.id);option.scrollIntoView({block:'nearest'});}});}
    function selectParty(index){const field=partyInput,item=partyMatches[index];if(!field||!item||field.readOnly||field.disabled)return;field.value=item.name;closePartyPopup();selectingParty=true;try{field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));}finally{selectingParty=false;}}
    function openPartyPopup(field,query){if(field.disabled||field.readOnly)return;if(partyInput!==field)closePartyPopup();partyInput=field;partyMatches=searchParties(query,field.dataset.sheetPartyKind || null);partyIndex=-1;
        if(!partyPopup){partyPopup=document.createElement('div');partyPopup.id='sheet-party-options';partyPopup.className='sheet-party-options';partyPopup.setAttribute('popover','manual');partyPopup.setAttribute('role','listbox');partyPopup.setAttribute('aria-label',field.dataset.sheetPartyKind==='Supplier'?'Suppliers from Masters':'Customers from Masters');(field.closest('dialog') || document.body).append(partyPopup);partyPopup.addEventListener('pointerdown',event=>event.preventDefault());}
        partyPopup.replaceChildren();if(!partyMatches.length){const empty=document.createElement('div');empty.className='sheet-party-empty';empty.textContent='No matching records in Masters.';partyPopup.append(empty);}else{partyMatches.forEach((item,index)=>{const option=document.createElement('div');option.id='sheet-party-option-'+partySequence+'-'+index;option.setAttribute('role','option');option.setAttribute('aria-selected','false');option.className='sheet-party-option';const name=document.createElement('strong');name.textContent=item.name;const details=document.createElement('small');details.textContent=[item.code,item.tin?'TIN: '+item.tin:'TIN not recorded'].filter(Boolean).join(' · ');option.append(name,details);option.addEventListener('click',()=>selectParty(index));option.addEventListener('pointermove',()=>highlightParty(index));partyPopup.append(option);});}
        const create=document.createElement('button');create.type='button';create.className='sheet-master-new';create.textContent='+ New '+(field.dataset.sheetPartyKind==='Supplier'?'supplier':'customer');create.addEventListener('click',()=>{const input=field,text=input.value,kind=input.dataset.sheetPartyKind || 'Both';closePartyPopup();window.SheetMasters.openCreator?.(input,'parties',text,kind);});partyPopup.append(create);
        field.setAttribute('aria-controls',partyPopup.id);field.setAttribute('aria-expanded','true');field.removeAttribute('aria-activedescendant');if(!partyPopup.matches(':popover-open'))partyPopup.showPopover();positionPartyPopup();
    }
    window.SheetMasters.refreshChoices=()=>document.querySelectorAll('select').forEach(field=>{seen.delete(field);configure(field);field.dispatchEvent(new Event('masters:sync'));});
    function enhancePartyInput(field,kind){field.removeAttribute('list');field.classList.add('sheet-party-input');field.dataset.sheetPartyKind=kind || '';field.setAttribute('role','combobox');field.setAttribute('aria-autocomplete','list');field.setAttribute('aria-haspopup','listbox');field.setAttribute('aria-expanded','false');field.setAttribute('autocomplete','off');
        const show=()=>{partySequence++;openPartyPopup(field,party(field.value,kind)?'':field.value);};field.addEventListener('focus',show);field.addEventListener('click',()=>{if(partyInput!==field)show();});field.addEventListener('input',()=>{if(selectingParty)return;partySequence++;openPartyPopup(field,field.value);});field.addEventListener('blur',()=>{if(partyInput===field)closePartyPopup();});field.addEventListener('keydown',event=>{if(field.disabled||field.readOnly)return;if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();event.stopPropagation();if(partyInput!==field)openPartyPopup(field,'');if(partyMatches.length)highlightParty(partyIndex<0?(event.key==='ArrowDown'?0:partyMatches.length-1):(partyIndex+(event.key==='ArrowDown'?1:-1)+partyMatches.length)%partyMatches.length);}else if(event.key==='Enter'&&partyInput===field&&partyIndex>=0){event.preventDefault();event.stopPropagation();selectParty(partyIndex);}else if(event.key==='Escape'&&partyInput===field){event.preventDefault();event.stopPropagation();closePartyPopup();}else if(event.key==='Tab'&&partyInput===field){closePartyPopup();}},true);
    }
    document.addEventListener('pointerdown',event=>{if(partyInput&&event.target!==partyInput&&!partyPopup?.contains(event.target))closePartyPopup();},true);
    document.addEventListener('scroll',event=>{if(partyPopup&&!partyPopup.contains(event.target))closePartyPopup();},true);window.addEventListener?.('resize',closePartyPopup);
    function scan(root){if(root.matches?.('select,datalist,input'))configure(root);root.querySelectorAll?.('select,datalist,input').forEach(configure);}
    function init(){new MutationObserver(()=>{if(partyInput&&(!partyInput.isConnected||partyInput.readOnly||partyInput.disabled))closePartyPopup();}).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['readonly','disabled','open']});const list=document.createElement('datalist');list.id='sheet-master-parties';list.append(...parties().map(item=>new Option(item.name,item.name)));document.body.append(list);for(const [id,kind] of [['sheet-master-customers','Customer'],['sheet-master-suppliers','Supplier']]){const choices=document.createElement('datalist');choices.id=id;choices.append(...parties(kind).map(item=>new Option(item.name,item.name)));document.body.append(choices);}scan(document);new MutationObserver(changes=>{for(const change of changes)for(const node of change.addedNodes)if(node.nodeType===1&&node.tagName!=='OPTION')scan(node);}).observe(document.body,{childList:true,subtree:true});}
    document.addEventListener('DOMContentLoaded',init);
    document.addEventListener('input',event=>{const input=event.target;if(input.tagName!=='INPUT'||input.dataset.mastersIgnore==='true')return;const name=input.dataset.purchaseField || input.dataset.paymentField || input.dataset.wtpField || input.dataset.collectionField || input.getAttribute('aria-label') || '';if(!/supplier|customer/i.test(name))return;const match=party(input.value,/supplier/i.test(name)?'Supplier':'Customer');if(!match)return;const scope=input.closest('tr') || input.closest('form') || input.closest('dialog');if(!scope)return;const tin=scope.querySelector('[data-purchase-field="tin"],[data-payment-field="tin"],[data-wtp-field="tin"],[data-collection-field="tin"],input[aria-label$=" tin"]');if(tin&&!tin.readOnly&&!tin.disabled){tin.value=match.tin || '';tin.dispatchEvent(new Event('input',{bubbles:true}));}},true);
})();
