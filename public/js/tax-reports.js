(() => {
    const el=id=>document.getElementById(id),main=el('books-main'),ledger=window.KontaduryaLedger,company=JSON.parse(el('tax-company').textContent),schedule=main.dataset.report,periods=window.FinancialPeriods,endMonth=['qap-ewt','alphalist-non-mwe'].includes(schedule)?12:company.accounting_period==='fiscal'?(Number(main.dataset.fiscalEnd)||12):12;
    let generatingDat=false,datProfile={...company},purchaseOverrides={};
    const datPurchases=schedule==='relief-purchases',datLabel=datPurchases?'Purchases':'Sales',datRecords=payload=>payload[datPurchases?'purchase_records':'sales_records'];
    let grouped=new URLSearchParams(location.search).get('view')!=='detailed',report,year;
    const money=value=>new Intl.NumberFormat('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100);
    const qap=schedule==='qap-ewt';let qapSchedule=new URLSearchParams(location.search).get('qap_schedule')==='2'?'2':'1';
    const relief=['relief-sales','relief-purchases'].includes(schedule),sawt=schedule==='sawt';
    let sawtTax=new URLSearchParams(location.search).get('sawt_tax')==='vat'?'vat':'income',sawtForm=new URLSearchParams(location.search).get('form') || (company.entity_type==='individual'?'1701Q':'1702Q');
    function syncSawtForm(){if(!sawt)return;const allowed=sawtTax==='vat'?['2550Q']:['1702Q','1701Q','1702','1701'];if(!allowed.includes(sawtForm))sawtForm=sawtTax==='vat'?'2550Q':company.entity_type==='individual'?'1701Q':'1702Q';for(const option of el('sawt-form').options){option.hidden=!allowed.includes(option.value);option.disabled=option.hidden;}el('sawt-form').value=sawtForm;document.querySelectorAll('[data-sawt-tax]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.sawtTax===sawtTax)));}
    const nonMwe=schedule==='alphalist-non-mwe';
    const annualReport=()=>nonMwe||annualSawt();
    const annualSawt=()=>sawt&&sawtTax==='income'&&['1701','1702'].includes(sawtForm);
    function setPeriod(value,selectedYear){try{const range=value==='year'||annualReport()?periods.fiscalYear(selectedYear,endMonth):periods.quarter(selectedYear,Number(value),endMonth);if(!window.WorkingPeriod.valid(range))throw Error('Choose a period within the supported dates.');el('tax-year').setCustomValidity('');window.WorkingPeriod.set(range);}catch(error){el('tax-year').setCustomValidity(error.message);el('tax-year').reportValidity();el('tax-year').value=year;}}
    function render(){
        syncSawtForm();
        const range=window.WorkingPeriod.current(),selection=periods.identify(range,endMonth);
        if(annualReport()&&selection.kind!=='annual'){setPeriod('year',selection.year);return;}
        year=selection.year;el('tax-year').value=year;
        for(let q=1;q<=4;q++){const dates=periods.quarter(year,q,endMonth),month=date=>new Date(date+'T12:00:00Z').toLocaleDateString('en-US',{month:'short',timeZone:'UTC'});el('tax-quarter').querySelector('[value="'+q+'"]').textContent='Q'+q+' · '+month(dates.from)+' – '+month(dates.to);}
        el('tax-quarter').value=selection.kind==='quarterly'?selection.quarter:selection.kind==='annual'?'year':'';
        for(const option of el('tax-quarter').options)option.disabled=annualReport()&&option.value!=='year';
        el('tax-prev').setAttribute('aria-label',annualReport()?'Previous year':'Previous quarter');el('tax-next').setAttribute('aria-label',annualReport()?'Next year':'Next quarter');
        if(sawt)el('sawt-layout-note').textContent='DAT follows the supplied BIR Alphalist sample layout: header, certificate details, and control totals. Validate the file before submission.';
        syncSawtForm();report=window.TaxReportData.build(ledger,schedule,range,grouped,{sawtTax,qapSchedule,masters:window.WorkspaceMasters});if(nonMwe){report=buildAlphalist();renderAlphalistCards();}el('tax-base-label').textContent=report.baseLabel;el('tax-amount-label').textContent=report.taxLabel;el('tax-base').textContent='₱'+money(report.stats.base);el('tax-amount').textContent='₱'+money(report.stats.tax);
        el('tax-review').textContent=!report.available?'Source needed':!report.stats.count?'No records':report.issues.length?'Review needed':'No missing fields';el('tax-review').classList.toggle('tax-review-warning',report.issues.length>0||!report.available);el('tax-review-summary').textContent=report.stats.count+' source records · '+report.issues.length+' review items';el('tax-note').textContent=report.note;el('tax-period').textContent=range.from+' — '+range.to+' · PHP';
        const head=document.createElement('tr');report.columns.forEach((title,index)=>{const th=document.createElement('th');th.scope='col';th.textContent=title;if(report.numeric.includes(index))th.className='tax-money';head.append(th);});if(qap||nonMwe){const th=document.createElement('th');th.scope='col';th.textContent='Actions';head.append(th);}el('tax-head').replaceChildren(head);el('tax-rows').replaceChildren();
        const query=el('sales-global-search').value.trim().toLowerCase(),shown=report.rows.filter(row=>!query||row.cells.join(' ').toLowerCase().includes(query));
        const append=(body,cells,total=false)=>{const tr=document.createElement('tr');if(total)tr.className='tax-total';cells.forEach((value,index)=>{const td=document.createElement('td');td.textContent=report.numeric.includes(index)?value===null?'Review':money(value):report.columns[index]==='Month'&&/^[0-9]{4}-[0-9]{2}$/.test(String(value))?new Date(value+'-01T12:00:00Z').toLocaleDateString('en-PH',{month:'long',year:'numeric',timeZone:'UTC'}):value || '—';if(report.numeric.includes(index))td.className='tax-money';if(sawt&&report.columns[index]==='Status'&&!total){const badge=document.createElement('span');badge.className=value==='Linked'?'sawt-linked':'sawt-unlinked';badge.textContent=value;td.replaceChildren(badge);}tr.append(td);});body.append(tr);};
        shown.forEach(row=>{append(el('tax-rows'),row.cells);if(nonMwe){const td=document.createElement('td'),button=document.createElement('button');button.type='button';button.className='qap-print-button';button.textContent='Print 2316';button.setAttribute('aria-label','Print Form 2316 for '+row.employee.name);button.addEventListener('click',()=>window.Form2316Print.open(row.employee,year));td.append(button);el('tax-rows').lastChild.append(td);}if(qap){if(qapSchedule==='1'&&!row.cells[3]){const cell=el('tax-rows').lastChild.children[3];cell.textContent='Not set in source';cell.title='Select the ATC in WTP, Purchases, or Masters → Chart of Accounts.';}const td=document.createElement('td');if(row.printRecord){const button=document.createElement('button');button.type='button';button.className='qap-print-button';button.textContent='Form 2307';button.setAttribute('aria-label','Print Form 2307 for '+row.cells[1]);button.addEventListener('click',()=>window.Form2307Print.open(row.printRecord));td.append(button);}else if(qapSchedule==='1'){const link=document.createElement('a');link.href=main.dataset.wtpUrl;link.textContent='Review source ATC';link.className='qap-print-button';td.append(link);}else td.textContent='—';el('tax-rows').lastChild.append(td);}});
        if(!shown.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=report.columns.length+(qap||nonMwe?1:0);td.className='tax-empty';if(report.emptyTitle&&!query){const title=document.createElement('strong'),description=document.createElement('p');title.textContent=report.emptyTitle;description.textContent=report.emptyDescription;td.append(title,description);}else td.textContent=!report.available?report.note:query?'No rows match your search.':'No posted records in this period.';tr.append(td);el('tax-rows').append(tr);}
        el('tax-totals').replaceChildren();if(report.rows.length){append(el('tax-totals'),report.totals,true);if(qap||nonMwe)el('tax-totals').lastChild.append(document.createElement('td'));}
        el('tax-count').textContent=shown.length+' of '+report.rows.length+' rows · Totals and exports include the full schedule.';
        el('tax-issues').replaceChildren();for(const issue of report.issues){const li=document.createElement('li');li.textContent=issue;el('tax-issues').append(li);}if(!report.issues.length){const li=document.createElement('li');li.textContent=report.available?(report.stats.count?'No missing fields detected by local checks. BIR validation has not been performed.':'No source records to review.') : report.note;el('tax-issues').append(li);}el('tax-issues-label').textContent='Review details ('+report.issues.length+')';
        document.querySelectorAll('[data-tax-view]').forEach(button=>{button.disabled=!(relief||sawt||qap);button.setAttribute('aria-pressed',String((button.dataset.taxView==='grouped')===grouped));});
        el('tax-excel').disabled=!report.available||(main.classList.contains('tax-reference-layout')&&!report.rows.length);el('tax-csv').disabled=el('tax-excel').disabled;el('tax-dat').disabled=!(relief||sawt||qap)||(qap&&qapSchedule==='2')||generatingDat||(main.classList.contains('tax-reference-layout')&&!report.rows.length);
        document.querySelectorAll('[aria-label="Tax Reports navigation"] a').forEach(link=>{const url=new URL(link.href);for(const [key,value] of Object.entries({from:range.from,to:range.to,year,view:grouped?'grouped':'detailed'}))url.searchParams.set(key,value);link.href=url.href;});
        document.querySelectorAll('[data-qap-schedule]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.qapSchedule===qapSchedule)));
        const url=new URL(location.href);if(qap)url.searchParams.set('qap_schedule',qapSchedule);url.searchParams.set('year',year);url.searchParams.set('view',grouped?'grouped':'detailed');if(sawt){url.searchParams.set('sawt_tax',sawtTax);url.searchParams.set('form',sawtForm);const monitor=new URL(el('sawt-monitor-link').href);monitor.searchParams.set('from',range.from);monitor.searchParams.set('to',range.to);monitor.searchParams.set('year',year);el('sawt-monitor-link').href=monitor.href;}history.replaceState(null,'',url);
    }
    function buildAlphalist(){
        let runs=[],problem='';
        try{runs=JSON.parse(sessionStorage.getItem('kontadurya.payroll.v1.'+el('workspace-ledger-config').dataset.key)||'[]');if(!Array.isArray(runs)||runs.some(run=>!run||typeof run!=='object'))throw Error('Invalid payroll storage.');}
        catch(error){runs=[];problem='Saved payroll could not be read. Open Payroll and review its storage warning; no records were changed.';}
        const result=window.AlphalistData.build(runs,JSON.parse(el('alphalist-employees').textContent),year);
        if(problem){result.issues.unshift(problem);result.available=false;result.emptyTitle='Payroll storage needs review';result.emptyDescription=problem;}
        return result;
    }
    function renderAlphalistCards(){
        const amount=value=>value===null?'Review':'₱'+money(value);
        for(const [id,field] of [['alpha-gross','base'],['alpha-exempt','exempt'],['alpha-taxable','taxable'],['alpha-withheld','tax']])el(id).textContent=amount(report.stats[field]);
        el('alpha-due').textContent='Tax due preview: '+amount(report.stats.due);
        el('alpha-review').textContent=report.issues.length?'Review needed':report.rows.length?'Locally checked':'No records';
        el('alpha-count').textContent=report.rows.length+' employees · '+report.issues.length+' review items';
        el('tax-quarter').closest('label').hidden=true;
        el('tax-dat').title='1604-C filing layout and annualization details are not connected.';
    }
    el('tax-quarter').addEventListener('change',event=>{if(event.target.value)setPeriod(event.target.value,year);});el('tax-year').addEventListener('input',()=>el('tax-year').setCustomValidity(''));el('tax-year').addEventListener('change',()=>setPeriod(el('tax-quarter').value || 'year',Number(el('tax-year').value)));
    for(const [id,offset] of [['tax-prev',-1],['tax-next',1]])el(id).addEventListener('click',()=>{if(annualReport()){setPeriod('year',year+offset);return;}const range=window.WorkingPeriod.current(),selection=periods.identify(range,endMonth),first=endMonth%12+1;let q=selection.quarter || Math.floor((Number(range.from.slice(5,7))-first+12)%12/3)+1,y=year;q+=offset;if(q===0){q=4;y--;}if(q===5){q=1;y++;}setPeriod(q,y);});
    document.querySelectorAll('[data-tax-view]').forEach(button=>button.addEventListener('click',()=>{grouped=button.dataset.taxView==='grouped';render();}));el('tax-audit').addEventListener('click',()=>{if(relief||sawt||qap){grouped=false;render();el('tax-export-status').textContent='Detailed source documents shown.';}else{document.querySelector('.tax-issues').open=true;}});
    document.querySelectorAll('[data-qap-schedule]').forEach(button=>button.addEventListener('click',()=>{qapSchedule=button.dataset.qapSchedule;render();}));
    document.querySelectorAll('[data-sawt-tax]').forEach(button=>button.addEventListener('click',()=>{sawtTax=button.dataset.sawtTax;render();}));el('sawt-form')?.addEventListener('change',event=>{sawtForm=event.target.value;render();});
    el('sales-global-search').placeholder='Search tax schedules…';el('sales-global-search').setAttribute('aria-label','Search tax schedules');el('sales-global-search').addEventListener('input',render);document.addEventListener('working-period:change',render);
    async function exportReport(extension){try{const range=window.WorkingPeriod.current(),title=document.querySelector('.financial-caption h2').textContent,meta=window.FinancialReportTemplate.metadata(company,title,{startDate:range.from,endDate:range.to},'income-statement');meta.lines[7]='Local review schedule · Not BIR validated';if(qap){meta.title='QAP EWT · Schedule '+qapSchedule+(qapSchedule==='2'?' · No withholding':' · Withheld');meta.lines[4]=meta.title;}if(sawt){meta.title='SAWT · '+(sawtTax==='vat'?'VAT':'Income Tax')+' · Form '+sawtForm;meta.lines[4]=meta.title;}
        if(extension==='xlsx' && ['relief-sales','relief-purchases'].includes(schedule)){
            el('tax-excel').disabled=true;
            el('tax-export-status').textContent='Preparing your Excel template…';
            try{
                const sales=window.TaxReportData.build(ledger,schedule,range,true);
                const purchaseExport=schedule==='relief-purchases';
                const rows=purchaseExport?window.TaxReportData.purchasesExcelRows(ledger,range,window.WorkspaceMasters):sales.rows.map(row=>row.cells.map((value,index)=>sales.numeric.includes(index)?value/100:String(value??'')));
                const response=await fetch(purchaseExport?main.dataset.purchasesExcelUrl:main.dataset.salesExcelUrl,{
                    method:'POST',credentials:'same-origin',
                    headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':window.WorkspaceMasters.csrfToken},
                    body:JSON.stringify({from:range.from,to:range.to,rows})
                });
                if(!response.ok){const error=await response.json().catch(()=>({}));throw Error(error.message||'Please refresh the page and try again.');}
                const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');
                link.href=url;link.download=schedule+'-'+range.from+'-to-'+range.to+'.xlsx';link.click();
                setTimeout(()=>URL.revokeObjectURL(url),1000);
                el('tax-export-status').textContent=(purchaseExport?'Purchases':'Sales')+' exported using your Excel template.';
            }finally{el('tax-excel').disabled=false;}
            return;
        }
        const rows=nonMwe?window.AlphalistData.excelRows(report):[...report.rows.map(row=>({kind:'account',cells:row.cells.map((value,index)=>report.numeric.includes(index)?value===null?'Review':value/100:value)})),{kind:'grand',cells:report.totals.map((value,index)=>report.numeric.includes(index)?value===null?'Review':value/100:value)}];const body=extension==='xlsx'?window.FinancialReportTemplate.xlsx(meta,report.columns,rows,{widths:report.columns.map((_,index)=>index===1?40:index===2?24:20),wrap:true}):window.FinancialReportTemplate.csv(report.columns,rows),url=URL.createObjectURL(new Blob([body],{type:extension==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv;charset=utf-8'})),link=document.createElement('a');link.href=url;link.download=schedule+(sawt?'-'+sawtForm:'')+'-'+range.from+'-to-'+range.to+'.'+extension;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);el('tax-export-status').textContent='Full schedule exported.';}catch(error){el('tax-export-status').textContent='Export failed. '+error.message;}}
    function setDatEntity(){
        const individual=el('dat-entity').value==='individual';
        el('dat-business-field').hidden=individual;el('dat-name').required=!individual;el('dat-name').disabled=individual;
        document.querySelectorAll('[data-dat-individual]').forEach(label=>{label.hidden=!individual;label.querySelector('input').disabled=!individual;});
        el('dat-last').required=individual;el('dat-first').required=individual;
    }
    function showDatDetails(payload){
        const values={entity:datProfile.entity_type || 'corporation',tin:datProfile.tin || '',name:payload.company_name || '',last:payload.last_name,first:payload.first_name,middle:payload.middle_name,trade:payload.trade_name,rdo:payload.rdo,street:payload.street,city:payload.city};
        for(const [key,value] of Object.entries(values))el('dat-'+key).value=value || '';
        setDatEntity();el('tax-dat-error').textContent='';
        el('tax-dat-selection').textContent=payload.from+' — '+payload.to+' · '+datRecords(payload).length+' posted '+datLabel+' records';
        el('tax-dat-heading').textContent=datLabel+' DAT details';
        el('tax-dat-dialog').classList.toggle('tax-dat-purchases',datPurchases);
        if(datPurchases)showPurchaseReview(payload);else showSalesReview(payload);
        el('tax-dat-dialog').showModal();
    }
    function masterCategoryCell(record){
        const cell=document.createElement('td'),label=window.WorkspaceMasters.reliefCategories?.[record.relief_category];
        cell.textContent=label || 'Not set in Masters';cell.classList.toggle('tax-dat-master-missing',!label);return cell;
    }
    function accountCell(record){const cell=document.createElement('td'),name=record.account_name || '';cell.textContent=record.account_code&&!name.startsWith(record.account_code+' · ')?record.account_code+' · '+name:name || 'Account missing';return cell;}
    function datPayload(){return datPurchases?window.TaxReportData.purchasesDatPayload(ledger,datProfile,window.WorkingPeriod.current(),window.WorkspaceMasters,purchaseOverrides):window.TaxReportData.salesDatPayload(ledger,datProfile,window.WorkingPeriod.current());}
    function rememberPurchaseReview(){
        document.querySelectorAll('[data-dat-purchase-field]').forEach(input=>{const key=input.dataset.purchaseKey;purchaseOverrides[key] ??= {};purchaseOverrides[key][input.dataset.datPurchaseField]=input.value;});
    }
    async function refreshDatMasters(){
        const response=await fetch(main.dataset.reliefCatalogUrl,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
        if(!response.ok)throw Error('Could not load Chart of Accounts categories. Refresh the page and try again.');
        const catalog=await response.json();window.WorkspaceMasters.reliefCategories=catalog.categories;
        for(const account of catalog.accounts){const existing=window.WorkspaceAccounts.find(item=>item.code===account.code);if(existing)Object.assign(existing,account);else window.WorkspaceAccounts.push(account);}
    }
    function showSalesReview(payload){
        el('tax-dat-sales-review').hidden=false;const body=el('tax-dat-sales-rows');body.replaceChildren();
        for(const record of payload.sales_records){const row=document.createElement('tr'),identity=document.createElement('td');identity.textContent=record.document_number+' · '+record.customer_name;row.append(identity,accountCell(record),masterCategoryCell(record));body.append(row);}
    }
    el('tax-dat-refresh-categories').addEventListener('click',async()=>{
        try{if(datPurchases)rememberPurchaseReview();await refreshDatMasters();const payload=datPayload();if(datPurchases)showPurchaseReview(payload);else showSalesReview(payload);el('tax-dat-error').textContent='';}
        catch(error){el('tax-dat-error').textContent=error.message;}
    });
    function showPurchaseReview(payload){
        el('tax-dat-purchase-review').hidden=false;
        const body=el('tax-dat-purchase-rows');body.replaceChildren();
        for(const record of payload.purchase_records){
            const row=document.createElement('tr'),identity=document.createElement('td');
            identity.textContent=record.document_number+' · '+record.supplier_name;row.append(identity,accountCell(record));
            const appendInput=(field,label,value)=>{
                const td=document.createElement('td'),input=document.createElement('input');
                input.value=value;input.dataset.purchaseKey=record.source_key;input.dataset.datPurchaseField=field;input.dataset.mastersIgnore='true';input.setAttribute('aria-label',label+' for '+record.document_number);td.append(input);row.append(td);return input;
            };
            const tin=appendInput('supplier_tin','Supplier TIN',record.supplier_tin);tin.required=true;tin.maxLength=32;
            row.append(masterCategoryCell(record));
            const vat=document.createElement('td');vat.textContent=money(Math.round(record.input_vat_amount*100));row.append(vat);
            const nonCreditable=appendInput('non_creditable_vat_amount','Non-creditable VAT',record.non_creditable_vat_amount);
            nonCreditable.type='number';nonCreditable.step='0.01';nonCreditable.min=String(Math.min(0,record.input_vat_amount));nonCreditable.max=String(Math.max(0,record.input_vat_amount));nonCreditable.required=true;
            body.append(row);
        }
    }
    el('dat-entity').addEventListener('change',setDatEntity);
    el('tax-dat-dialog').addEventListener('cancel',event=>event.preventDefault());
    for(const id of ['tax-dat-close','tax-dat-cancel'])el(id).addEventListener('click',()=>el('tax-dat-dialog').close());
    el('tax-dat-form').addEventListener('submit',event=>{
        event.preventDefault();
        datProfile={...datProfile,entity_type:el('dat-entity').value,tin:el('dat-tin').value.trim(),registered_name:el('dat-name').value.trim(),last_name:el('dat-last').value.trim(),first_name:el('dat-first').value.trim(),middle_name:el('dat-middle').value.trim(),trade_name:el('dat-trade').value.trim(),rdo:el('dat-rdo').value.trim(),dat_street:el('dat-street').value.trim(),dat_city:el('dat-city').value.trim()};
        if(datPurchases)rememberPurchaseReview();
        generateDat(event,true);
    });
    async function generateDat(event,reviewed=false){
        event.preventDefault();
        if(generatingDat || !relief)return;
        generatingDat=true;el('tax-dat').disabled=true;el('tax-dat-download').disabled=true;el('tax-dat-error').textContent='';
        el('tax-export-status').textContent='Preparing '+datLabel+' DAT…';
        try{
            await refreshDatMasters();
            const payload=datPayload();
            if(!reviewed || !payload.company_tin || !payload.street || !payload.city || !/^[0-9]{3}$/.test(payload.rdo) || (!payload.non_individual&&(!payload.first_name||!payload.last_name))){
                if(!el('tax-dat-dialog').open)showDatDetails(payload);
                el('tax-export-status').textContent='Complete the taxpayer details to generate monthly DAT files.';
                return;
            }
            const missing=datRecords(payload).find(record=>record.taxable_amount!==0&&!window.WorkspaceMasters.reliefCategories?.[record.relief_category]);
            if(missing){
                if(datPurchases)showPurchaseReview(payload);else showSalesReview(payload);
                throw Error('Set the RELIEF category for '+(missing.account_name || missing.account_code || missing.document_number)+' in Masters → Chart of Accounts, then refresh categories.');
            }
            const response=await fetch(datPurchases?main.dataset.purchasesDatUrl:main.dataset.salesDatUrl,{
                method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':window.WorkspaceMasters.csrfToken},
                body:JSON.stringify(payload)
            });
            if(!response.ok){
                const error=await response.json().catch(()=>({})),messages=Object.values(error.errors || {}).flat();
                throw Error(messages[0] || error.message || 'Refresh the page and try again.');
            }
            const disposition=response.headers.get('Content-Disposition') || '',filename=disposition.match(/filename="?([^";]+)"?/i)?.[1];
            if(!filename || !/^(?:[0-9]{9}[SP][0-9]{6}\.DAT|RELIEF-(?:Sales|Purchases)-\d{4}-\d{2}-\d{2}-to-\d{4}-\d{2}-\d{2}\.zip)$/.test(filename))throw Error('The server returned an invalid DAT filename.');
            const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');
            link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();
            setTimeout(()=>URL.revokeObjectURL(url),1000);
            el('tax-dat-dialog').close();
            const count=new Set(datRecords(payload).map(record=>record.date.slice(0,7))).size;
            el('tax-export-status').textContent=count+' monthly '+datLabel+' DAT file'+(count===1?'':'s')+' downloaded · '+datRecords(payload).length+' posted records · BIR validation not performed.';
        }catch(error){el('tax-export-status').textContent='DAT export failed. '+error.message;el('tax-dat-error').textContent=error.message;}
        finally{generatingDat=false;el('tax-dat').disabled=!relief;el('tax-dat-download').disabled=false;}
    }
    let sawtExportPayload=null;
    function syncSawtEntity(){const individual=el('sawt-entity').value==='individual';el('sawt-business-field').hidden=individual;el('sawt-name').disabled=individual;el('sawt-name').required=!individual;document.querySelectorAll('[data-sawt-individual]').forEach(label=>{label.hidden=!individual;label.querySelector('input').disabled=!individual;});el('sawt-last').required=individual;el('sawt-first').required=individual;}
    function openSawtDat(event){
        event.preventDefault();if(generatingDat)return;
        try{
            sawtExportPayload=window.TaxReportData.sawtDatPayload(ledger,company,window.WorkingPeriod.current(),{formType:sawtForm,taxKind:sawtTax,masters:window.WorkspaceMasters});
            const taxpayer=sawtExportPayload.taxpayer,values={entity:taxpayer.entity_type,tin:taxpayer.tin,name:taxpayer.registered_name,last:taxpayer.last_name,first:taxpayer.first_name,middle:taxpayer.middle_name,rdo:taxpayer.rdo};for(const [key,value] of Object.entries(values))el('sawt-'+key).value=value || '';
            syncSawtEntity();el('sawt-dat-period').textContent=sawtExportPayload.from+' — '+sawtExportPayload.to+' · Form '+sawtForm+' · Return period '+sawtExportPayload.to.slice(5,7)+'/'+sawtExportPayload.to.slice(0,4)+' · '+sawtExportPayload.records.length+' posted certificate(s)';
            el('sawt-dat-error').textContent='';const body=el('sawt-dat-rows');body.replaceChildren();
            sawtExportPayload.records.forEach((record,index)=>{
                const row=document.createElement('tr'),identity=document.createElement('td');identity.textContent=record.document_number+' · '+record.registered_name;row.append(identity);
                function input(cell,field,label,max=50){const control=document.createElement('input');control.dataset.sawtRow=String(index);control.dataset.sawtField=field;control.dataset.mastersIgnore='true';control.value=record[field] ?? '';control.maxLength=max;control.setAttribute('aria-label',record.document_number+' '+label);cell.append(control);return control;}
                const tinCell=document.createElement('td'),tin=input(tinCell,'tin','Withholding agent TIN and branch',20);tin.required=true;row.append(tinCell);
                const typeCell=document.createElement('td'),type=document.createElement('select');type.dataset.sawtRow=String(index);type.dataset.sawtField='entity_type';type.add(new Option('Registered entity','corporation'));type.add(new Option('Individual','individual'));type.value=record.entity_type;type.setAttribute('aria-label',record.document_number+' Name type');typeCell.append(type);const registered=input(typeCell,'registered_name','Registered name');row.append(typeCell);
                const names=document.createElement('td'),last=input(names,'last_name','Last name',30),first=input(names,'first_name','First name',30),middle=input(names,'middle_name','Middle name',30);last.placeholder='Last name';first.placeholder='First name';middle.placeholder='Middle name';row.append(names);
                const update=()=>{const individual=type.value==='individual';registered.disabled=individual;registered.hidden=individual;registered.required=!individual;for(const item of [last,first,middle]){item.disabled=!individual;item.hidden=!individual;}last.required=individual;first.required=individual;};type.addEventListener('change',update);update();
                const nature=document.createElement('td');input(nature,'nature_income','Nature of income (optional)');row.append(nature);
                const tax=document.createElement('td'),atc=input(tax,'atc','ATC',5),rate=input(tax,'tax_rate','Tax rate',5);atc.required=true;atc.pattern='W[ICVB][0-9]{3}';rate.type='number';rate.min='0.01';rate.max='99.99';rate.step='0.01';rate.required=true;row.append(tax);
                for(const amount of ['income_payment','amount_withheld']){const cell=document.createElement('td');cell.className='tax-money';cell.textContent=Number(record[amount]).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});row.append(cell);}body.append(row);
            });
            el('sawt-dat-dialog').showModal();
        }catch(error){el('tax-export-status').textContent='SAWT DAT export failed. '+error.message;}
    }
    if(sawt){
        el('sawt-entity').addEventListener('change',syncSawtEntity);el('sawt-dat-dialog').addEventListener('cancel',event=>event.preventDefault());for(const id of ['sawt-dat-close','sawt-dat-cancel'])el(id).addEventListener('click',()=>{if(!generatingDat)el('sawt-dat-dialog').close();});
        el('sawt-dat-form').addEventListener('submit',async event=>{
            event.preventDefault();if(generatingDat||!sawtExportPayload)return;generatingDat=true;el('sawt-dat-download').disabled=true;el('sawt-dat-error').textContent='';el('tax-export-status').textContent='Preparing SAWT DAT…';
            try{
                const payload=JSON.parse(JSON.stringify(sawtExportPayload));payload.taxpayer={tin:el('sawt-tin').value.trim(),entity_type:el('sawt-entity').value,registered_name:el('sawt-name').value.trim(),last_name:el('sawt-last').value.trim(),first_name:el('sawt-first').value.trim(),middle_name:el('sawt-middle').value.trim(),rdo:el('sawt-rdo').value.trim().toUpperCase()};
                document.querySelectorAll('[data-sawt-row][data-sawt-field]').forEach(input=>{payload.records[Number(input.dataset.sawtRow)][input.dataset.sawtField]=input.value.trim();});
                const response=await fetch(main.dataset.sawtDatUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':window.WorkspaceMasters.csrfToken},body:JSON.stringify(payload)});
                if(!response.ok){const error=await response.json().catch(()=>({}));throw Error(Object.values(error.errors || {}).flat()[0] || error.message || 'Unable to generate SAWT DAT. Refresh the page and try again.');}
                const filename=(response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/i)?.[1];if(!filename||!/^\d{19}(?:1700|1701Q?|1702Q?|2550[MQ]|2551Q|2553)\.DAT$/.test(filename))throw Error('The server returned an invalid SAWT filename.');
                const url=URL.createObjectURL(await response.blob()),anchor=document.createElement('a');anchor.href=url;anchor.download=filename;document.body.append(anchor);anchor.click();anchor.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);el('sawt-dat-dialog').close();el('tax-export-status').textContent='SAWT DAT downloaded · '+response.headers.get('X-SAWT-Detail-Count')+' detail row(s) · Selected form layout · BIR validation not performed.';
            }catch(error){el('sawt-dat-error').textContent=error.message;el('tax-export-status').textContent='SAWT DAT export failed. '+error.message;}
            finally{generatingDat=false;el('sawt-dat-download').disabled=false;el('tax-dat').disabled=!report.rows.length;}
        });
    }
    async function generateQap(event,annual=false){
        event.preventDefault();if(generatingDat)return;
        try{
            const payload=annual?window.TaxReportData.qapAnnualDatPayload(ledger,company,year,{masters:window.WorkspaceMasters}):window.TaxReportData.qapDatPayload(ledger,company,window.WorkingPeriod.current(),{masters:window.WorkspaceMasters});
            generatingDat=true;el('tax-dat').disabled=true;el('tax-1604e').disabled=true;el('tax-export-status').textContent=annual?'Generating annual 1604E DAT for '+year+'…':'Generating monthly QAP DAT files…';
            const response=await fetch(annual?main.dataset.qapAnnualDatUrl:main.dataset.qapDatUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':window.WorkspaceMasters.csrfToken},body:JSON.stringify(payload)});
            if(!response.ok){const problem=await response.json().catch(()=>({}));throw Error(Object.values(problem.errors || {}).flat()[0] || problem.message || 'Unable to generate QAP DAT.');}
            const filename=(response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/i)?.[1];if(!filename||!(annual?/^\d{14}1604E12\d{4}\.DAT$/:/^QAP-EWT-Q[1-4]-\d{4}\.zip$/).test(filename))throw Error('The server returned an invalid QAP filename.');
            const url=URL.createObjectURL(await response.blob()),link=document.createElement('a');link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
            el('tax-export-status').textContent=(annual?'Annual 1604E '+year+' DAT downloaded · ':response.headers.get('X-QAP-File-Count')+' monthly QAP DAT files downloaded · ')+response.headers.get('X-QAP-Detail-Count')+' payee / ATC row(s) · BIR validation not performed.';
        }catch(error){el('tax-export-status').textContent=error.message;}
        finally{generatingDat=false;el('tax-1604e').disabled=false;el('tax-dat').disabled=qapSchedule==='2'||!report.rows.length;}
    }
    el('tax-1604e')?.addEventListener('click',event=>generateQap(event,true));
    el('tax-dat').addEventListener('click',event=>qap?generateQap(event):sawt?openSawtDat(event):generateDat(event));
    el('tax-excel').addEventListener('click',()=>exportReport('xlsx'));el('tax-csv').addEventListener('click',()=>exportReport('csv'));
    const toggle=el('sales-menu-toggle'),backdrop=el('sales-backdrop');toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');backdrop.hidden=!open;toggle.setAttribute('aria-expanded',String(open));});backdrop.addEventListener('click',()=>{document.body.classList.remove('sales-menu-open');backdrop.hidden=true;toggle.setAttribute('aria-expanded','false');});document.querySelector('[data-journal-add]')?.addEventListener('click',()=>location.href=main.dataset.journalUrl);render();
})();
