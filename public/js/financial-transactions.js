(() => {
    const el=id=>document.getElementById(id),config=JSON.parse(el('transaction-report-config').textContent),{selection,report,company}=config,ledger=window.KontaduryaLedger;
    const range={from:selection.from,to:selection.to}, build=range=>window.FinancialReports.build(ledger,report,Number(range.to.slice(0,4)),12,range);
    const selectedReport=build(range),sourceReport=build({from:selection.return_from || range.from,to:selection.return_to || range.to});
    const matches=row=>row.key===selection.row && row.code===selection.account;
    const selectedRow=selectedReport.rows.find(matches),row=selectedRow || sourceReport.rows.find(matches);
    const money=value=>{const number=new Intl.NumberFormat('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Math.abs(value)/100);return value<0?'('+number+')':number;};
    const columns=['Transaction date','Transaction type','Number','Name','Description','Account name','Split account','Amount','Balance'];
    let detail,expected;
    function cell(tr,value,numeric=false){const td=document.createElement('td');td.textContent=value;if(numeric)td.className='transaction-money';tr.append(td);return td;}
    function render(){
        const body=el('transaction-rows');body.replaceChildren();const search=el('transaction-search').value.trim().toLowerCase();
        const visible=detail.rows.filter(item=>!search || [item.date,item.type,item.number,item.name,item.description,item.account,item.split].join(' ').toLowerCase().includes(search));
        const heading=document.createElement('tr');heading.className='transaction-group';cell(heading,row.code+' · '+row.label+' ('+detail.rows.length+')').colSpan=9;body.append(heading);
        for(const item of visible){const tr=document.createElement('tr');if(item.opening)tr.className='transaction-opening';for(const key of ['date','type','number','name','description','account','split']){const td=cell(tr,item[key] || '—');td.title=item[key] || '';}cell(tr,money(item.amount),true);cell(tr,money(item.balance),true);body.append(tr);}
        if(!visible.length){const tr=document.createElement('tr');cell(tr,detail.rows.length?'No transactions match your search.':'No posted transactions for this account and period.').colSpan=9;body.append(tr);}
        const totals=el('transaction-totals');totals.replaceChildren();const tr=document.createElement('tr');cell(tr,'Total · '+row.code+' · '+row.label).colSpan=7;cell(tr,money(detail.total),true);cell(tr,money(detail.total),true);totals.append(tr);
        el('transaction-count').textContent=visible.length+' of '+detail.rows.length+' rows · PHP · Draft and void entries excluded.';
        el('transaction-reconciliation').textContent='Report amount: '+money(expected)+(detail.total===expected?' · Reconciled':' · Review variance');
    }
    el('transaction-range').textContent=selection.from+' — '+selection.to;
    if(!row){el('transaction-notice').hidden=false;el('transaction-notice').textContent='This account is no longer in the source report. Return to Financial Reports and select an amount again.';return;}
    detail=window.FinancialAccountBreakdown.transactions(ledger,report,row,selectedReport,selection.metric || 'balance');
    const metric=['debit','credit','balance'].indexOf(selection.metric || 'balance');
    expected=report==='trial-balance'?(selectedRow?.values[metric] || 0):(selectedRow?.value || 0);
    el('transaction-basis').textContent=detail.scope+(report==='trial-balance'?' · '+detail.metric.charAt(0).toUpperCase()+detail.metric.slice(1):'');
    if(detail.total!==expected){el('transaction-notice').hidden=false;el('transaction-notice').textContent='The transaction total differs from the source report. Review the account entries.';}
    el('transaction-search').addEventListener('input',render);
    el('sales-global-search')?.addEventListener('input',event=>{el('transaction-search').value=event.target.value;render();});
    el('transaction-density').addEventListener('change',event=>document.querySelector('.transaction-paper').classList.toggle('transaction-comfortable',event.target.value==='comfortable'));
    const excel=el('transaction-excel');excel.disabled=false;
    excel.addEventListener('click',()=>{
        try{
            const meta=window.FinancialReportTemplate.metadata(company,'Transaction Report',selectedReport,report);
            meta.lines[4]='Transaction Report · '+row.code+' · '+row.label;meta.lines[6]=config.reportTitle+' · '+detail.metric;meta.lines[7]=detail.scope;
            const rows=[{kind:'section',cells:[row.code+' · '+row.label+' ('+detail.rows.length+')','','','','','','','','']},...detail.rows.map(item=>({kind:'account',cells:[item.date,item.type,item.number,item.name,item.description,item.account,item.split,item.amount/100,item.balance/100]})),{kind:'grand',cells:['Total','','','','','','',detail.total/100,detail.total/100]}];
            const bytes=window.FinancialReportTemplate.xlsx(meta,columns,rows,{widths:[18,22,20,30,50,38,55,20,20],wrap:true});
            const url=URL.createObjectURL(new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'})),link=document.createElement('a');link.href=url;link.download=report+'-transactions-'+row.code.replace(/[^a-z0-9-]/gi,'_')+'-'+selection.from+'-to-'+selection.to+'.xlsx';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);el('transaction-export-status').textContent='Full breakdown exported: '+detail.rows.length+' rows.';
        }catch(error){el('transaction-export-status').textContent='Export failed. '+error.message;}
    });
    const toggle=el('sales-menu-toggle'),backdrop=el('sales-backdrop');toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');backdrop.hidden=!open;toggle.setAttribute('aria-expanded',String(open));});backdrop.addEventListener('click',()=>{document.body.classList.remove('sales-menu-open');backdrop.hidden=true;toggle.setAttribute('aria-expanded','false');});
    document.querySelector('[data-journal-add]')?.addEventListener('click',()=>location.href=el('books-main').dataset.journalUrl);
    render();
})();
