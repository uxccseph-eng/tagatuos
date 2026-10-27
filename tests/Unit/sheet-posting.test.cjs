const test=require('node:test'),assert=require('node:assert/strict'),posting=require('../../public/js/sheet-posting.js');
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
for(const sheet of ['sales','collections','cwt','purchases','payments','wtp','journal','payroll']){
    test(sheet+' toolbar posts the single visible draft without requiring a selection',()=>{const records=[{id:1,status:'posted'},{id:2,status:'draft'},{id:3,status:'void'}];assert.deepEqual(posting.candidates(records),[records[1]]);assert.deepEqual(posting.candidates(records,new Set([2])),[records[1]]);assert.equal(records[1].status,'draft');});
}
test('selecting only posted or void entries never posts an unselected draft',()=>{const records=[{id:1,status:'posted'},{id:2,status:'draft'},{id:3,status:'void'}];assert.deepEqual(posting.candidates(records,[1]),[]);assert.deepEqual(posting.candidates(records,[3]),[]);assert.deepEqual(posting.candidates(records,[1,2]),[records[1]]);});
test('legacy multiple drafts need explicit selection and hidden rows cannot be candidates',()=>{const records=[{id:'first',status:'draft'},{id:'second',status:'draft'}];assert.deepEqual(posting.candidates(records),[]);assert.deepEqual(posting.candidates(records,['second']),[records[1]]);assert.deepEqual(posting.candidates([],['first']),[]);});
const sale={date:'2026-10-20',customer:'Sample Customer',gross:11200,account:'4020 · Sales - Services',tax:'12% VAT',terms:'Cash',bank:'1010 · Cash on Hand',memo:''};
test('direct Sales posting accepts optional descriptions and validates essential fields',()=>{assert.equal(posting.salesError(sale),'');for(const change of [{date:'2026-02-30'},{date:''},{customer:' '},{gross:0},{gross:NaN},{gross:1.2},{account:''},{tax:''},{terms:'Credit'},{bank:''}])assert.notEqual(posting.salesError({...sale,...change}),'');});

function inlineSales(overrides={}){
    const storage=new Map(),messages=[],window={addEventListener(){},SheetPosting:posting,WorkingPeriod:{matches:date=>date>='2026-10-01'&&date<='2026-10-31'}},context={window,document:{getElementById:()=>({dataset:{key:'direct-post'},textContent:'{"period":"2026-10"}'}),querySelectorAll:()=>[]},sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)},location:{reload(){}}};
    vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/workspace-ledger.js'),'utf8'),context);
    const ledger=window.KontaduryaLedger,draft={...sale,id:4,number:'SI-000004',status:'draft',cwt:'None',vatCwt:'None',reference:'DIRECT-POST',...overrides};ledger.saveSales([...ledger.sales(),draft]);
    Object.assign(context,{entries:new Map(),autoSalesDraft:draft,draft:{hidden:false},selectedEntries:()=>[],announce:message=>messages.push(message)});let refreshed=0;window.SheetBulk={refresh:()=>refreshed++};
    const source=fs.readFileSync(path.join(__dirname,'../../public/js/sales.js'),'utf8');vm.runInContext(source.slice(source.indexOf('function postingCandidates()'),source.indexOf('function refreshPostCount()'))+source.slice(source.indexOf('function postSelection('),source.indexOf('function voidEntries(')),context);
    return {context,ledger,storage,messages,post:()=>vm.runInContext('postSelection()',context),refreshed:()=>refreshed};
}
test('Sales toolbar posts the active inline entry without any popup and creates its cash collection',()=>{const ui=inlineSales(),before=ui.ledger.collections().length;ui.post();assert.equal(ui.ledger.sales().at(-1).status,'posted');assert.equal(ui.ledger.sales().at(-1).number,'SI-000004');assert.equal(ui.ledger.collections().length,before+1);assert.equal(ui.context.autoSalesDraft,null);assert.equal(ui.context.draft.hidden,true);assert.equal(ui.refreshed(),1);});
test('direct Sales posting keeps on-account sales unpaid and blocks incomplete inline entries',()=>{const ui=inlineSales({terms:'On Account'}),before=ui.ledger.collections().length;ui.post();assert.equal(ui.ledger.collections().length,before);assert.equal(ui.ledger.invoices().at(-1).balance,sale.gross);const invalid=inlineSales({gross:0});invalid.post();assert.equal(invalid.ledger.sales().at(-1).status,'draft');assert.equal(invalid.context.draft.hidden,false);assert.match(invalid.messages.at(-1),/positive gross/);});
test('failed inline posting preserves the draft and never reports success',()=>{const ui=inlineSales(),before=ui.ledger.collections().length;ui.storage.set=()=>{throw Error('Storage full');};ui.post();assert.equal(ui.ledger.sales().at(-1).status,'draft');assert.equal(ui.ledger.collections().length,before);assert.equal(ui.context.draft.hidden,false);assert.equal(ui.context.autoSalesDraft.id,4);assert.equal(ui.refreshed(),0);assert.match(ui.messages.at(-1),/storage is unavailable/);});

test('Sales row Post targets its own draft even when a different posted row is selected',()=>{
    const ui=inlineSales();
    ui.context.selectedEntries=()=>[{id:1,status:'posted'}];
    vm.runInContext('postSelection([autoSalesDraft])',ui.context);
    assert.equal(ui.ledger.sales().at(-1).status,'posted');
    assert.equal(ui.ledger.sales().at(-1).number,'SI-000004');
});

function directPost(sheet,{invalid=false,failSave=false,certificate='CERT-001'}={}) {
    const record={id:'draft-1',number:'TXN-000001',date:'2026-10-20',status:'draft',customer:'Sample Customer',supplier:'Sample Supplier',gross:10000,amount:10000,tin:'000-000-000-00000',certificate,saleId:'sale-1'};
    const messages=[],saved=[],rendered=[];
    const context={window:{SheetMasters:{withholdingError:()=>''}},records:sheet==='collections'?new Map([[record.id,record]]):[record],selected:new Set(),clone:value=>JSON.parse(JSON.stringify(value)),history:()=>{},invalid:()=>invalid,invalidDraft:()=>invalid,match:()=>true,validate:()=>!invalid,announce:message=>messages.push(message),render:()=>rendered.push(true),update:()=>{},open:()=>{throw Error('Posting must not open a popup');},inspect:()=>{throw Error('Posting must not inspect a popup');}};
    const save=next=>{if(failSave)throw Error('Storage full');if(sheet==='cwt')for(const item of next)if(item.status==='posted'&&!item.certificate)item.certificate=item.number;saved.push(JSON.parse(JSON.stringify(next)));};
    context.store=next=>{try{save(next);context.records=next;return true;}catch(error){context.announce(error.message);return false;}};
    context.ledger={wtpAtc:record=>record.ewtCode || '',wtpVatAtc:record=>record.wvatCode || '',certificateNumber:inlineSales().ledger.certificateNumber,saveCollections:save,saveCwtCertificates:save,paymentError:()=>invalid?'Complete payment fields.':'',wtpError:()=>invalid?'Complete certificate fields.':'',journalError:()=>invalid?'Unbalanced journal.':''};
    const source=fs.readFileSync(path.join(__dirname,'../../public/js',sheet+'.js'),'utf8');
    const start=source.indexOf('    function post('),end=source.indexOf('\n    function ',start+5);
    assert.ok(start>=0&&end>start, sheet+' has a directly callable post handler');
    vm.createContext(context);vm.runInContext(source.slice(start,end),context);
    return {record,messages,saved,rendered,post:()=>context.post(posting.candidates([record]))};
}
for(const sheet of ['collections','cwt','purchases','payments','wtp','journal']) {
    test(sheet+' actual post handler saves the inline draft without a popup',()=>{
        const ui=directPost(sheet);assert.equal(ui.post(),true);assert.equal(ui.saved.length,1);assert.equal(ui.saved[0][0].status,'posted');assert.ok(ui.saved[0][0].postedAt);assert.equal(ui.rendered.length,1);assert.match(ui.messages.at(-1),/posted/);
    });
    test(sheet+' refuses incomplete drafts without saving or opening a popup',()=>{
        const ui=directPost(sheet,{invalid:true});assert.notEqual(ui.post(),true);assert.equal(ui.saved.length,0);assert.equal(ui.record.status,'draft');assert.equal(ui.rendered.length,0);assert.ok(ui.messages.length>0);
    });
    test(sheet+' preserves draft status and reports storage failures',()=>{
        const ui=directPost(sheet,{failSave:true});assert.equal(ui.post(),false);assert.equal(ui.record.status,'draft');assert.equal(ui.saved.length,0);assert.equal(ui.rendered.length,0);assert.match(ui.messages.at(-1),/Storage full/);
    });
}


for(const sheet of ['cwt','wtp'])test(sheet+' direct Post generates a blank certificate number without opening a popup',()=>{
    const ui=directPost(sheet,{certificate:''});assert.equal(ui.post(),true);
    assert.equal(ui.saved[0][0].certificate,ui.record.number);
});
