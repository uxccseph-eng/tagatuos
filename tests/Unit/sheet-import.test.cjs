const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const engine=require('../../public/js/sheet-import.js');
function workspace(storage=new Map()){
    const accounts=[['1010','Cash on Hand','Asset',true],['1030','BDO Checking','Asset',true],['1150','Prepaid CWT','Asset'],['2010','Accounts Payable - Trade','Liability'],['4000','Merchandise Sales','Income'],['4020','Sales - Services','Income'],['7110','Rent Expense','Expense']].map(([code,name,type,is_cash_bank],index)=>({id:index+1,code,name,type,is_cash_bank:Boolean(is_cash_bank)}));
    const window={addEventListener(){},WorkspaceAccounts:accounts,WorkspaceMasters:{parties:[{id:1,code:'CUS001',name:'Import Customer',classification:'Customer',tin:'000-000-000-00000'},{id:2,code:'SUP001',name:'Import Supplier',classification:'Supplier',tin:'000-000-000-00000'},{id:3,code:'OLD',name:'Old Party',classification:'Both',archived:true}],taxCodes:[{code:'VAT',name:'VAT',kind:'VAT',rate:12},{code:'NONVAT',name:'Non-VAT',kind:'Exempt',rate:0}],banks:[],atcCodes:[{code:'WC160',name:'Services',rate:2,category:'Income-tax CWT'},{code:'WC100',name:'Goods',rate:1,category:'Income-tax CWT'},{code:'WC120',name:'Rent',rate:5,category:'Income-tax CWT'}]}};
    const context={window,document:{getElementById:()=>({textContent:'{"period":"2026-10"}',dataset:{key:'import-qa'}}),querySelectorAll:()=>[],addEventListener(){}},sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)},location:{reload(){}}};
    vm.createContext(context);for(const file of ['sheet-masters.js','workspace-ledger.js'])vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js',file),'utf8'),context);
    return {ledger:window.KontaduryaLedger,masters:window.SheetMasters,accounts,storage};
}
function csv(sheet,rows){return engine.template(sheet)+rows.map(row=>engine.columns[sheet].map(header=>'"'+String(row[header] ?? '').replace(/"/g,'""')+'"').join(',')).join('\r\n');}
function sale(overrides={}){return {Date:'2026-10-19',Customer:'CUS001','Gross Amount':'112.00','Revenue Account':'4000',Terms:'On Account','Invoice Reference':'IMPORT-SI-001',...overrides};}
function purchase(overrides={}){return {Date:'2026-10-19',Supplier:'SUP001','Gross Amount':'112.00',Account:'7110',Terms:'On Account','Invoice Reference':'IMPORT-BILL-001',...overrides};}
const preview=(sheet,rows,context)=>engine.preview(sheet,csv(sheet,rows),context);
function post(sheet,rows,context){const result=preview(sheet,rows,context);assert.deepEqual(result.errors,[]);return context.ledger.postImport(sheet,result.records,'QA');}
test('CSV parser handles BOM, CRLF, quoted commas, escaped quotes and multiline cells',()=>{
    assert.deepEqual(engine.parseCsv('\uFEFFA,B\r\n"A, B","Line 1\nLine ""2"""\r\n'),[['A','B'],['A, B','Line 1\nLine "2"']]);
    assert.throws(()=>engine.parseCsv('A\n"unclosed'),/unclosed/);assert.throws(()=>engine.parseCsv('A\n"closed"oops'),/Unexpected/);
});
test('all seven templates contain the exact fields and reject header-only files',()=>{
    for(const sheet of Object.keys(engine.methods)){assert.deepEqual(engine.parseCsv(engine.template(sheet))[0],engine.columns[sheet]);assert.throws(()=>engine.preview(sheet,engine.template(sheet),workspace()),/Add entry rows/);}
});
test('amounts retain cents exactly and dates reject rollover and ambiguous formats',()=>{
    assert.equal(engine.amount('₱ 1,234.56'),123456);assert.equal(engine.amount('',undefined,true),0);assert.throws(()=>engine.amount('1.234'),/decimal/);assert.throws(()=>engine.amount('-1'),/number/);assert.throws(()=>engine.amount('0'),/greater/);assert.equal(engine.date('10/19/2026'),'2026-10-19');assert.throws(()=>engine.date('2026-02-30'),/real date/);
});
test('unknown or repeated headers and oversized batches are rejected',()=>{
    const context=workspace();assert.throws(()=>engine.preview('sales','Date,Date\n2026-10-19,2026-10-19',context),/columns/);assert.throws(()=>engine.preview('sales','Surprise\nvalue',context),/columns/);assert.throws(()=>preview('sales',Array.from({length:501},()=>sale()),context),/500/);assert.throws(()=>engine.preview('sales','x'.repeat(5*1024*1024+1),context),/5 MB/);
});
test('preview resolves Masters without consuming numbers or changing storage and retains an existing draft',()=>{
    const context=workspace(),draft={...context.ledger.sales()[0],id:4,number:'SI-000004',status:'draft',customer:'Draft customer'};context.ledger.saveSales([...context.ledger.sales(),draft]);
    const before=JSON.stringify(context.ledger.sales()),stored=JSON.stringify([...context.storage]);const result=preview('sales',[sale({'Revenue Account':'4000 · Merchandise Sales','Deposit Account':'1010 · Cash on Hand'})],context);
    assert.deepEqual(result.errors,[]);assert.equal(result.records[0].customer,'Import Customer');assert.equal(result.records[0].tax,'12% VAT');assert.equal(JSON.stringify(context.ledger.sales()),before);assert.equal(JSON.stringify([...context.storage]),stored);
    const posted=context.ledger.postImport('sales',result.records);assert.equal(posted.records[0].number,'SI-000005');assert.equal(context.ledger.sales().filter(record=>record.status==='draft').length,1);
});
test('Sales batch posts cash receipts automatically and leaves on-account invoices unpaid',()=>{
    const context=workspace(),before=context.ledger.collections().length;post('sales',[sale({Terms:'Cash'}),sale({'Invoice Reference':'IMPORT-SI-002'})],context);
    assert.equal(context.ledger.collections().length,before+1);const invoices=context.ledger.invoices().filter(record=>record.customer==='Import Customer');assert.equal(invoices[0].balance,0);assert.equal(invoices[1].balance,11200);assert.equal(context.ledger.sales().at(-1).memo,'');assert.equal(workspace(context.storage).ledger.sales().at(-1).reference,'IMPORT-SI-002');
});
test('duplicates within CSV, existing invoices and stale previews block posting atomically',()=>{
    const context=workspace();assert.match(preview('sales',[sale(),sale()],context).errors[0].message,/Duplicate/);const result=preview('sales',[sale()],context);context.ledger.postImport('sales',result.records);const before=JSON.stringify([...context.storage]);assert.throws(()=>context.ledger.postImport('sales',result.records),/Duplicate/);assert.equal(JSON.stringify([...context.storage]),before);
    assert.match(preview('sales',[sale({'Gross Amount':'500'})],context).errors[0].message,/reference already/);
});
test('invalid Masters and amounts report CSV row numbers; purchase references are optional',()=>{
    const context=workspace();for(const change of [{Customer:'OLD'},{'Revenue Account':'7110'},{'Gross Amount':'1.234'},{'Income Withholding ATC':'WC999'}]){const result=preview('sales',[sale(change)],context);assert.equal(result.errors[0].row,2);}
    assert.equal(preview('purchases',[purchase({'Invoice Reference':''})],context).errors.length,0);
});
test('Purchases import handles optional descriptions and creates only cash payments',()=>{
    const context=workspace(),before=context.ledger.payments().length;post('purchases',[purchase({Terms:'Cash'}),purchase({'Invoice Reference':'IMPORT-BILL-002','Income Withholding ATC':'WC120'})],context);
    assert.equal(context.ledger.payments().length,before+1);assert.equal(context.ledger.purchases().at(-1).ewt,5);assert.equal(context.ledger.purchases().at(-1).ewtCode,'WC120');assert.equal(context.ledger.purchases().at(-1).description,'');
});
test('Collections inherit legacy source parties and validate cumulative batch balance',()=>{
    const context=workspace(),row={Date:'2026-10-19','Source Invoice':'SI-000002',Amount:'5000',Reference:'COL-ONE'};const before=context.ledger.collections().length;
    const invalid=preview('collections',[row,{...row,Reference:'COL-TWO'}],context);assert.match(invalid.errors[0].message,/exceed/);assert.equal(context.ledger.collections().length,before);
    const result=post('collections',[{...row,Amount:'100',Reference:'COL-A'},{...row,Amount:'200',Reference:'COL-B'}],context);assert.equal(result.count,2);assert.equal(result.records[0].customer,'Cruz Dental Clinic Corp.');
});
test('Payments inherit suppliers, reject overpayment and allow standalone expense rows',()=>{
    const context=workspace();post('purchases',[purchase()],context);const bill=context.ledger.purchases().at(-1),row={Date:'2026-10-19','Source Bill':bill.number,Amount:'70',Reference:'PAY-A'};
    const invalid=preview('payments',[row,{...row,Reference:'PAY-B'}],context);assert.match(invalid.errors[0].message,/remaining bill balance/);
    const result=post('payments',[{...row,Amount:'50'},{Date:'2026-10-19',Supplier:'SUP001',Amount:'10',Account:'7110',Reference:'EXP-A'}],context);assert.equal(result.count,2);assert.equal(result.records[0].supplier,'Import Supplier');assert.equal(result.records[1].type,'Direct Expense');
});
test('CWT imports source amounts and explicit TIN and prevents duplicate certificates',()=>{
    const context=workspace(),row={Date:'2026-10-19','Source Invoice':'SI-000002','Customer TIN':'000-000-000-00000','Certificate Number':'CWT-QA-1'};const result=post('cwt',[row],context);assert.equal(result.records[0].amount,context.ledger.cwtSource(2).withheld);assert.equal(result.records[0].offset,'1150 · Prepaid CWT');assert.match(preview('cwt',[{...row,'Certificate Number':'CWT-QA-2'}],context).errors[0].message,/already/);
});
test('WTP imports linked withholding and resolves the active offset account',()=>{
    const context=workspace(),row={Date:'2026-10-19','Source Bill':'BILL-000002','Supplier TIN':'000-000-000-00000','Certificate Number':'WTP-QA-1'};const result=post('wtp',[row],context);assert.equal(result.records[0].taxBase,context.ledger.wtpSource(2).taxBase);assert.equal(result.records[0].ewt,5);assert.equal(result.records[0].supplier,'VARIOUS SUPPLIERS');assert.match(preview('wtp',[{...row,'Certificate Number':'WTP-QA-2'}],context).errors[0].message,/already/);
});
test('Journal groups lines into balanced vouchers with optional particulars',()=>{
    const context=workspace(),base={'Voucher Key':'J1',Date:'2026-10-19',Reference:'J-IMPORT-1'};const rows=[{...base,Account:'1010',Debit:'100'},{...base,Account:'4000',Credit:'100'}];const result=post('journal',rows,context);assert.equal(result.count,1);assert.equal(result.records[0].lines.length,2);assert.equal(result.records[0].particulars,'');
    assert.match(preview('journal',rows.map((row,index)=>({...row,Reference:'J-OTHER',Credit:index?'90':''})),context).errors[0].message,/equal/);assert.match(preview('journal',[rows[0],{...rows[1],Reference:'Different'}],context).errors[0].message,/matching voucher/);
});
test('failed commits and storage failures roll back the whole batch and numbering',()=>{
    const context=workspace(),result=preview('sales',[sale(),sale({'Invoice Reference':'IMPORT-SI-002'})],context),before=JSON.stringify(context.ledger.sales());
    const invalid=structuredClone(result.records);invalid[1].gross=0;assert.throws(()=>context.ledger.postImport('sales',invalid),/positive amount/);assert.equal(JSON.stringify(context.ledger.sales()),before);
    context.storage.set=()=>{throw Error('Full');};assert.throws(()=>context.ledger.postImport('sales',result.records),/storage|space|saved/i);assert.equal(JSON.stringify(context.ledger.sales()),before);
});


test('Sales, Purchases, Collections, Payments and Journal imports allow blank external references',()=>{
    const context=workspace();
    assert.equal(post('sales',[sale({'Invoice Reference':''})],context).records[0].reference,'');
    assert.equal(post('purchases',[purchase({'Invoice Reference':''})],context).records[0].reference,'');
    assert.equal(post('collections',[{Date:'2026-10-19',Customer:'CUS001',Amount:'10','Entry Type':'Advance',Reference:''}],context).records[0].reference,'');
    assert.equal(post('payments',[{Date:'2026-10-19',Supplier:'SUP001',Amount:'10',Account:'7110',Reference:''}],context).records[0].reference,'');
    const base={'Voucher Key':'NO-REF',Date:'2026-10-19',Reference:''};
    assert.equal(post('journal',[{...base,Account:'1010',Debit:'10'},{...base,Account:'4000',Credit:'10'}],context).records[0].reference,'');
});

test('CWT and WTP batch posting generates omitted certificate numbers and retains duplicate checks',()=>{
    const context=workspace(),cwt={Date:'2026-10-19','Source Invoice':'SI-000002','Customer TIN':'000-000-000-00000'},wtp={Date:'2026-10-19','Source Bill':'BILL-000002','Supplier TIN':'000-000-000-00000'};
    const received=post('cwt',[cwt],context).records[0],issued=post('wtp',[wtp],context).records[0];
    assert.equal(received.certificate,received.number);assert.equal(issued.certificate,issued.number);
    assert.ok(preview('cwt',[cwt],context).errors.length);assert.ok(preview('wtp',[wtp],context).errors.length);
    assert.equal(workspace(context.storage).ledger.cwtCertificates().at(-1).certificate,received.number);
    assert.equal(workspace(context.storage).ledger.wtpCertificates().at(-1).certificate,issued.number);
});
