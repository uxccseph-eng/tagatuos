(function(root){
    const validDate=value=>typeof value==='string'&&/^(?:19|20|21)\d{2}-\d{2}-\d{2}$/.test(value)&&Number.isFinite(Date.parse(value+'T12:00:00Z'))&&new Date(value+'T12:00:00Z').toISOString().slice(0,10)===value;
    const valid=range=>Boolean(range&&validDate(range.from)&&validDate(range.to)&&range.from<=range.to);
    function month(period){if(!/^(?:19|20|21)\d{2}-(?:0[1-9]|1[0-2])$/.test(period || ''))throw Error('Choose a valid month.');const [year,value]=period.split('-').map(Number);return {from:period+'-01',to:new Date(Date.UTC(year,value,0)).toISOString().slice(0,10)};}
    const includes=(date,range)=>valid(range)&&validDate(date)&&date>=range.from&&date<=range.to;
    function shiftYears(range,offset){if(!valid(range))throw Error('Choose a valid date range.');const shift=value=>{const [year,month,day]=value.split('-').map(Number),target=year+offset;return target+'-'+String(month).padStart(2,'0')+'-'+String(Math.min(day,new Date(Date.UTC(target,month,0)).getUTCDate())).padStart(2,'0');};return {from:shift(range.from),to:shift(range.to)};}
    const api={validDate,valid,month,includes,shiftYears};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(!root)return;
    const controls=document.querySelector('[data-working-period-controls]');if(!controls){root.WorkingPeriod=api;return;}
    const main=document.querySelector('main[data-period],main[data-report]'),config=document.getElementById('workspace-ledger-config'),params=new URLSearchParams(location.search),financial=Boolean(main?.dataset.report),storageKey='kontadurya.working-period.'+(config?.dataset.key || 'preview');
    let seed=main?.dataset.period || JSON.parse(config?.textContent || '{}').period,initial;
    function fiscal(year){const endMonth=Number(main.dataset.fiscalEnd)||12;return {from:(endMonth===12?year:year-1)+'-'+String(endMonth%12+1).padStart(2,'0')+'-01',to:new Date(Date.UTC(year,endMonth,0)).toISOString().slice(0,10)};}
    if(financial){const year=/^(?:19|20|21)\d{2}$/.test(params.get('year') || '')?Number(params.get('year')):Number(main.dataset.year);initial=fiscal(year);if(!valid(initial))initial=fiscal(Number(main.dataset.year));}else initial=month(seed);
    let saved=null;try{saved=JSON.parse(sessionStorage.getItem(storageKey));}catch{}
    const requested={from:params.get('from'),to:params.get('to')};
    let active=valid(requested)?requested:params.has('period')&&/^(?:19|20|21)\d{2}-(?:0[1-9]|1[0-2])$/.test(params.get('period'))?month(params.get('period')):financial&&params.has('year')?initial:valid(saved)?saved:initial;
    const from=controls.querySelector('[data-period-from]'),to=controls.querySelector('[data-period-to]'),format=value=>new Date(value+'T12:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
    function render(){
        if(document.activeElement!==from)from.value=active.from;if(document.activeElement!==to)to.value=active.to;
        const fullMonth=month(active.from.slice(0,7)),label=active.from===fullMonth.from&&active.to===fullMonth.to?new Date(active.from+'T12:00:00').toLocaleDateString('en-PH',{month:'long',year:'numeric'}):'Date range';
        document.querySelectorAll('.sheet-period-label strong,[data-books-month]').forEach(node=>{if(node.textContent!==label)node.textContent=label;});
        document.querySelectorAll('.sheet-period-range').forEach(node=>{const value=format(active.from)+' – '+format(active.to);if(node.textContent!==value)node.textContent=value;});
        document.querySelectorAll('[aria-label="Books navigation"] a,[aria-label="Financial Reports navigation"] a').forEach(link=>{const url=new URL(link.href);url.searchParams.set('from',active.from);url.searchParams.set('to',active.to);link.href=url.href;});
    }
    function set(range,notify=true){if(!valid(range))throw Error('From must be a valid date on or before To.');active={from:range.from,to:range.to};try{sessionStorage.setItem(storageKey,JSON.stringify(active));}catch{}render();const url=new URL(location.href);url.searchParams.set('from',active.from);url.searchParams.set('to',active.to);if(!financial)url.searchParams.set('period',active.from.slice(0,7));history.replaceState(null,'',url);if(notify)document.dispatchEvent(new CustomEvent('working-period:change',{detail:{...active,period:active.from.slice(0,7)}}));}
    root.WorkingPeriod={...api,current:()=>({...active}),matches:date=>includes(date,active),render,set,fiscal,entryDate:()=>{const now=new Date(),today=now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');return includes(today,active)?today:active.from;},revealDate(date){if(validDate(date)&&!includes(date,active))set(month(date.slice(0,7)));}};
    from.value=active.from;to.value=active.to;
    for(const input of [from,to])input.addEventListener('input',()=>{from.setCustomValidity('');to.setCustomValidity('');});
    controls.querySelector('[data-period-apply]').addEventListener('click',()=>{from.setCustomValidity('');to.setCustomValidity('');if(!valid({from:from.value,to:to.value})){to.setCustomValidity('Choose valid From and To dates. To must be on or after From.');to.reportValidity();return;}set({from:from.value,to:to.value});});
    controls.querySelector('[data-period-month]').addEventListener('click',()=>set(financial?fiscal(Number(active.to.slice(0,4))+(Number(active.to.slice(5,7))>(Number(main.dataset.fiscalEnd)||12)?1:0)):month(active.from.slice(0,7))));
    document.addEventListener('click',event=>{const button=event.target.closest('.sheet-period button[aria-label="Previous month"],.sheet-period button[aria-label="Next month"],#books-prev,#books-next');if(!button)return;event.preventDefault();event.stopImmediatePropagation();const date=new Date(active.from+'T12:00:00Z');date.setUTCDate(1);date.setUTCMonth(date.getUTCMonth()+(button.getAttribute('aria-label')==='Previous month'?-1:1));const period=date.toISOString().slice(0,7);if(validDate(period+'-01'))set(month(period));},true);
    set(active,false);
})(typeof window==='undefined'?null:window);
