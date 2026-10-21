(() => {
    'use strict';
    const el=id=>document.getElementById(id),main=el('lapsing-main'),page=main.dataset.page,money=value=>new Intl.NumberFormat('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100);
    let preview=null,revision=0,busy=false;
    const report=message=>{el('run-message').textContent=message;};
    const cell=(row,value,moneyCell=false)=>{const node=row.insertCell();node.textContent=moneyCell?money(value):value;node.className=moneyCell?'ppe-money':'';return node;};
    function fullMonth(){const range=window.WorkingPeriod.current();return preview&&range.from===preview.from&&range.to===preview.to;}
    async function response(url,options={}){const result=await fetch(url,{headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...options});let data;try{data=await result.json();}catch{throw Error('Unable to read the response. Refresh and try again.');}if(!result.ok)throw Error(result.status===422?Object.values(data.errors||{}).flat().join(' '):result.status===419?'Session expired. Refresh before posting.':'Unable to load or post this run.');return data;}
    async function load(){
        const current=++revision;preview=null;if(el('run-post'))el('run-post').disabled=true;el('run-status').className='masters-chip';el('run-status').textContent='Loading…';el('run-status').removeAttribute('title');
        try{const url=new URL(main.dataset.previewUrl,location.origin);url.searchParams.set('period',window.WorkingPeriod.current().from.slice(0,7));const data=await response(url);if(current!==revision)return;preview=data;render();report(fullMonth()?'Loaded from PPE Masters.':`Preview is for ${data.period} only. Select the full month before posting.`);}catch(error){if(current===revision){report(error.message);el('run-status').textContent='Unable to load';}}
    }
    function render(){
        el('run-month').textContent=new Date(preview.from+'T12:00:00').toLocaleDateString('en-PH',{month:'long',year:'numeric'});
        el('run-status').className=preview.run?'posted-status-badge':'masters-chip';el('run-status').title=preview.run?preview.run.number+' · '+new Date(preview.run.posted_at).toLocaleString():'';
        el('run-status').textContent=preview.run?'Posted · '+preview.period:preview.ready?'Ready to post':preview.attention.length?'Needs attention':'No depreciation to post';
        el('attention-count').textContent=preview.attention.length;
        if(el('run-post'))el('run-post').disabled=busy||!preview.ready||!fullMonth();
        document.querySelectorAll('[aria-label="Lapsing navigation"] a').forEach(link=>{const url=new URL(link.href);url.searchParams.set('period',preview.period);link.href=url.href;});
        if(page==='run'){
            const body=el('run-rows'),sums={cost_cents:0,monthly:0,opening_dep:0,depreciation:0,closing_dep:0,net:0};body.replaceChildren();let group=null;
            for(const item of preview.rows){
                if(item.category!==group){group=item.category;const row=document.createElement('tr');row.className='ppe-group';const label=cell(row,group);label.colSpan=10;body.append(row);}
                const row=document.createElement('tr');cell(row,item.code);cell(row,item.name);cell(row,item.acquired_date);cell(row,item.cost_cents,true);cell(row,item.life_months+' mo');for(const key of ['monthly','opening_dep','depreciation','closing_dep','net'])cell(row,item[key],true);body.append(row);for(const key of Object.keys(sums))sums[key]+=item[key];
            }
            if(!preview.rows.length){const row=document.createElement('tr'),empty=cell(row,'No eligible PPE items in this month. Register assets in PPE Masters.');empty.colSpan=10;body.append(row);}
            const total=document.createElement('tr');const label=cell(total,'Total · '+preview.rows.length+' item(s)');label.colSpan=3;cell(total,sums.cost_cents,true);cell(total,'');for(const key of ['monthly','opening_dep','depreciation','closing_dep','net'])cell(total,sums[key],true);el('run-totals').replaceChildren(total);
            el('run-summary').textContent=`Cost ₱${money(sums.cost_cents)} · Dep this month ₱${money(sums.depreciation)} · NBV ₱${money(sums.net)}`;
        }else if(page==='attention'){
            const container=el('run-attention');container.replaceChildren();if(!preview.attention.length){const heading=document.createElement('h2');heading.textContent='Nothing needs attention';const text=document.createElement('p');text.textContent='No outstanding setup or monthly-run issues were found for this period.';container.append(heading,text);}
            for(const issue of preview.attention){const item=document.createElement('article'),heading=document.createElement('h3');heading.textContent=issue.code+' · '+issue.name;const detail=document.createElement('p');detail.textContent=issue.message;const link=document.createElement('a');link.href=main.dataset.mastersUrl+'?asset='+encodeURIComponent(issue.code);link.textContent='Open in PPE Masters';item.append(heading,detail,link);container.append(item);}
        }
    }
    el('run-post')?.addEventListener('click',async()=>{
        if(busy||!preview?.ready||!fullMonth())return;busy=true;render();
        try{const result=await response(main.dataset.postUrl,{method:'POST',body:JSON.stringify({period:preview.period,preview_hash:preview.preview_hash})});await load();report(result.created?result.run.number+' posted. The depreciation voucher is saved and available in Books.':'This month was already posted; no duplicate run was created.');}catch(error){report(error.message);}finally{busy=false;if(preview)render();}
    });
    el('run-refresh').addEventListener('click',()=>{if(!busy)load();});document.addEventListener('working-period:change',()=>{if(!busy)load();});
    el('run-export').addEventListener('click',()=>{
        if(!preview)return;const columns=['code','name','category','acquired_date','cost_cents','life_months','monthly','opening_dep','depreciation','closing_dep','net'],amounts=new Set(['cost_cents','monthly','opening_dep','depreciation','closing_dep','net']),quote=value=>'"'+String(value??'').replace(/^[=+@\-\t\r]/,"'$&").replace(/"/g,'""')+'"';const rows=[columns,...preview.rows.map(item=>columns.map(key=>amounts.has(key)?(item[key]/100).toFixed(2):item[key]))];const url=URL.createObjectURL(new Blob(['\ufeff'+rows.map(row=>row.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'})),link=document.createElement('a');link.href=url;link.download='depreciation-'+preview.period+'.csv';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    const toggle=el('sales-menu-toggle'),backdrop=el('sales-backdrop');toggle.addEventListener('click',()=>{const open=document.body.classList.toggle('sales-menu-open');toggle.setAttribute('aria-expanded',String(open));backdrop.hidden=!open;});backdrop.addEventListener('click',()=>{document.body.classList.remove('sales-menu-open');toggle.setAttribute('aria-expanded','false');backdrop.hidden=true;});
    document.querySelectorAll('[data-sales-info]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();report('This module is not connected yet.');}));const requested=new URLSearchParams(location.search).get('entry');if(/^DEP-(19|20|21)\d{2}-\d{2}$/.test(requested || ''))window.WorkingPeriod.set(window.WorkingPeriod.month(requested.slice(4)),false);load();
})();