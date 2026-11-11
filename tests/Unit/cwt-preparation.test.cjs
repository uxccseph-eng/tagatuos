const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../public/js/cwt.js'),'utf8');
function prepare(records,sale={id:123,number:'SI-000123',customer:'Customer',tin:'12345678900000',taxBase:47059,cwt:'WC160 · 2%',withheld:941}){
 const opened=[],messages=[],saved=[],elements=new Map();
 const context={records:structuredClone(records),ledger:{cwtSource:id=>String(id)===String(sale.id)?sale:null},window:{DraftLeaveGuard:{continueDraft:(rows,callback)=>{const draft=rows.find(row=>row.status==='draft');if(draft)callback(draft);return draft;}},WorkingPeriod:{revealDate:()=>{},current:()=>({from:'2026-11-01'}),entryDate:()=> '2026-11-12'}},element:id=>{if(!elements.has(id))elements.set(id,{value:'filter',scrollIntoView:()=>{}});return elements.get(id);},render:()=>{},inspect:record=>opened.push(record),announce:message=>messages.push(message),persist:()=>saved.push(true),crypto:{randomUUID:()=> 'new-certificate'},period:'2026-11',status:'posted'};
 vm.createContext(context);
 vm.runInContext(source.slice(source.indexOf('    function referencesSale('),source.indexOf('    const match='))+source.slice(source.indexOf('    function add(saleId)'),source.indexOf('    function control(')),context);
 return {context,opened,messages,saved,click:id=>context.add(id)};
}
for(const status of ['void','archived'])test(`Prepare creates a fresh draft when an old ${status} certificate exists`,()=>{
 const ui=prepare([{id:'old',number:'2307R-000004',saleId:123,invoice:'SI-000123',status,date:'2026-10-01'}]);ui.click(123);
 assert.equal(ui.context.records.length,2);assert.equal(ui.context.records[0].status,status);assert.equal(ui.context.records[1].status,'draft');assert.equal(ui.context.records[1].saleId,123);assert.equal(ui.context.records[1].number,'');assert.equal(ui.context.records[1].amount,941);assert.equal(ui.saved.length,1);
});
for(const status of ['draft','posted'])test(`Prepare opens the matching ${status} certificate despite numeric/string IDs`,()=>{
 const ui=prepare([{id:'existing',number:status==='posted'?'2307R-000123':'',saleId:'123',invoice:'SI-000123',status,date:'2026-11-01'}]);ui.click(123);
 assert.equal(ui.context.records.length,1);assert.equal(ui.opened[0].id,'existing');assert.equal(ui.saved.length,0);assert.equal(ui.context.status,'all');
});
test('Prepare opens a legacy posted certificate by invoice when its source ID is absent',()=>{
 const ui=prepare([{id:'legacy',number:'2307R-000123',invoice:'SI-000123',status:'posted',date:'2026-11-01'}]);ui.click(123);assert.equal(ui.opened[0].id,'legacy');assert.equal(ui.saved.length,0);
});
test('a retained certificate with a different source ID cannot block a reused invoice number',()=>{
 const ui=prepare([{id:'other',number:'2307R-000122',saleId:122,invoice:'SI-000123',status:'posted',date:'2026-11-01'}]);ui.click(123);assert.equal(ui.context.records.length,2);assert.equal(ui.saved.length,1);
});
test('Prepare keeps an unrelated active draft and opens it with a clear instruction',()=>{
 const ui=prepare([{id:'other-draft',saleId:124,invoice:'SI-000124',status:'draft',date:'2026-11-01'}]);ui.click(123);assert.equal(ui.context.records.length,1);assert.equal(ui.opened[0].id,'other-draft');assert.match(ui.messages.at(-1),/SI-000124.*SI-000123/);assert.equal(ui.saved.length,0);
});
test('an unavailable source does not create an empty certificate or open a different record',()=>{
 const ui=prepare([]);ui.click(999);assert.equal(ui.context.records.length,0);assert.equal(ui.opened.length,0);assert.equal(ui.saved.length,0);assert.match(ui.messages.at(-1),/active posted source/);
});
