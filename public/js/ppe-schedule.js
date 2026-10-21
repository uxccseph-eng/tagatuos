(function(root){
    'use strict';
    const index=date=>Number(date.slice(0,4))*12+Number(date.slice(5,7))-1;
    function before(date){const day=new Date(date+'T12:00:00Z');day.setUTCDate(day.getUTCDate()-1);return day.toISOString().slice(0,10);}
    function accumulated(asset,date){
        if(date<asset.service_date)return 0;
        const value=new Date(date+'T12:00:00Z'),end=new Date(Date.UTC(value.getUTCFullYear(),value.getUTCMonth()+1,0)).toISOString().slice(0,10);
        let last=index(date)-(date<end?1:0);
        if(asset.disposal_date)last=Math.min(last,index(asset.disposal_date)-1);
        const months=Math.max(0,Math.min(asset.life_months,last-index(asset.service_date)+1));
        return Math.round((asset.cost_cents-asset.residual_cents)*months/asset.life_months);
    }
    function calculate(asset,from,to){
        const prior=before(from),disposal=asset.disposal_date;
        const openingHeld=asset.acquired_date<from&&(!disposal||disposal>=from);
        const closingHeld=asset.acquired_date<=to&&(!disposal||disposal>to);
        const removed=Boolean(disposal&&disposal>=from&&disposal<=to);
        const opening=openingHeld?asset.cost_cents:0,additions=asset.acquired_date>=from&&asset.acquired_date<=to?asset.cost_cents:0,disposals=removed?asset.cost_cents:0,closing=closingHeld?asset.cost_cents:0;
        const openingDep=openingHeld?accumulated(asset,prior):0;
        const periodDep=Math.max(0,accumulated(asset,to)-accumulated(asset,prior));
        const closingDep=closingHeld?accumulated(asset,to):0;
        const removedDep=removed?accumulated(asset,to):0;
        return {opening,additions,disposals,closing,openingDep,periodDep,removedDep,closingDep,net:Math.max(0,closing-closingDep)};
    }
    const api={accumulated,calculate};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.PpeSchedule=api;
})(globalThis);
