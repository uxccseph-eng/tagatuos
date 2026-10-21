const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const periodApi=require('../../public/js/working-period.js');
function page(url='http://localhost/lapsing?period=2027-01&from=2027-01-15&to=2027-03-12'){
    const element=()=>({dataset:{},value:'',disabled:false,events:{},addEventListener(name,handler){this.events[name]=handler;}});
    const main=element(),picker=element(),config=element(),previous=element(),next=element(),label=element(),range=element(),events=[],storage=new Map();
    main.dataset.period='2026-10';config.dataset.key='monthly-test';
    const context={window:{WorkingPeriod:periodApi},URL,URLSearchParams,location:{href:url,search:new URL(url).search},history:{replaceState(_state,_title,value){context.location.href=String(value);}},sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},document:{getElementById:id=>({'lapsing-main':main,'lapsing-month':picker,'workspace-ledger-config':config}[id]),querySelector:selector=>({'.sheet-period-label strong':label,'.sheet-period-range':range,'[aria-label="Previous month"]':previous,'[aria-label="Next month"]':next}[selector]),querySelectorAll:()=>[],dispatchEvent:event=>events.push(event)}};
    vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/lapsing-period.js'),'utf8'),context);
    return {context,picker,previous,next,events,api:context.window.WorkingPeriod};
}
test('Lapsing normalizes custom ranges to one full month and removes date-range URL parameters',()=>{
    const ui=page();assert.equal(ui.picker.value,'2027-01');assert.equal(ui.api.current().from,'2027-01-01');assert.equal(ui.api.current().to,'2027-01-31');
    const url=new URL(ui.context.location.href);assert.equal(url.searchParams.get('period'),'2027-01');assert.equal(url.searchParams.has('from'),false);assert.equal(url.searchParams.has('to'),false);
});
test('previous/next and the month picker handle year boundaries and leap years',()=>{
    const ui=page();ui.previous.events.click();assert.equal(ui.picker.value,'2026-12');ui.next.events.click();assert.equal(ui.picker.value,'2027-01');
    ui.picker.value='2028-02';ui.picker.events.change();assert.equal(ui.api.current().to,'2028-02-29');assert.equal(ui.events.at(-1).detail.period,'2028-02');
    ui.picker.value='';ui.picker.events.change();assert.equal(ui.picker.value,'2028-02');
});
test('month navigation is bounded and clears stale voucher links after changing months',()=>{
    const ui=page('http://localhost/lapsing?period=1900-01&entry=DEP-2027-01');assert.equal(ui.previous.disabled,true);
    ui.next.events.click();assert.equal(new URL(ui.context.location.href).searchParams.has('entry'),false);
    ui.picker.value='2199-12';ui.picker.events.change();assert.equal(ui.next.disabled,true);
});
test('posted badge follows the selected preview and disappears for an unposted month',()=>{
    const nodes=Object.fromEntries(['run-month','run-status','attention-count','run-post'].map(id=>[id,{}]));
    const context={el:id=>nodes[id],preview:{from:'2027-01-01',period:'2027-01',run:{number:'DEP-2027-01',posted_at:'2027-01-31T12:00:00Z'},attention:[],ready:false},busy:false,fullMonth:()=>true,document:{querySelectorAll:()=>[]}};
    const source=fs.readFileSync(path.join(__dirname,'../../public/js/lapsing.js'),'utf8');
    vm.createContext(context);vm.runInContext(source.slice(source.indexOf('    function render(){'),source.indexOf("        if(page==='run'){"))+'}',context);context.render();
    assert.equal(nodes['run-status'].className,'posted-status-badge');assert.equal(nodes['run-status'].textContent,'Posted · 2027-01');assert.equal(nodes['run-post'].disabled,true);
    context.preview={from:'2027-02-01',period:'2027-02',run:null,attention:[],ready:true};context.render();assert.equal(nodes['run-status'].className,'masters-chip');assert.equal(nodes['run-status'].textContent,'Ready to post');assert.equal(nodes['run-post'].disabled,false);
});
