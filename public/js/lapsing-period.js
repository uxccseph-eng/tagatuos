(() => {
    'use strict';
    const api=window.WorkingPeriod,main=document.getElementById('lapsing-main'),picker=document.getElementById('lapsing-month');
    if(!main||!picker)return;
    const config=document.getElementById('workspace-ledger-config'),key='kontadurya.working-period.'+config.dataset.key,params=new URLSearchParams(location.search);
    const valid=value=>/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/.test(value || '');
    let saved=null;try{saved=JSON.parse(sessionStorage.getItem(key));}catch{}
    const initial=[params.get('period'),params.get('from')?.slice(0,7),saved?.from?.slice(0,7),main.dataset.period].find(valid);
    let active=api.month(initial);
    function render(){
        picker.value=active.from.slice(0,7);
        document.querySelector('.sheet-period-label strong').textContent=new Date(active.from+'T12:00:00').toLocaleDateString('en-PH',{month:'long',year:'numeric'});
        document.querySelector('.sheet-period-range').textContent='Monthly depreciation';
        document.querySelectorAll('[aria-label="Lapsing navigation"] a').forEach(link=>{const url=new URL(link.href);url.searchParams.set('period',picker.value);link.href=url.href;});
        document.querySelector('[aria-label="Previous month"]').disabled=picker.value==='1900-01';
        document.querySelector('[aria-label="Next month"]').disabled=picker.value==='2199-12';
    }
    function set(range,notify=true){
        const period=range?.from?.slice(0,7);if(!valid(period))throw Error('Choose a valid month.');active=api.month(period);
        try{sessionStorage.setItem(key,JSON.stringify(active));}catch{}
        render();const url=new URL(location.href);url.searchParams.set('period',period);url.searchParams.delete('from');url.searchParams.delete('to');if(notify)url.searchParams.delete('entry');history.replaceState(null,'',url);
        if(notify)document.dispatchEvent(new CustomEvent('working-period:change',{detail:{...active,period}}));
    }
    picker.addEventListener('change',()=>{if(valid(picker.value))set(api.month(picker.value));else render();});
    for(const [label,offset] of [['Previous month',-1],['Next month',1]])document.querySelector(`[aria-label="${label}"]`).addEventListener('click',()=>{const date=new Date(active.from+'T12:00:00Z');date.setUTCMonth(date.getUTCMonth()+offset);const period=date.toISOString().slice(0,7);if(valid(period))set(api.month(period));});
    window.WorkingPeriod={...api,current:()=>({...active}),set,render,matches:date=>date>=active.from&&date<=active.to};
    set(active,false);
})();
