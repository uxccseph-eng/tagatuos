(() => {
    const el=id=>document.getElementById(id), main=el('books-main'), ledger=window.KontaduryaLedger, type=main.dataset.report;
    const template=window.FinancialReportTemplate, company=JSON.parse(el('financial-company').textContent), title=el('financial-report-title').textContent;
    const periods=window.FinancialPeriods,fiscalEnd=Number(main.dataset.fiscalEnd)||12;
    let metadata;
    const params=new URLSearchParams(location.search); let year=/^(19|20|21)\d{2}$/.test(params.get('year') || '')?Number(params.get('year')):Number(main.dataset.year), mode=['comparative','multi-period'].includes(params.get('mode'))&&type!=='trial-balance'?params.get('mode'):'standard', reports=[], displayed=[], headers=[];
    const money=value=>new Intl.NumberFormat('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Math.abs(value)/100), format=value=>value<0?'('+money(value)+')':money(value);
    const info=(title,text)=>{el('books-info-title').textContent=title;el('books-info-text').textContent=text;el('books-info').showModal();};
    function render() {
        year=periods.yearFor(window.WorkingPeriod.current(),fiscalEnd);el('financial-year').value=year;syncQuarter(); const count=mode==='multi-period'?3:mode==='comparative'?2:1;
        reports=Array.from({length:count},(_,offset)=>window.FinancialReports.build(ledger,type,year-offset,Number(main.dataset.fiscalEnd),window.WorkingPeriod.shiftYears(window.WorkingPeriod.current(),-offset)));
        headers=type==='trial-balance'?reports[0].columns:['Account',...reports.map(report=>report.startDate+' — '+report.endDate)];
        const order=[], lookup=new Map();
        for(const report of reports){for(const [index,row] of report.rows.entries()){if(!lookup.has(row.key)){const following=report.rows.slice(index+1).find(item=>order.includes(item.key));if(following){order.splice(order.indexOf(following.key),0,row.key);}else{order.push(row.key);}lookup.set(row.key,row);}}}
        const query=el('sales-global-search').value.trim().toLowerCase(); displayed=[];el('financial-rows').replaceChildren();el('financial-head').replaceChildren();const head=document.createElement('tr');for(const title of headers){const cell=document.createElement('th');cell.scope='col';cell.textContent=title;head.append(cell);}el('financial-head').append(head);
        for(const key of order){const row=lookup.get(key);if(query&&row.kind==='account'&&!((row.code || '')+' '+row.label).toLowerCase().includes(query)){continue;}const values=type==='trial-balance'?row.values:reports.map(report=>report.rows.find(item=>item.key===key)?.value || 0);const tr=document.createElement('tr');tr.className='financial-'+row.kind;
            const label=document.createElement('td');
            if(type==='trial-balance'){const code=document.createElement('td');code.className='financial-code';code.textContent=row.kind==='grand'?row.label:(row.code || '');tr.append(code);}
            if(row.code){const link=document.createElement('a');link.className='financial-account-link';link.textContent=(type==='trial-balance'?'':row.code+' · ')+row.label;link.title='Open transaction report';link.href=breakdownUrl(row);label.append(link);}else{label.textContent=type==='trial-balance'&&row.kind==='grand'?'':row.label;}tr.append(label);
            for(const [index,value] of values.entries()){const cell=document.createElement('td');cell.className='financial-money';if(row.code){const link=document.createElement('a');link.className='financial-account-link';link.textContent=format(value);link.title='Open transaction report';link.href=breakdownUrl(row,type==='trial-balance'?0:index,type==='trial-balance'?['debit','credit','balance'][index]:'balance');cell.append(link);}else{cell.textContent=row.kind==='section'?'':format(value);}tr.append(cell);}el('financial-rows').append(tr);displayed.push({row,values});
        }
        const report=reports[0];
        metadata=template.metadata(company,title,report,type);
        for(const [id,key] of Object.entries({'financial-company-name':'companyName','financial-trade-name':'trade','financial-registration':'registration','financial-address':'address','financial-period-heading':'period','financial-range-heading':'range','financial-branch':'branch','financial-software':'software','financial-generated':'generated'})){el(id).textContent=metadata[key];}
        el('financial-document-filter').hidden=!query;
        const warnings=[...new Set(reports.flatMap(item=>item.warnings))];el('financial-period').textContent=(type==='balance-sheet'?'As at '+report.endDate:report.startDate+' — '+report.endDate)+' · PHP';
        const check=el('financial-check'), balanced=reports.every(item=>item.variance===0&&item.postedVariance===0);check.textContent=type==='income-statement'?report.entries+' posted entries':balanced?(type==='trial-balance'||type==='balance-sheet'?'Balanced':'Reconciled'):'Review variance';check.className=balanced?'financial-balanced':'financial-variance';
        el('financial-notice').textContent=warnings.join(' ');el('financial-notice').hidden=!warnings.length;
        el('financial-footer').textContent=report.entries+' posted entries in the selected date range · Draft and void transactions excluded.'+(query?' Search filters detail rows; totals cover the full report.':'');
        document.querySelectorAll('[data-report-mode]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.reportMode===mode)));
        document.querySelectorAll('[aria-label="Financial Reports navigation"] a').forEach(link=>{const url=new URL(link.href);url.searchParams.set('year',year);if(mode!=='standard'){url.searchParams.set('mode',mode);}else{url.searchParams.delete('mode');}link.href=url.href;});
        const url=new URL(location.href);url.searchParams.set('year',year);if(mode!=='standard'){url.searchParams.set('mode',mode);}else{url.searchParams.delete('mode');}history.replaceState(null,'',url);
        window.WorkingPeriod.render();
    }
    function breakdownUrl(row, index=0, metric='balance') {
        const report=reports[index],url=new URL(main.dataset.transactionsUrl,location.origin);
        for(const [key,value] of Object.entries({account:row.code,row:row.key,from:report.startDate,to:report.endDate,metric,return_from:reports[0].startDate,return_to:reports[0].endDate,mode})){url.searchParams.set(key,value);}
        return url.href;
    }
    function syncQuarter() {
        const selection=periods.identify(window.WorkingPeriod.current(),fiscalEnd),select=el('financial-quarter');
        const month=value=>new Date(value+'T12:00:00Z').toLocaleDateString('en-US',{month:'short',timeZone:'UTC'});
        for(let number=1;number<=4;number++){const range=periods.quarter(year,number,fiscalEnd);select.querySelector('[value="q'+number+'"]').textContent='Q'+number+' · '+month(range.from)+' – '+month(range.to);}
        select.value=selection.kind==='quarterly'?'q'+selection.quarter:selection.kind==='annual'?'year':'';
        el('financial-year').title=fiscalEnd===12?'Calendar year':'Fiscal year ending in this year';
        select.title=fiscalEnd===12?'Calendar-year quarters':'Quarters follow the company fiscal year';
    }
    function applyReportingPeriod(value,yearValue) {
        try{
            const range=value.startsWith('q')?periods.quarter(yearValue,Number(value.slice(1)),fiscalEnd):periods.fiscalYear(yearValue,fiscalEnd);
            if(!window.WorkingPeriod.valid(range))throw Error('The selected period is outside the supported date range.');
            el('financial-year').setCustomValidity('');window.WorkingPeriod.set(range);
        }catch(error){el('financial-year').setCustomValidity(error.message);el('financial-year').reportValidity();el('financial-year').value=year;syncQuarter();}
    }
    el('financial-quarter').addEventListener('change',event=>{if(event.target.value)applyReportingPeriod(event.target.value,year);});
    el('financial-year').addEventListener('change',()=>applyReportingPeriod(el('financial-quarter').value,Number(el('financial-year').value)));
    el('financial-year').addEventListener('input',()=>el('financial-year').setCustomValidity(''));
    document.querySelectorAll('[data-report-mode]').forEach(button=>button.addEventListener('click',()=>{mode=button.dataset.reportMode;render();}));el('sales-global-search').addEventListener('input',render);
    document.addEventListener('working-period:change',render);
    const download=(body,mime,extension,range=reports[0])=>{const url=URL.createObjectURL(new Blob([body],{type:mime})),link=document.createElement('a');link.href=url;link.download=type+'-'+range.startDate+'-to-'+range.endDate+'.'+extension;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);el('financial-export-status').textContent='Report exported.';};
    const exportRows=()=>displayed.map(({row,values})=>({kind:row.kind,cells:[...(type==='trial-balance'?[row.kind==='grand'?row.label:(row.code || '')]:[]),type==='trial-balance'&&row.kind==='grand'?'':row.label,...values.map(value=>row.kind==='section'?'':value/100)]}));
    const exportMetadata=()=>{const meta=template.metadata(company,title,reports[0],type);if(el('sales-global-search').value.trim()){meta.lines[5]+=' · Filtered detail; totals cover the full report';}return meta;};
    el('financial-csv').addEventListener('click',()=>download(template.csv(headers,exportRows()),'text/csv;charset=utf-8','csv'));
    el('financial-excel').addEventListener('click',()=>download(template.xlsx(exportMetadata(),headers,exportRows()),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','xlsx'));
    el('financial-pdf').addEventListener('click',async()=>{const button=el('financial-pdf');button.disabled=true;button.setAttribute('aria-busy','true');el('financial-export-status').textContent='Generating PDF…';try{const meta=exportMetadata(),columns=[...headers],rows=exportRows(),range={...reports[0]};const pdf=await window.FinancialReportPdf.create(meta,columns,rows);download(pdf,'application/pdf','pdf',range);}catch(error){el('financial-export-status').textContent='PDF export failed. '+error.message;}finally{button.disabled=false;button.removeAttribute('aria-busy');}});
    document.querySelectorAll('[data-sales-info],[data-wtp-info]').forEach(button=>button.addEventListener('click',event=>{event.preventDefault();info(button.dataset.salesInfo || button.dataset.wtpInfo,'This module is not connected yet. Financial reports are available from the posted local ledger.');}));el('books-info-close').addEventListener('click',()=>el('books-info').close());el('books-info').addEventListener('click',event=>{if(event.target!==el('books-info')){return;}const rect=el('books-info').getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom){el('books-info').close();}});
    document.querySelector('[data-journal-add]')?.addEventListener('click',()=>{location.href=main.dataset.journalUrl;});const toggle=el('sales-menu-toggle'),backdrop=el('sales-backdrop');function closeMenu(){document.body.classList.remove('sales-menu-open');backdrop.hidden=true;toggle.setAttribute('aria-expanded','false');}toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');backdrop.hidden=!open;toggle.setAttribute('aria-expanded',String(open));});backdrop.addEventListener('click',closeMenu);document.addEventListener('keydown',event=>{if(event.key==='Escape'){closeMenu();}if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();el('sales-global-search').focus();}});render();
})();
