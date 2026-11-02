const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const engine=require('../../public/js/sheet-import.js');
function workspace(storage=new Map()){
    const accounts=[['1010','Cash on Hand','Asset',true],['1030','BDO Checking','Asset',true],['1150','Prepaid CWT','Asset'],['2010','Accounts Payable - Trade','Liability'],['4000','Merchandise Sales','Income'],['4020','Sales - Services','Income'],['7110','Rent Expense','Expense']].map(([code,name,type,is_cash_bank],index)=>({id:index+1,code,name,type,is_cash_bank:Boolean(is_cash_bank)}));
    const window={addEventListener(){},WorkspaceAccounts:accounts,WorkspaceMasters:{parties:[{id:1,code:'CUS001',name:'Import Customer',classification:'Customer',tin:'000-000-000-00000'},{id:2,code:'SUP001',name:'Import Supplier',classification:'Supplier',tin:'000-000-000-00000'},{id:3,code:'OLD',name:'Old Party',classification:'Both',archived:true}],taxCodes:[{code:'VAT',name:'VAT',kind:'VAT',rate:12},{code:'NONVAT',name:'Non-VAT',kind:'Exempt',rate:0}],banks:[],atcCodes:[{code:'WC160',name:'Services',rate:2,category:'Income-tax CWT'},{code:'WC100',name:'Goods',rate:1,category:'Income-tax CWT'},{code:'WC120',name:'Rent',rate:5,category:'Income-tax CWT'}]}};
    const context={window,document:{getElementById:()=>({textContent:'{"period":"2026-10"}',dataset:{key:'import-qa'}}),querySelectorAll:()=>[],addEventListener(){}},sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)},location:{reload(){}}};
    vm.createContext(context);for(const file of ['sheet-masters.js','workspace-ledger.js'])vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js',file),'utf8'),context);
    return {ledger:window.KontaduryaLedger,masters:window.SheetMasters,accounts,storage,catalog:window.WorkspaceMasters};
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
    const posted=context.ledger.postImport('sales',result.records);assert.equal(posted.records[0].number,'SI-000004');assert.equal(context.ledger.sales().filter(record=>record.status==='draft').length,1);
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

test('guided mapping guesses familiar headers and keeps ambiguous columns unmapped',()=>{
    const mapping=engine.guessMapping('sales',['Invoice date','Customer name','Total amount','Account code','VAT','ATC','Invoice no']);
    assert.equal(mapping.Date,0);assert.equal(mapping.Customer,1);assert.equal(mapping['Gross Amount'],2);assert.equal(mapping['Revenue Account'],3);assert.equal(mapping['Income Withholding ATC'],5);
    assert.equal(engine.guessMapping('sales',['Customer','Client']).Customer,null);
    const matrix=[['Title'],['Date','Customer','Amount'],['2026-10-19','CUS001','112']];
    const rows=engine.mapRows('sales',matrix,{Date:0,Customer:1,'Gross Amount':2},2);
    assert.equal(rows[0]._row,3);assert.equal(rows[0].values.Customer,'CUS001');
    assert.throws(()=>engine.mapRows('sales',matrix,{Date:0,Customer:0},2),/one field/);
    assert.throws(()=>engine.mapRows('sales',matrix,{Surprise:0},2),/Unsupported/);
    assert.throws(()=>engine.mapRows('sales',Array.from({length:502},()=>['data']),{Customer:0}),/500/);
});

test('guided review requires a tax choice, resolves Masters, and skips excluded errors',()=>{
    const context=workspace(),rows=[{_row:2,values:sale()},{_row:3,values:sale({Customer:'Unknown'})}];
    let result=engine.previewMapped('sales',rows,context);assert.match(result.errors[0].message,/Tax Type/);
    rows[0].values['Tax Type']='VAT';rows[1].skip=true;
    result=engine.previewMapped('sales',rows,context);assert.deepEqual(result.errors,[]);assert.equal(result.included,1);assert.equal(result.totalGross,11200);
    rows[0].skip=true;assert.match(engine.previewMapped('sales',rows,context).errors[0].message,/No rows/);
});

test('guided suggestions fill only blanks and retain current Masters rates',()=>{
    const context=workspace();context.masters.partyDefaults=()=>({values:{account:'4000 · Merchandise Sales',tax:'12% VAT',cwt:'WC160 · 2%'},sourceNumber:'SI-000099'});
    const rows=[{_row:2,values:sale({'Revenue Account':'','Tax Type':'','Income Withholding ATC':'None'})}];
    let result=engine.previewMapped('sales',rows,context);assert.deepEqual(result.errors,[]);assert.equal(result.records[0].account,'4000 · Merchandise Sales');assert.equal(result.records[0].cwt,'None');assert.equal(result.records[0]._suggestion.source,'SI-000099');
    rows[0].values['Revenue Account']='4020';rows[0].values['Tax Type']='NONVAT';result=engine.previewMapped('sales',rows,context);assert.equal(result.records[0].tax,'Non-VAT');assert.equal(result.records[0].account,'4020 · Sales - Services');
});

test('guided review blocks exact duplicates and warns on missing-reference matches',()=>{
    const context=workspace();post('sales',[sale()],context);
    const rows=[{_row:2,values:sale({'Tax Type':'VAT'})}];
    assert.match(engine.previewMapped('sales',rows,context).errors[0].message,/Duplicate/);
    rows[0].values['Invoice Reference']='';let result=engine.previewMapped('sales',rows,context);assert.equal(result.warnings.length,1);assert.equal(result.warnings[0].row,2);
    rows[0].values['Invoice Reference']='ANOTHER';result=engine.previewMapped('sales',rows,context);assert.equal(result.warnings.length,0);
});

test('bulk correction touches only selected included blanks unless replacement is requested',()=>{
    const {applyBulk}=require('../../public/js/guided-sheet-import.js');
    const rows=[{selected:true,values:{Account:''},duplicateReviewed:true},{selected:true,values:{Account:'7110'}},{selected:true,skip:true,values:{Account:''}},{values:{Account:''}}];
    applyBulk(rows,'Account','4000');assert.equal(rows[0].values.Account,'4000');assert.equal(rows[0].duplicateReviewed,false);assert.equal(rows[1].values.Account,'7110');assert.equal(rows[2].values.Account,'');assert.equal(rows[3].values.Account,'');
    applyBulk(rows,'Account','4020',true);assert.equal(rows[1].values.Account,'4020');
});

test('batch signatures ignore source order and review metadata and repeat posting is blocked',()=>{
    const {canonicalBatch}=require('../../public/js/guided-sheet-import.js'),context=workspace(),records=preview('sales',[sale(),sale({'Invoice Reference':'TWO'})],context).records;
    assert.equal(canonicalBatch('sales',records),canonicalBatch('sales',[...records].reverse().map(r=>({...r,_row:99,_suggestion:{source:'ignored'}}))));
    const hash='a'.repeat(64);context.ledger.postImport('sales',records,'QA',hash);const before=JSON.stringify(context.ledger.sales());
    assert.equal(context.ledger.sales().at(-1).importBatchKey,hash);assert.equal(context.ledger.sales().at(-1)._suggestion,undefined);
    assert.throws(()=>context.ledger.postImport('sales',records,'QA',hash),/already/);assert.equal(JSON.stringify(context.ledger.sales()),before);
});

test('Excel clipboard parser preserves tabs, quoted multiline cells, commas, leading zeros and source row gaps',()=>{
    const cells=engine.parseClipboard('\uFEFFDate\tCustomer\tAmount\tDescription\r\n2026-10-01\t"ABC, INC"\t1120.00\t"Line 1\r\nLine ""2"""\r\n\r\n2026-10-02\t0000123\t2240.00\t\r\n');
    assert.deepEqual(cells[0],['Date','Customer','Amount','Description']);assert.equal(cells[1][1],'ABC, INC');assert.equal(cells[1][3],'Line 1\r\nLine "2"');assert.deepEqual(cells[2],['']);assert.equal(cells[3][1],'0000123');assert.equal(cells[3][3],'');
    assert.deepEqual(engine.parseClipboard('Only one column\nnext row'),[['Only one column'],['next row']]);
});

test('clipboard input blocks malformed, empty, excessive and binary batches before review',()=>{
    for(const text of ['', '\t\r\n ', '"unclosed'])assert.throws(()=>engine.parseClipboard(text),/Paste|unclosed/);
    assert.throws(()=>engine.parseClipboard('x'.repeat(5*1024*1024+1)),/5 MB/);
    assert.throws(()=>engine.parseClipboard('a\0b'),/binary/);
    assert.throws(()=>engine.parseClipboard(Array(522).fill('x').join('\n')),/500/);
    assert.throws(()=>engine.parseClipboard(Array(101).fill('x').join('\t')),/100 columns/);
    assert.throws(()=>engine.parseClipboard('x'.repeat(4001)),/4,000/);
});

test('pasted Sales and Purchases share Masters validation, exact duplicate checks and atomic import preview',()=>{
    const context=workspace();
    for(const sheet of ['sales','purchases']){
        const person=sheet==='sales'?'Customer':'Supplier',account=sheet==='sales'?'Revenue Account':'Account';
        const matrix=engine.parseClipboard(`Date\t${person}\tAmount\t${account}\tTax Type\n2026-10-01\t${sheet==='sales'?'CUS001':'SUP001'}\t112.00\t${sheet==='sales'?'4000':'7110'}\tVAT`);
        const rows=engine.mapRows(sheet,matrix,engine.guessMapping(sheet,matrix[0]));
        const before=JSON.stringify([...context.storage]);const result=engine.previewMapped(sheet,rows,context);assert.deepEqual(result.errors,[]);assert.equal(result.records[0].gross,11200);assert.equal(JSON.stringify([...context.storage]),before);
        assert.match(engine.previewMapped(sheet,[rows[0],{...rows[0],_row:3}],context).errors[0].message,/Duplicate/);
        rows[0].values[person]='Unknown';assert.match(engine.previewMapped(sheet,rows,context).errors[0].message,/Masters/);
    }
});

test('fill down follows source order, preserves existing values and excludes skipped and unselected rows',()=>{
    const {fillDown}=require('../../public/js/guided-sheet-import.js');
    const rows=[{_row:4,selected:true,values:{Date:'2026-10-04'}},{_row:2,selected:true,values:{Date:'2026-10-02'}},{_row:3,selected:true,values:{Date:''},duplicateReviewed:true},{_row:1,selected:true,skip:true,values:{Date:'2026-10-01'}},{_row:5,values:{Date:''}}];
    fillDown(rows,'Date');assert.equal(rows[0].values.Date,'2026-10-04');assert.equal(rows[2].values.Date,'2026-10-02');assert.equal(rows[2].duplicateReviewed,false);assert.equal(rows[3].values.Date,'2026-10-01');assert.equal(rows[4].values.Date,'');
    fillDown(rows,'Date',true);assert.equal(rows[0].values.Date,'2026-10-02');
    assert.throws(()=>fillDown([rows[0]],'Date'),/at least two/);assert.throws(()=>fillDown(rows,'Account'),/first selected source row/);
});


test('blank Sales CSV cells inherit client settings and explicit values override them',()=>{
    const ws=workspace();
    ws.catalog.atcCodes.push({code:'WB030',name:'VAT withholding',rate:3,category:'VAT Withholding'});
    ws.catalog.preferences={sales:{terms:'On Account',classification:'Government',bankCode:'1030',cwtCode:'WC160',vatCwtCode:'WB030',hideCwt:true}};
    const blank=sale({Terms:'',Classification:'','Deposit Account':'','Income Withholding ATC':'','VAT Withholding ATC':''});
    const result=engine.preview('sales',csv('sales',[blank]),ws);
    assert.deepEqual(result.errors,[]);
    assert.equal(result.records[0].terms,'On Account');assert.equal(result.records[0].classification,'Government');
    assert.equal(result.records[0].bank,'1030 \u00b7 BDO Checking');assert.equal(result.records[0].cwt,'WC160 \u00b7 2%');assert.equal(result.records[0].vatCwt,'WB030 \u00b7 3%');
    const explicit=engine.preview('sales',csv('sales',[{...blank,Terms:'Cash',Classification:'Regular','Deposit Account':'1010','Income Withholding ATC':'None','VAT Withholding ATC':'0%'}]),ws);
    assert.deepEqual(explicit.errors,[]);assert.equal(explicit.records[0].terms,'Cash');assert.equal(explicit.records[0].classification,'Regular');assert.equal(explicit.records[0].bank,'1010 \u00b7 Cash on Hand');assert.equal(explicit.records[0].cwt,'None');assert.equal(explicit.records[0].vatCwt,'None');
});

test('guided Sales imports apply the same defaults for unmapped cells without changing source values',()=>{
    const ws=workspace();ws.catalog.preferences={sales:{terms:'On Account',classification:'Government',bankCode:'1030'}};
    const row={_row:2,skip:false,values:sale({Terms:'','Tax Type':'VAT'})};
    const result=engine.previewMapped('sales',[row],ws);
    assert.deepEqual(result.errors,[]);assert.equal(result.records[0].terms,'On Account');assert.equal(result.records[0].classification,'Government');assert.equal(result.records[0].bank,'1030 \u00b7 BDO Checking');assert.equal(row.values.Terms,'');
});

test('Sales starts with Cash, Regular and Cash in Bank while Purchases keeps its existing defaults',()=>{
    const ws=workspace();ws.accounts.push({id:100,code:'1020',name:'Cash in Bank',type:'Asset',is_cash_bank:true});
    const sales=engine.preview('sales',csv('sales',[sale({Terms:''})]),ws);
    assert.deepEqual(sales.errors,[]);assert.equal(sales.records[0].terms,'Cash');assert.equal(sales.records[0].classification,'Regular');assert.equal(sales.records[0].bank,'1020 \u00b7 Cash in Bank');
    const purchases=engine.preview('purchases',csv('purchases',[purchase({Terms:''})]),ws);
    assert.deepEqual(purchases.errors,[]);assert.equal(purchases.records[0].bank,'1010 \u00b7 Cash on Hand');
});

test('invalid explicit and archived configured deposit accounts are rejected instead of silently substituted',()=>{
    const ws=workspace();ws.catalog.preferences={sales:{bankCode:'1030'}};
    const invalid=engine.preview('sales',csv('sales',[sale({'Deposit Account':'NOT-AN-ACCOUNT'})]),ws);
    assert.equal(invalid.errors.length,1);
    ws.accounts.find(a=>a.code==='1030').archived=true;
    const archived=engine.preview('sales',csv('sales',[sale({'Deposit Account':''})]),ws);
    assert.equal(archived.errors.length,1);
    const other=workspace();assert.equal(other.masters.salesDefaults().terms,'Cash');assert.equal(other.masters.salesDefaults().hideCwt,false);
});
