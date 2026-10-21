const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../../public/js/workspace-ledger.js'),'utf8');
test('purchase VAT and withholding use cent arithmetic and balance the journal',()=>{
    const ledger=openLedger();
    const bill=ledger.purchases()[1];const amounts=ledger.purchaseAmounts(bill);
    assert.equal(amounts.gross,20000);assert.equal(amounts.net,17857);assert.equal(amounts.vat,2143);assert.equal(amounts.ewt,893);assert.equal(amounts.payable,19107);
    const withVatWithholding=ledger.purchaseAmounts({...bill,wvat:5});
    assert.equal(withVatWithholding.net+withVatWithholding.vat,withVatWithholding.ewt+withVatWithholding.wvat+withVatWithholding.payable);
    for(const tax of ['Non-VAT','Zero-Rated','Exempt']){
        const result=ledger.purchaseAmounts({...bill,tax});assert.equal(result.vat,0);assert.equal(result.net,20000);assert.equal(result.ewt,1000);
    }
});
test('purchase receipts and void audit persist independently of sales and collections',()=>{
    const storage=new Map(),ledger=openLedger(storage);const sales=JSON.stringify(ledger.sales()),collections=JSON.stringify(ledger.collections());
    const purchases=ledger.purchases();purchases[0].status='void';purchases[0].voidReason='Duplicate invoice';purchases[0].attachments=[{name:'receipt.pdf',data:'data:application/pdf;base64,cWE='}];
    ledger.savePurchases(purchases);ledger.saveSales(ledger.sales());ledger.saveCollections(ledger.collections());
    const reloaded=openLedger(storage);assert.equal(reloaded.purchases()[0].voidReason,'Duplicate invoice');assert.equal(reloaded.purchases()[0].attachments[0].name,'receipt.pdf');
    assert.equal(JSON.stringify(reloaded.sales()),sales);assert.equal(JSON.stringify(reloaded.collections()),collections);
    assert.notEqual(openLedger(storage,'another-company').purchases()[0].status,'void');
});
function openLedger(storage=new Map(),key='company-a',catalog=null,masters=null,depreciation=[]) {
    const context={document:{getElementById:()=>({textContent:'{"period":"2026-10"}',dataset:{key}}),querySelectorAll:()=>[]},sessionStorage:{getItem:key=>storage.get(key) || null,setItem:(key,value)=>storage.set(key,value)},window:{addEventListener:()=>{},WorkspaceDepreciationJournals:depreciation},location:{reload:()=>{}}};
    if(catalog)context.window.WorkspaceAccounts=catalog;
    vm.createContext(context);if(masters){context.window.WorkspaceMasters=masters;context.document.addEventListener=()=>{};vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../../public/js/sheet-masters.js'),'utf8'),context);}vm.runInContext(source,context);
    return context.window.KontaduryaLedger;
}

function issuedCertificate(ledger,overrides={}) {
    const source=ledger.wtpSource(2);
    return {...ledger.nextWtpCertificate(),date:'2026-10-16',supplier:source.supplier,tin:'123-456-789-000',billId:source.id,bill:source.number,taxBase:source.taxBase,ewt:source.ewt,wvat:source.wvat,offset:'2010 · Accounts Payable - Trade',certificate:'ISSUED-QA-001',status:'posted',...overrides};
}

test('issued withholding copies source bill calculations including opaque purchase identifiers',()=>{
    const ledger=openLedger();const source=ledger.wtpSource(2);
    assert.equal(source.taxBase,17857);assert.equal(source.withheld,893);
    const totals=ledger.wtpAmounts({taxBase:17857,ewt:5,wvat:5});assert.equal(totals.ewt,893);assert.equal(totals.wvat,893);assert.equal(totals.total,1786);
    const bills=ledger.purchases();bills.push({...bills[1],id:'bill-uuid',number:'BILL-000003',terms:'On Account'});ledger.savePurchases(bills);
    assert.equal(ledger.wtpSource('bill-uuid').number,'BILL-000003');
});
test('issued certificate posting voiding and restoring preserve purchase and payment balances',()=>{
    const storage=new Map(),ledger=openLedger(storage),beforeBills=JSON.stringify(ledger.purchases()),beforePayments=JSON.stringify(ledger.payments());
    const certificate=issuedCertificate(ledger);ledger.saveWtpCertificates([certificate]);
    certificate.status='void';certificate.voidReason='Amended certificate';ledger.saveWtpCertificates([certificate]);
    assert.equal(openLedger(storage).wtpCertificates()[0].voidReason,'Amended certificate');
    certificate.status='posted';ledger.saveWtpCertificates([certificate]);
    assert.equal(JSON.stringify(ledger.purchases()),beforeBills);assert.equal(JSON.stringify(ledger.payments()),beforePayments);
    assert.equal(openLedger(storage,'other-company').wtpCertificates().length,0);
});
test('issued certificates reject duplicate bills and serials invalid TINs and stale source amounts',()=>{
    const ledger=openLedger(),certificate=issuedCertificate(ledger);ledger.saveWtpCertificates([certificate]);
    const duplicate={...certificate,...ledger.nextWtpCertificate(),certificate:'SECOND-SERIAL'};
    assert.throws(()=>ledger.saveWtpCertificates([certificate,duplicate]),/already has a posted/);
    const manual={...duplicate,billId:null,bill:'',certificate:certificate.certificate};assert.throws(()=>ledger.saveWtpCertificates([certificate,manual]),/already has a posted/);
    assert.throws(()=>ledger.saveWtpCertificates([{...certificate,tin:'invalid'}]),/TIN/);
    assert.throws(()=>ledger.saveWtpCertificates([{...certificate,taxBase:10000}]),/source bill/);
    assert.equal(ledger.wtpCertificates()[0].taxBase,17857);
});
test('standalone issued certificates support manual withholding and retained audit numbering',()=>{
    const ledger=openLedger(),manual=issuedCertificate(ledger,{billId:null,bill:'',taxBase:500000,ewt:2,wvat:0});
    ledger.saveWtpCertificates([manual]);assert.equal(ledger.wtpAmounts(manual).total,10000);
    const draft={...ledger.nextWtpCertificate(),status:'draft'};ledger.saveWtpCertificates([manual,draft]);ledger.saveWtpCertificates([manual]);assert.equal(ledger.nextWtpCertificate().number,draft.number);
    ledger.saveWtpCertificates([{...manual,status:'archived',voidReason:'Superseded'}]);assert.equal(ledger.nextWtpCertificate().number,'2307I-000002');
});

test('cash purchases generate one net payment each and stay settled after reload',()=>{
    const storage=new Map(),ledger=openLedger(storage);
    assert.deepEqual(Array.from(ledger.payments(),record=>record.amount),[10000,19107]);
    ledger.savePurchases(ledger.purchases());ledger.savePurchases(ledger.purchases());
    const reloaded=openLedger(storage);assert.equal(reloaded.payments().length,2);
    assert.equal(reloaded.payableBills().every(bill=>bill.balance===0),true);
});
test('voided cash payments reopen the bill without being recreated and block duplicate restoration',()=>{
    const storage=new Map(),ledger=openLedger(storage),payments=ledger.payments();
    payments[0].status='void';payments[0].paymentVoided=true;payments[0].voidReason='Duplicate voucher';ledger.savePayments(payments);
    assert.equal(ledger.payableBills()[0].balance,10000);
    ledger.savePurchases(ledger.purchases());const reloaded=openLedger(storage);
    assert.equal(reloaded.payments().length,2);assert.equal(reloaded.payments()[0].status,'void');
    const replacement={...reloaded.nextPayment(),date:'2026-10-20',supplier:'VARIOUS SUPPLIERS',billId:1,bill:'BILL-000001',amount:10000,bank:'1010 · Cash on Hand',method:'Cash',type:'AP Settlement',status:'posted',origin:'manual'};
    reloaded.savePayments([...reloaded.payments(),replacement]);const restore=reloaded.payments();restore[0].status='posted';restore[0].paymentVoided=false;
    assert.throws(()=>reloaded.savePayments(restore),/exceeds the remaining bill balance/);
    assert.equal(reloaded.payments()[0].status,'void');assert.equal(reloaded.payableBills()[0].balance,0);
});
test('on-account payments support partial settlement and reject atomic overpayments',()=>{
    const ledger=openLedger(),bills=ledger.purchases();bills[0].terms='On Account';ledger.savePurchases(bills);
    assert.equal(ledger.payableBills()[0].balance,10000);
    const payment={...ledger.nextPayment(),date:'2026-10-20',supplier:'VARIOUS SUPPLIERS',billId:1,bill:'BILL-000001',amount:4000,bank:'1010 · Cash on Hand',method:'Bank Transfer',type:'AP Settlement',status:'posted',origin:'manual'};
    ledger.savePayments([...ledger.payments(),payment]);assert.equal(ledger.payableBills()[0].balance,6000);
    const overpayment={...payment,...ledger.nextPayment(),amount:6001};assert.throws(()=>ledger.savePayments([...ledger.payments(),overpayment]),/exceeds/);assert.equal(ledger.payableBills()[0].balance,6000);
    const billEdit=ledger.purchases();billEdit[0].gross=1000;assert.throws(()=>ledger.savePurchases(billEdit),/exceeds/);assert.equal(ledger.purchases()[0].gross,10000);
});
test('direct expenses and supplier advances do not settle bills and drafts release voucher numbers',()=>{
    const ledger=openLedger(),before=ledger.payableBills()[0].balance;
    const direct={...ledger.nextPayment(),date:'2026-10-20',supplier:'PLDT ENTERPRISE',amount:15000,bank:'1010 · Cash on Hand',method:'Cash',type:'Direct Expense',account:'7130 · Telecommunication',status:'posted',billId:null,origin:'manual'};
    ledger.savePayments([...ledger.payments(),direct]);assert.equal(ledger.payableBills()[0].balance,before);
    const draft={...ledger.nextPayment(),status:'draft',date:'2026-10-20',amount:0};ledger.savePayments([...ledger.payments(),draft]);ledger.savePayments(ledger.payments().filter(record=>record.id!==draft.id));assert.equal(ledger.nextPayment().number,draft.number);
    const advance={...direct,...ledger.nextPayment(),type:'Supplier Advance'};ledger.savePayments([...ledger.payments(),advance]);assert.equal(ledger.payableBills()[0].balance,before);
});
test('posted cash sales create exactly one collection each, including after reload',()=>{
    const storage=new Map();const ledger=openLedger(storage);
    ledger.saveSales(ledger.sales());ledger.saveSales(ledger.sales());
    const reloaded=openLedger(storage);
    assert.equal(reloaded.collections().length,2);
    assert.deepEqual(Array.from(reloaded.collections(),record=>record.invoice),['SI-000001','SI-000003']);
    assert.equal(reloaded.collections()[0].amount,49107);
});
test('on-account sale stays unpaid until a partial collection is posted',()=>{
    const ledger=openLedger();
    assert.equal(ledger.collections().some(record=>record.invoice==='SI-000002'),false);
    assert.equal(ledger.invoices().find(sale=>sale.id===2).balance,982143);
    const collection={...ledger.newCollection(),date:'2026-10-20',invoice:'SI-000002',customer:'Cruz Dental Clinic Corp.',amount:200000,type:'Settlement',status:'draft'};
    ledger.saveCollections([...ledger.collections(),collection]);
    assert.equal(ledger.invoices().find(sale=>sale.id===2).balance,982143);
    collection.status='posted';ledger.saveCollections([...ledger.collections().filter(record=>record.id!==collection.id),collection]);
    assert.equal(ledger.invoices().find(sale=>sale.id===2).balance,782143);
});
test('voiding, restoring, and changing terms keep the original linked collection identity',()=>{
    const ledger=openLedger();const sales=ledger.sales();const id=ledger.collections()[0].id;
    sales[0].status='void';ledger.saveSales(sales);assert.equal(ledger.collections()[0].status,'void');
    sales[0].status='posted';ledger.saveSales(sales);assert.equal(ledger.collections()[0].status,'posted');
    sales[0].terms='30 Days';ledger.saveSales(sales);assert.equal(ledger.collections()[0].status,'void');
    assert.equal(ledger.collections()[0].id,id);assert.equal(ledger.invoices()[0].balance,49107);
    sales[0].status='archived';ledger.saveSales(sales);assert.equal(ledger.collections()[0].status,'archived');
});
test('draft sales do not create collections and posting uses the current amount and bank',()=>{
    const ledger=openLedger();const sales=ledger.sales();
    sales.push({...sales[0],id:4,number:'SI-000004',status:'draft',gross:112000,cwt:'None (0%)',bank:'1030 · BDO Checking'});
    ledger.saveSales(sales);assert.equal(ledger.collections().length,2);
    sales[3].status='posted';ledger.saveSales(sales);
    const collection=ledger.collections().find(record=>record.saleId===4);
    assert.equal(collection.amount,112000);assert.equal(collection.bank,'1030 · BDO Checking');
});
test('manual collection voids reopen the invoice and advances do not settle it',()=>{
    const ledger=openLedger();const collection={...ledger.newCollection(),invoice:'SI-000002',amount:982143,type:'Settlement',status:'posted'};
    ledger.saveCollections([...ledger.collections(),collection]);assert.equal(ledger.invoices().find(sale=>sale.id===2).balance,0);
    collection.status='void';ledger.saveCollections([...ledger.collections().filter(record=>record.id!==collection.id),collection]);
    assert.equal(ledger.invoices().find(sale=>sale.id===2).balance,982143);
    collection.status='posted';collection.type='Advance';ledger.saveCollections([...ledger.collections().filter(record=>record.id!==collection.id),collection]);
    assert.equal(ledger.invoices().find(sale=>sale.id===2).balance,982143);
});
test('workspace records remain isolated',()=>{
    const storage=new Map();const first=openLedger(storage,'company-a');const sales=first.sales();sales[0].customer='Workspace A';first.saveSales(sales);
    const second=openLedger(storage,'company-b');assert.equal(second.sales()[0].customer,'VARIOUS CUSTOMERS');
});
test('legacy credit terms migrate to On Account and remain unpaid',()=>{
    const storage=new Map();const ledger=openLedger(storage);const sales=ledger.sales();
    sales[1].terms='30 Days';ledger.saveSales(sales);
    const reloaded=openLedger(storage);
    assert.equal(reloaded.sales()[1].terms,'On Account');
    assert.equal(reloaded.collections().some(record=>record.invoice==='SI-000002'),false);
    assert.equal(reloaded.invoices().find(sale=>sale.id===2).balance,982143);
});
test('voiding a cash collection reopens its invoice without voiding the sale or recreating the receipt',()=>{
    const storage=new Map();const ledger=openLedger(storage);const cash=ledger.collections()[0];
    ledger.voidCollection(cash.id,'Payment cancelled','Demo Owner');
    assert.equal(ledger.sales()[0].status,'posted');
    assert.equal(ledger.invoices()[0].balance,49107);
    ledger.saveSales(ledger.sales());
    const reloaded=openLedger(storage);
    assert.equal(reloaded.collections()[0].status,'void');
    assert.equal(reloaded.collections()[0].voidAudit.reason,'Payment cancelled');
    assert.equal(reloaded.collections().filter(record=>record.saleId===1).length,1);
    reloaded.restoreCollection(cash.id);
    assert.equal(reloaded.collections()[0].status,'posted');
    assert.equal(reloaded.invoices()[0].balance,0);
});
test('restoring an automatic receipt cannot overcollect an invoice already paid by a replacement',()=>{
    const ledger=openLedger();const cash=ledger.collections()[0];
    ledger.voidCollection(cash.id,'Replace receipt','Demo Owner');
    ledger.saveCollections([...ledger.collections(),{...ledger.newCollection(),invoice:cash.invoice,amount:cash.amount,type:'Settlement',status:'posted'}]);
    assert.throws(()=>ledger.restoreCollection(cash.id),/other collections/);
    assert.equal(ledger.collections()[0].status,'void');
    assert.equal(ledger.invoices()[0].balance,0);
});
test('void reasons are required and manual receipt restoration returns to draft',()=>{
    const ledger=openLedger();const record={...ledger.newCollection(),invoice:'SI-000002',amount:10000,type:'Settlement',status:'posted'};
    ledger.saveCollections([...ledger.collections(),record]);
    assert.throws(()=>ledger.voidCollection(record.id,'  ','Demo Owner'),/void reason/);
    assert.equal(ledger.collections().find(item=>item.id===record.id).status,'posted');
    ledger.voidCollection(record.id,'Duplicate receipt','Demo Owner');
    const restored=ledger.restoreCollection(record.id);
    assert.equal(restored.status,'draft');
    assert.equal(restored.voidAudit.reason,'Duplicate receipt');
});
test('editing a void memo preserves the original reason and financial values',()=>{
    const ledger=openLedger();const cash=ledger.collections()[0];
    ledger.voidCollection(cash.id,'Original reason','Demo Owner');
    const updated=ledger.editCollectionVoidMemo(cash.id,'Corrected explanation','Reviewer');
    assert.equal(updated.amount,cash.amount);assert.equal(updated.status,'void');
    assert.equal(updated.voidAudit.reason,'Corrected explanation');assert.equal(updated.voidAudit.editedBy,'Reviewer');
    assert.equal(updated.memoHistory[0].reason,'Original reason');assert.equal(updated.voidHistory[0].reason,'Original reason');
});
test('purge is recoverable and preserves void history through reload and sales synchronization',()=>{
    const storage=new Map();const ledger=openLedger(storage);const cash=ledger.collections()[0];
    assert.throws(()=>ledger.archiveVoidedCollection(cash.id),/Only a voided/);
    ledger.voidCollection(cash.id,'Duplicate receipt','Demo Owner');ledger.archiveVoidedCollection(cash.id);ledger.saveSales(ledger.sales());
    const reloaded=openLedger(storage);const archived=reloaded.collections()[0];
    assert.equal(archived.status,'archived');assert.equal(archived.voidAudit.reason,'Duplicate receipt');
    reloaded.unarchiveVoidedCollection(cash.id);reloaded.saveSales(reloaded.sales());
    assert.equal(reloaded.collections()[0].status,'void');assert.equal(reloaded.invoices()[0].balance,49107);
    reloaded.restoreCollection(cash.id);assert.equal(reloaded.invoices()[0].balance,0);
});

test('discarding a trailing draft releases its transaction number but never reuses its internal id',()=>{
    const storage=new Map();const ledger=openLedger(storage);
    const draft={...ledger.newCollection(),status:'draft'};
    assert.equal(draft.number,'CR-000003');
    ledger.saveCollections([...ledger.collections(),draft]);
    ledger.saveCollections(ledger.collections().filter(record=>record.id!==draft.id));
    const replacement=openLedger(storage).newCollection();
    assert.equal(replacement.number,'CR-000003');
    assert.ok(replacement.id>draft.id);
});

test('discarding several trailing drafts resets numbering after the last retained receipt',()=>{
    const ledger=openLedger();
    const first={...ledger.newCollection(),status:'draft'};
    const second={...ledger.newCollection(),status:'draft'};
    ledger.saveCollections([...ledger.collections(),first,second]);
    ledger.saveCollections(ledger.collections().filter(record=>record.status!=='draft'));
    assert.equal(ledger.newCollection().number,'CR-000003');
});

test('discarding an older draft closes numbering gaps while preserving record identities',()=>{
    const ledger=openLedger();
    const draft={...ledger.newCollection(),status:'draft'};
    const posted={...ledger.newCollection(),status:'posted'};
    const voided={...ledger.newCollection(),status:'void'};
    const archived={...ledger.newCollection(),status:'archived'};
    ledger.saveCollections([...ledger.collections(),draft,posted,voided,archived]);
    ledger.saveCollections(ledger.collections().filter(record=>record.id!==draft.id));
    assert.deepEqual(Array.from(ledger.collections().slice(-3),record=>record.number),['CR-000003','CR-000004','CR-000005']);
    assert.equal(ledger.newCollection().number,'CR-000006');
    assert.deepEqual(Array.from(ledger.collections().slice(-3),record=>record.id),[posted.id,voided.id,archived.id]);
});

test('automatic cash collections use the number released by a discarded draft',()=>{
    const ledger=openLedger();const draft={...ledger.newCollection(),status:'draft'};
    ledger.saveCollections([...ledger.collections(),draft]);
    ledger.saveCollections(ledger.collections().filter(record=>record.id!==draft.id));
    const sales=ledger.sales();sales[1].terms='Cash';ledger.saveSales(sales);
    const automatic=ledger.collections().find(record=>record.saleId===2);
    assert.equal(automatic.number,'CR-000003');assert.ok(automatic.id>draft.id);
    assert.equal(ledger.newCollection().number,'CR-000004');
});

test('legacy counters inflated by discarded drafts recover from retained numbers on reload',()=>{
    const storage=new Map();const ledger=openLedger(storage);ledger.saveCollections(ledger.collections());
    const key='kontadurya.ledger.v1.company-a';const state=JSON.parse(storage.get(key));
    state.nextCollectionId=20;delete state.nextCollectionNumber;storage.set(key,JSON.stringify(state));
    const recovered=openLedger(storage).newCollection();
    assert.equal(recovered.number,'CR-000003');assert.equal(recovered.id,20);
});

test('CWT sources use posted sales and copy their existing income withholding separately from final VAT',()=>{
    const ledger=openLedger();
    const source=ledger.cwtSource(2);
    assert.equal(source.taxBase,892857);assert.equal(source.withheld,17857);assert.equal(source.rate,2);
    const sales=ledger.sales();sales[1].status='draft';ledger.saveSales(sales);
    assert.equal(ledger.cwtSource(2),null);
    sales[1].status='posted';sales[1].vatCwt='5% Final VAT';ledger.saveSales(sales);
    assert.equal(ledger.cwtSource(2).withheld,17857);
});

test('CWT records survive reload and sales collection updates without changing invoice balances',()=>{
    const storage=new Map();const ledger=openLedger(storage);const balance=ledger.invoices()[1].balance;
    ledger.saveCwtCertificates([{id:'certificate-1',number:'2307R-000001',saleId:2,amount:17857,status:'posted'}]);
    ledger.saveSales(ledger.sales());ledger.saveCollections(ledger.collections());
    const reloaded=openLedger(storage);
    assert.equal(reloaded.cwtCertificates()[0].number,'2307R-000001');assert.equal(reloaded.invoices()[1].balance,balance);
    assert.equal(openLedger(storage,'company-b').cwtCertificates().length,0);
});
function journalVoucher(ledger,overrides={}) {
    return {...ledger.nextJournalEntry(),date:'2026-10-16',reference:'JV-QA-001',particulars:'Test accrual',type:'Adjusting',status:'posted',lines:[{account:'6110 · Office Supplies',party:'QA Supplier',debit:601,credit:0,remarks:''},{account:'6150 · Packaging Supplies',party:'QA Supplier',debit:400,credit:0,remarks:''},{account:'2010 · Accounts Payable - Trade',party:'QA Supplier',debit:0,credit:1001,remarks:''}],...overrides};
}

test('journal vouchers sum multiple lines using cents and require balanced positive single-sided amounts',()=>{
    const ledger=openLedger(),record=journalVoucher(ledger);
    assert.equal(ledger.journalError(record),null);
    assert.equal(ledger.journalAmounts(record).debit,1001);assert.equal(ledger.journalAmounts(record).credit,1001);assert.equal(ledger.journalAmounts(record).variance,0);
    const invalid=[];
    const copy=()=>JSON.parse(JSON.stringify(record));
    let candidate=copy();candidate.lines[2].credit=1000;invalid.push(candidate);
    candidate=copy();candidate.lines[0].credit=1;invalid.push(candidate);
    candidate=copy();candidate.lines[0].debit=-1;invalid.push(candidate);
    candidate=copy();candidate.lines[0].debit=1.2;invalid.push(candidate);
    candidate=copy();candidate.lines[0].account='Unknown account';invalid.push(candidate);
    candidate=copy();candidate.date='2026-02-30';invalid.push(candidate);
    candidate=copy();candidate.lines=[record.lines[0]];invalid.push(candidate);
    for(const entry of invalid){entry.draftSaved=true;assert.ok(ledger.journalError(entry));assert.throws(()=>ledger.saveJournalEntries([entry]));}
    assert.equal(ledger.journalEntries().length,0);
});

test('journal posting is atomic and remains independent of source sheet balances through void and restore',()=>{
    const storage=new Map(),ledger=openLedger(storage),snapshot=()=>JSON.stringify([ledger.sales(),ledger.collections(),ledger.purchases(),ledger.payments(),ledger.cwtCertificates(),ledger.wtpCertificates()]),before=snapshot();
    const record=journalVoucher(ledger);ledger.saveJournalEntries([record]);
    const invalid=journalVoucher(ledger,{reference:'SECOND'});invalid.lines[2].credit=999;
    assert.throws(()=>ledger.saveJournalEntries([record,invalid]));assert.equal(ledger.journalEntries().length,1);
    record.status='void';assert.throws(()=>ledger.saveJournalEntries([record]),/reason/);
    record.voidReason='Duplicate adjustment';record.history=[{label:'Voided',user:'QA',time:'2026-10-16T12:00:00Z'}];ledger.saveJournalEntries([record]);
    assert.equal(openLedger(storage).journalEntries()[0].voidReason,'Duplicate adjustment');assert.equal(openLedger(storage).journalEntries()[0].lines[0].debit,601);
    record.status='posted';ledger.saveJournalEntries([record]);assert.equal(snapshot(),before);assert.equal(openLedger(storage,'different-company').journalEntries().length,0);
    const result=ledger.journalEntries();result[0].lines[0].debit=999;assert.equal(ledger.journalEntries()[0].lines[0].debit,601);
});

test('journal drafts retain partial work and release trailing numbers while archived vouchers retain theirs',()=>{
    const storage=new Map(),ledger=openLedger(storage),record=journalVoucher(ledger,{status:'draft',draftSaved:true,reference:'',lines:[]});
    ledger.saveJournalEntries([record]);assert.equal(openLedger(storage).journalEntries()[0].draftSaved,true);assert.ok(ledger.journalError(record));
    assert.equal(ledger.nextJournalEntry().number,'GJ-000002');ledger.saveJournalEntries([]);assert.equal(ledger.nextJournalEntry().number,'GJ-000001');
    ledger.saveJournalEntries([journalVoucher(ledger,{status:'archived',voidReason:'Duplicate'})]);assert.equal(ledger.nextJournalEntry().number,'GJ-000002');
});

test('journal storage failures roll back an attempted posting',()=>{
    const storage=new Map(),ledger=openLedger(storage);const record=journalVoucher(ledger);storage.set=()=>{throw new Error('Storage full');};
    assert.throws(()=>ledger.saveJournalEntries([record]),/storage/);assert.equal(ledger.journalEntries().length,0);
});
test('books derive balanced posted vouchers without duplicating linked issued withholding',()=>{
    const ledger=openLedger(),before=JSON.stringify([ledger.sales(),ledger.collections(),ledger.purchases(),ledger.payments()]);
    const entries=ledger.bookEntries();assert.equal(entries.length,9);
    for(const entry of entries)assert.equal(entry.lines.reduce((sum,line)=>sum+line.debit,0),entry.lines.reduce((sum,line)=>sum+line.credit,0),entry.number);
    const sale=entries.find(entry=>entry.number==='SI-000001');assert.equal(sale.lines[0].debit,50000);
    const bill=entries.find(entry=>entry.number==='BILL-000002');assert.equal(bill.lines.find(line=>line.account.startsWith('2010')).credit,19107);assert.equal(bill.lines.find(line=>line.account.startsWith('2140')).credit,893);
    ledger.saveWtpCertificates([issuedCertificate(ledger)]);assert.equal(ledger.bookEntries().length,entries.length);
    const standalone=issuedCertificate(ledger,{...ledger.nextWtpCertificate(),certificate:'STANDALONE-002',billId:null,bill:''});ledger.saveWtpCertificates([...ledger.wtpCertificates(),standalone]);assert.equal(ledger.bookEntries().filter(entry=>entry.sheet==='wtp').length,1);
    assert.equal(JSON.stringify([ledger.sales(),ledger.collections(),ledger.purchases(),ledger.payments()]),before);
});

function bookReport(){const context={window:{}};vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../../public/js/books-report.js'),'utf8'),context);return context.window.BooksReports;}

test('Books date ranges filter every register inclusively and keep balances before From',()=>{
    const ledger=openLedger(),engine=bookReport(),range={from:'2026-10-18',to:'2026-10-18'};
    const journal=engine.build(ledger,'general-journal','2026-10',range);assert.equal(journal.entries,1);assert.equal(journal.rows.every(row=>row.date==='2026-10-18'),true);assert.equal(journal.debit,journal.credit);
    const general=engine.build(ledger,'general-ledger','2026-10',range);assert.equal(general.rows.filter(row=>row.kind==='opening').some(row=>row.balance!==0),true);assert.equal(general.rows.filter(row=>!row.kind).every(row=>row.date==='2026-10-18'),true);assert.equal(general.rows.find(row=>row.kind==='opening').date,range.from);
    for(const name of ['subsidiary-sales','subsidiary-purchase','cash-receipts','cash-disbursements','cash-analysis','settlement-trace']){const report=engine.build(ledger,name,'2026-10',range);assert.equal(report.rows.filter(row=>row.date).every(row=>row.date>=range.from&&row.date<=range.to),true,name);}
});

test('Books date ranges span months without including transactions after To',()=>{
    const ledger=openLedger(),sales=ledger.sales();sales.push({...sales[1],id:4,number:'SI-000004',date:'2026-11-01',reference:'RANGE-IN'});sales.push({...sales[1],id:5,number:'SI-000005',date:'2026-11-02',reference:'RANGE-OUT'});ledger.saveSales(sales);
    const report=bookReport().build(ledger,'subsidiary-sales','2026-10',{from:'2026-10-21',to:'2026-11-01'});assert.deepEqual(Array.from(report.rows,row=>row.number),['SI-000003','SI-000004']);assert.equal(report.salesTotals.gross,sales[2].gross+sales[3].gross);
});

function dashboardReport(){const context={window:{}};vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../../public/js/dashboard-report.js'),'utf8'),context);return context.window.DashboardReports;}

test('database account metadata drives journal choices, normal balances, cash and income totals',()=>{
    const baseline=openLedger(),catalog=baseline.journalAccounts().map(label=>{const [code,name]=label.split(' · ');return {...baseline.accountProfile(code),name};});
    catalog.push({code:'1300',name:'Special Cash',type:'Asset',normal_balance:'Debit',is_cash_bank:true,archived:false},{code:'8100',name:'Other Revenue',type:'Income',normal_balance:'Credit',is_cash_bank:false,archived:false});
    const storage=new Map(),ledger=openLedger(storage,'company-a',catalog),record=journalVoucher(ledger,{lines:[{account:'1300 · Special Cash',party:'',debit:777,credit:0,remarks:''},{account:'8100 · Other Revenue',party:'',debit:0,credit:777,remarks:''}]});
    assert.ok(ledger.journalAccounts().includes('8100 · Other Revenue'));ledger.saveJournalEntries([record]);
    const report=dashboardReport().build(ledger,'2026-10-17');assert.equal(report.cash,20777);assert.equal(report.income,18634);
    const book=bookReport().build(ledger,'general-ledger','2026-10',{account:'8100'});assert.equal(book.rows.find(row=>row.kind==='subtotal').normalSide,'Cr');assert.equal(book.rows.find(row=>row.kind==='subtotal').balance,777);
    catalog.find(account=>account.code==='8100').archived=true;const reloaded=openLedger(storage,'company-a',catalog);assert.equal(reloaded.journalAccounts().includes('8100 · Other Revenue'),false);assert.equal(reloaded.journalError(reloaded.journalEntries()[0]),null);
    assert.match(reloaded.journalError({...record,id:2,number:'GJ-000002'}),/active account/);
});

test('sales certificate badges match posted received certificates and disappear on void',()=>{
    const storage=new Map(),ledger=openLedger(storage);
    const certificate={id:'badge-cwt',number:'2307R-000001',saleId:1,invoice:'SI-000001',status:'draft',amount:893};
    ledger.saveCwtCertificates([certificate]);assert.equal(ledger.receivedCwtForSale(1).length,0);
    certificate.status='posted';ledger.saveCwtCertificates([certificate]);assert.equal(ledger.receivedCwtForSale('1')[0].number,certificate.number);assert.equal(ledger.receivedCwtForSale(2).length,0);assert.equal(ledger.receivedCwtForSale('missing').length,0);
    const copy=ledger.receivedCwtForSale(1);copy[0].status='void';assert.equal(ledger.receivedCwtForSale(1).length,1);assert.equal(openLedger(storage).receivedCwtForSale(1).length,1);
    certificate.status='void';ledger.saveCwtCertificates([certificate]);assert.equal(ledger.receivedCwtForSale(1).length,0);
});

test('dashboard leaves seed samples and unposted drafts in onboarding until a user posts an entry',()=>{
    const ledger=openLedger(),build=dashboardReport().build;assert.equal(build(ledger,'2026-10-17').hasFirstEntry,false);
    const sale={...ledger.sales()[0],id:4,number:'SI-000004',status:'draft'};ledger.saveSales([...ledger.sales(),sale]);assert.equal(build(ledger,'2026-10-17').hasFirstEntry,false);
    sale.status='posted';ledger.saveSales(ledger.sales().map(row=>row.id===sale.id?sale:row));assert.equal(build(ledger,'2026-10-17').hasFirstEntry,true);
});

test('dashboard reports actual ledger controls through today without future entries or expected CWT clearing AR',()=>{
    const ledger=openLedger(),build=dashboardReport().build,before=JSON.stringify(ledger.sales());
    const report=build(ledger,'2026-10-17');assert.equal(report.cash,20000);assert.equal(report.receivable,893);assert.equal(report.payable,0);assert.equal(report.income,17857);assert.equal(report.monthSales,44643);assert.equal(report.monthPurchases,26786);assert.equal(report.netVat,2143);assert.equal(report.posted,6);assert.equal(report.balanced,true);assert.equal(report.buckets.length,12);assert.equal(report.buckets.at(-1).period,'2026-10');assert.equal(report.buckets.at(-1).posted,6);assert.equal(JSON.stringify(ledger.sales()),before);
    ledger.saveCwtCertificates([{id:'dashboard-cwt',number:'2307R-000001',date:'2026-10-17',customer:'VARIOUS CUSTOMERS',amount:893,invoice:'SI-000001',status:'posted'}]);
    assert.equal(build(ledger,'2026-10-17').receivable,0);assert.equal(build(ledger,'2026-10-17').cash,20000);
});

test('dashboard respects fiscal year boundaries and includes manual income and expense adjustments',()=>{
    const ledger=openLedger(),build=dashboardReport().build;
    const accrual=(id,date,amount)=>journalVoucher(ledger,{id,number:'GJ-'+id,date,lines:[{account:'1105 · Accounts Receivable - Trade',party:'QA',debit:amount,credit:0,remarks:''},{account:'4020 · Sales - Services',party:'QA',debit:0,credit:amount,remarks:''}]});
    ledger.saveJournalEntries([accrual(1,'2026-06-30',1000),accrual(2,'2026-07-01',500)]);
    assert.equal(build(ledger,'2026-10-17',6).income,18357);assert.equal(build(ledger,'2026-10-17').income,19357);
    assert.equal(build(ledger,'2026-06-30',6).income,1000);assert.equal(build(ledger,'2026-07-01',6).income,500);
    const journal=ledger.journalEntries();journal[1].status='draft';ledger.saveJournalEntries(journal);assert.equal(build(ledger,'2026-10-17',6).income,17857);
});

test('purchase register reconciles invoice tax bases and withholding without inventing supplier metadata',()=>{
    const ledger=openLedger(),build=bookReport().build;
    const report=build(ledger,'subsidiary-purchase','2026-10'),totals=report.purchaseTotals;
    assert.equal(report.rows.length,2);assert.equal(totals.gross,30000);assert.equal(totals.vatable,26786);assert.equal(totals.vat,3214);assert.equal(totals.ewt,893);assert.equal(totals.net,29107);
    assert.equal(report.rows[0].tin,'');assert.equal(report.rows[0].address,'');
    const bills=ledger.purchases();bills[0].supplier='Different supplier';bills[0].tax='Non-VAT';bills[0].tin='123-456-789';bills[0].address='Supplier address';bills[1].wvat=5;ledger.savePurchases(bills);
    const filtered=build(ledger,'subsidiary-purchase','2026-10',{party:'Different supplier'});assert.equal(filtered.rows.length,1);assert.equal(filtered.purchaseTotals.nonVat,10000);assert.equal(filtered.rows[0].tin,'123-456-789');assert.equal(filtered.rows[0].address,'Supplier address');
    const updated=build(ledger,'subsidiary-purchase','2026-10').purchaseTotals;assert.equal(updated.gross,updated.net+updated.ewt+updated.wvat);assert.equal(updated.wvat,893);
    bills[0].status='draft';bills[1].status='void';ledger.savePurchases(bills);assert.equal(build(ledger,'subsidiary-purchase','2026-10').rows.length,0);
});

test('cash disbursements show actual cash once while keeping source invoice withholding informational',()=>{
    const ledger=openLedger(),report=bookReport().build(ledger,'cash-disbursements','2026-10');
    assert.equal(report.rows.length,2);assert.equal(report.cashTotals.gross,29107);assert.equal(report.cashTotals.net,29107);assert.equal(report.cashTotals.ewt,0);
    const withheld=report.rows.find(row=>row.sourceBill==='BILL-000002');assert.equal(withheld.sourceEwt,893);assert.equal(withheld.net,19107);assert.equal(withheld.ewt,0);assert.equal(withheld.vat,undefined);assert.match(withheld.offset,/2010/);
    assert.equal(report.debit,29107);assert.equal(report.credit,29107);
    assert.equal(bookReport().build(ledger,'cash-disbursements','2026-10',{party:'Unknown supplier'}).rows.length,0);
});

test('cash receipts allocate expected withholding across partial collections and reporting periods exactly once',()=>{
    const ledger=openLedger(),build=bookReport().build,sale=ledger.invoices().find(record=>record.id===2);
    const original=build(ledger,'cash-receipts','2026-10').rows.find(row=>row.sourceInvoice==='SI-000001');assert.equal(original.gross,50000);assert.equal(original.net,49107);assert.equal(original.ewt,893);assert.equal(original.vat,undefined);
    for(const [date,amount] of [['2026-10-20',100001],['2026-11-02',200002],['2026-11-03',sale.total-300003]]){
        ledger.saveCollections([...ledger.collections(),{...ledger.newCollection(),date,invoice:sale.number,customer:sale.customer,amount,type:'Settlement',status:'posted'}]);
    }
    const october=build(ledger,'cash-receipts','2026-10',{party:sale.customer}),november=build(ledger,'cash-receipts','2026-11',{party:sale.customer});
    const rows=[...october.rows,...november.rows].filter(row=>row.sourceInvoice===sale.number);
    assert.equal(rows.length,3);assert.equal(rows.reduce((sum,row)=>sum+row.ewt,0),17857);assert.equal(rows.reduce((sum,row)=>sum+row.net,0),sale.total);assert.equal(rows.reduce((sum,row)=>sum+row.gross,0),sale.gross);
    for(const row of rows){assert.equal(row.gross,row.net+row.ewt+row.wvat);assert.match(row.offset,/1105/);}
    const unfiltered=build(ledger,'cash-receipts','2026-11');assert.equal(unfiltered.cashTotals.ewt,november.cashTotals.ewt);
    ledger.saveCollections([...ledger.collections(),{...ledger.newCollection(),date:'2026-11-04',customer:'Advance customer',amount:5000,type:'Advance',status:'posted'}]);
    const advance=build(ledger,'cash-receipts','2026-11',{party:'Advance customer'}).rows[0];assert.equal(advance.gross,5000);assert.equal(advance.ewt,0);assert.match(advance.offset,/2200/);assert.equal(advance.sourceInvoice,'');
});

test('books reports exclude voids and drafts and retain manual journal and received withholding lines',()=>{
    const ledger=openLedger(),sale=ledger.cwtSource(1);
    ledger.saveCwtCertificates([{id:'received-qa',number:'2307R-000001',date:sale.date,customer:sale.customer,amount:sale.withheld,invoice:sale.number,status:'posted'}]);
    ledger.saveJournalEntries([journalVoucher(ledger),{...journalVoucher(ledger),id:2,number:'GJ-000002',status:'draft'}]);
    assert.equal(ledger.bookEntries().filter(entry=>entry.sheet==='journal').length,1);assert.equal(ledger.bookEntries().find(entry=>entry.sheet==='cwt').lines[0].debit,893);
    const sales=ledger.sales();sales[0].status='void';ledger.saveSales(sales);
    assert.equal(ledger.bookEntries().some(entry=>entry.number==='SI-000001'),false);assert.equal(ledger.bookEntries().some(entry=>entry.number==='CR-000001'),false);
    const report=bookReport().build(ledger,'general-journal','2026-10');assert.equal(report.debit,report.credit);
});

test('account books carry prior posted balances and source filters preserve book scope',()=>{
    const ledger=openLedger(),build=bookReport().build;
    const october=build(ledger,'general-journal','2026-10');assert.equal(october.entries,9);assert.equal(october.debit,october.credit);
    const november=build(ledger,'general-ledger','2026-11');assert.ok(november.rows.length>0);assert.equal(november.rows.every(row=>['opening','subtotal'].includes(row.kind)),true);assert.equal(november.debit,0);
    const cash=build(ledger,'cash-analysis','2026-11');assert.equal(cash.rows.length,0);assert.equal(cash.analysisTotals.amount,0);
    for(const [book,sheet] of [['subsidiary-sales','sales'],['subsidiary-purchase','purchases'],['cash-receipts','collections'],['cash-disbursements','payments']]){const report=build(ledger,book,'2026-10');assert.equal(report.rows.every(row=>row.sheet===sheet),true);assert.equal(report.debit,report.credit);}
    assert.equal(build(ledger,'general-journal','2026-10',{search:'nobody-matches'}).rows.length,0);
});

test('settlement trace groups period invoices with later posted cash and actual CWT clearings',()=>{
    const ledger=openLedger(),build=bookReport().build,sales=ledger.sales();
    const sale=sales[1];ledger.saveCollections([...ledger.collections(),{...ledger.newCollection(),date:'2026-11-02',customer:sale.customer,invoice:sale.number,amount:10000,bank:'1010 · Cash on Hand',type:'Settlement',status:'posted'}]);
    let report=build(ledger,'settlement-trace','2026-10');let rows=report.rows.filter(row=>row.document===sale.number);
    assert.equal(rows[0].amount,sale.gross);assert.equal(rows[1].amount,-10000);assert.equal(rows[1].date,'2026-11-02');assert.equal(rows.at(-1).balance,sale.gross-10000);
    ledger.saveCwtCertificates([{id:'received-qa',number:'2307R-000001',date:'2026-11-01',customer:sale.customer,amount:17857,invoice:sale.number,status:'posted'}]);
    report=build(ledger,'settlement-trace','2026-10');rows=report.rows.filter(row=>row.document===sale.number);assert.equal(rows[1].type,'CWT / 2307');assert.equal(rows[1].amount,-17857);assert.equal(rows.at(-1).balance,sale.gross-27857);
    const filtered=build(ledger,'settlement-trace','2026-10',{search:'2307R-000001'});assert.equal(filtered.rows.length,4);assert.equal(filtered.traceTotals.documents,1);assert.equal(filtered.traceTotals.balance,sale.gross-27857);
    assert.equal(build(ledger,'settlement-trace','2026-11').traceTotals.documents,0);
    const billReport=build(ledger,'settlement-trace','2026-10',{analysisMode:'purchases'});assert.equal(billReport.traceTotals.balance,0);assert.equal(billReport.traceTotals.cleared,30000);assert.equal(billReport.rows.filter(row=>row.type==='Source Withholding').length,1);
    ledger.saveWtpCertificates([issuedCertificate(ledger)]);assert.equal(build(ledger,'settlement-trace','2026-10',{analysisMode:'purchases'}).traceTotals.cleared,30000);
});

test('cash analysis uses invoice accounts and proportional VAT and excludes draft or void vouchers',()=>{
    const ledger=openLedger(),build=bookReport().build;
    const receipts=build(ledger,'cash-analysis','2026-10');const first=receipts.rows.find(row=>row.sourceInvoice==='SI-000001');assert.equal(first.amount,49107);assert.equal(first.net,43846);assert.equal(first.code,'4020');
    const payments=build(ledger,'cash-analysis','2026-10',{analysisMode:'disbursements'});assert.equal(payments.rows.length,2);assert.equal(payments.analysisTotals.amount,29107);assert.equal(payments.rows.find(row=>row.sourceBill==='BILL-000002').code,'7110');
    ledger.saveCollections([...ledger.collections(),{...ledger.newCollection(),date:'2026-10-20',customer:'Advance',amount:12345,type:'Advance',status:'posted'}]);
    const advance=build(ledger,'cash-analysis','2026-10',{search:'Advance'}).rows.find(row=>row.party==='Advance');assert.equal(advance.net,12345);assert.equal(advance.code,'2200');assert.equal(advance.sourceInvoice,'');
    const record=ledger.collections().find(row=>row.number==='CR-000001');ledger.voidCollection(record.id,'Test void','QA');assert.equal(build(ledger,'cash-analysis','2026-10').rows.some(row=>row.number===record.number),false);
});

test('general ledger shows normal credit balances, balances forwarded, and period subtotals without double counting',()=>{
    const ledger=openLedger(),bills=ledger.purchases();bills[1].terms='On Account';ledger.savePurchases(bills);const build=bookReport().build;
    const october=build(ledger,'general-ledger','2026-10',{account:'2010'});const total=october.rows.find(row=>row.kind==='subtotal');assert.equal(total.balance,19107);assert.equal(total.normalSide,'Cr');assert.equal(total.debit,10000);assert.equal(total.credit,29107);assert.equal(october.debit,10000);assert.equal(october.credit,29107);
    const november=build(ledger,'general-ledger','2026-11',{account:'2010'});assert.equal(november.rows.find(row=>row.kind==='opening').balance,19107);assert.equal(november.rows.find(row=>row.kind==='subtotal').balance,19107);assert.equal(november.debit,0);assert.equal(november.credit,0);
    assert.equal(build(ledger,'general-ledger','2026-10',{account:'1500'}).rows.length,0);const zero=build(ledger,'general-ledger','2026-10',{account:'1500',includeZero:true});assert.equal(zero.rows.find(row=>row.kind==='opening').balance,0);
    const full=build(ledger,'general-ledger','2026-10');assert.equal(full.debit,full.credit);const inputVat=full.rows.find(row=>row.code==='2110'&&row.kind==='subtotal');assert.equal(inputVat.balance,3214);assert.equal(inputVat.normalSide,'Dr');
});

test('subsidiary balances match detailed closing balances and expose unassigned control amounts',()=>{
    const ledger=openLedger(),build=bookReport().build;const detailed=build(ledger,'subsidiary-ledger','2026-10'),balances=build(ledger,'subsidiary-ledger','2026-10',{balances:true});
    for(const row of balances.rows){const closing=detailed.rows.find(item=>item.kind==='subtotal'&&item.group===row.group);assert.equal(row.balance,closing.balance);assert.equal(row.debit,closing.debit);assert.equal(row.credit,closing.credit);}
    assert.equal(detailed.ledgerStats.controlVariance,0);
    const journal=journalVoucher(ledger,{lines:[{account:'1105 · Accounts Receivable - Trade',party:'',debit:1000,credit:0,remarks:''},{account:'4020 · Sales - Services',party:'',debit:0,credit:1000,remarks:''}]});ledger.saveJournalEntries([journal]);
    assert.equal(build(ledger,'subsidiary-ledger','2026-10').ledgerStats.controlVariance,1000);
});

test('subsidiary sales uses one invoice row and reconciles VAT classifications, expected withholding, and customer filters',()=>{
    const ledger=openLedger(),sales=ledger.sales();sales[1].gross=10000;sales[1].tax='Zero-Rated (0%)';sales[1].classification='Top Withholding Agent';sales[2].gross=1000;sales[2].tax='VAT Exempt';sales[2].cwt='None (0%)';sales.push({...sales[0],id:4,number:'SI-000004',gross:500,tax:'Non-VAT',cwt:'None (0%)',customer:'Non VAT Customer',customerTin:'123-456-789',customerAddress:'Actual customer address'});ledger.saveSales(sales);
    const build=bookReport().build,report=build(ledger,'subsidiary-sales','2026-10');assert.equal(report.rows.length,4);assert.equal(report.salesTotals.gross,61500);assert.equal(report.salesTotals.vatable,44643);assert.equal(report.salesTotals.vat,5357);assert.equal(report.salesTotals.zero,10000);assert.equal(report.salesTotals.exempt,1000);assert.equal(report.salesTotals.nonVat,500);assert.equal(report.salesTotals.ewt,1093);assert.equal(report.salesTotals.net,60407);
    assert.equal(report.rows[0].tin,'');assert.equal(report.rows[0].address,'');assert.equal(report.rows.find(row=>row.number==='SI-000004').tin,'123-456-789');assert.equal(build(ledger,'subsidiary-sales','2026-10',{customerType:'Top Withholding Agent'}).rows.length,1);
    const remaining=ledger.sales();remaining[0].status='void';remaining[3].status='draft';ledger.saveSales(remaining);assert.equal(build(ledger,'subsidiary-sales','2026-10').rows.length,2);
});

test('master party and tax snapshots flow into source invoices, cash settlements and books',()=>{
 const masters={parties:[{id:55,name:'Master Customer',classification:'Customer',tin:'123-456-789-000',address:'Sample address'}],taxCodes:[],banks:[],atcCodes:[]};const ledger=openLedger(new Map(),'masters-test',null,masters);
 const sales=ledger.sales();sales.push({...sales[0],id:9,number:'SI-000009',customer:'Master Customer',gross:10750,tax:'TAX|CUSTOM|VAT|7.5',cwt:'WC160 · 2%',status:'posted'});ledger.saveSales(sales);
 const source=ledger.cwtSource(9);assert.equal(source.taxBase,10000);assert.equal(source.withheld,200);assert.equal(source.tin,'123-456-789-000');assert.equal(source.customerAddress,'Sample address');const voucher=ledger.bookEntries().find(entry=>entry.number==='SI-000009');assert.equal(voucher.lines.find(line=>line.account.startsWith('2210')).credit,750);assert.equal(voucher.lines.reduce((sum,line)=>sum+line.debit-line.credit,0),0);
 const amounts=ledger.purchaseAmounts({gross:10750,tax:'TAX|CUSTOM|VAT|7.5',ewt:0.5,wvat:0});assert.equal(amounts.net,10000);assert.equal(amounts.ewt,50);
});

test('deleting middle invoices compacts displayed numbers and keeps linked receipt and certificate identities',()=>{
 const storage=new Map(),ledger=openLedger(storage),sales=ledger.sales();
 sales[1].status='draft';ledger.saveSales(sales);
 ledger.saveCwtCertificates([{id:1,number:'2307R-000001',saleId:3,invoice:'SI-000003',status:'draft'}]);
 const receipt=ledger.collections().find(record=>record.saleId===3);
 const remaining=ledger.sales().filter(record=>record.id!==2);ledger.saveSales(remaining);
 assert.equal(remaining.find(record=>record.id===3).number,'SI-000002');
 assert.equal(ledger.collections().find(record=>record.id===receipt.id).invoice,'SI-000002');
 assert.equal(ledger.cwtCertificates()[0].invoice,'SI-000002');
 assert.equal(ledger.cwtCertificates()[0].saleId,3);
 assert.equal(openLedger(storage).sales().find(record=>record.id===3).number,'SI-000002');
});
test('deleting a middle bill updates bill references without changing source identifiers',()=>{
 const ledger=openLedger(),bills=ledger.purchases();
 bills[0].status='draft';ledger.savePurchases(bills);
 ledger.saveWtpCertificates([{id:1,number:'2307I-000001',billId:2,bill:'BILL-000002',status:'draft'}]);
 ledger.savePurchases(ledger.purchases().filter(record=>record.id!==1));
 assert.equal(ledger.purchases()[0].number,'BILL-000001');
 assert.equal(ledger.wtpCertificates()[0].bill,'BILL-000001');
 assert.equal(ledger.wtpCertificates()[0].billId,2);
});
test('all remaining sheet sequences compact after a middle draft deletion',()=>{
 const ledger=openLedger();
 for(const [read,save,prefix] of [['payments','savePayments','PV'],['cwtCertificates','saveCwtCertificates','2307R'],['wtpCertificates','saveWtpCertificates','2307I'],['journalEntries','saveJournalEntries','GJ']]){
 const existing=ledger[read](),base=Math.max(0,...existing.map(record=>Number(record.number.split('-').at(-1))));
 const drafts=[1,2,3].map(n=>({id:'draft-'+n,number:prefix+'-'+String(base+n).padStart(6,'0'),status:'draft',customer:'Draft customer'}));
 ledger[save]([...existing,...drafts]);
 const kept=ledger[read]().filter(record=>record.id!=='draft-2');ledger[save](kept);
 assert.equal(ledger[read]().find(record=>record.id==='draft-3').number,prefix+'-'+String(base+2).padStart(6,'0'));
 assert.equal(kept.find(record=>record.id==='draft-3').number,prefix+'-'+String(base+2).padStart(6,'0'));
 }
});

test('restoring a manual collection cannot create a second draft on the page',()=>{
 const ledger=openLedger();const voided={...ledger.newCollection(),status:'void',origin:'manual'},draft={...ledger.newCollection(),status:'draft',customer:'Keep draft'};
 ledger.saveCollections([...ledger.collections(),voided,draft]);
 assert.throws(()=>ledger.restoreCollection(voided.id),/Only one draft/);
 assert.equal(ledger.collections().find(record=>record.id===voided.id).status,'void');
 assert.equal(ledger.collections().find(record=>record.id===draft.id).customer,'Keep draft');
});

for(const [sheet,read,save,prefix] of [['sales','sales','saveSales','SI'],['collections','collections','saveCollections','CR'],['purchases','purchases','savePurchases','BILL'],['payments','payments','savePayments','PV'],['cwt','cwtCertificates','saveCwtCertificates','2307R'],['wtp','wtpCertificates','saveWtpCertificates','2307I'],['journal','journalEntries','saveJournalEntries','GJ']]){
 test('bulk actions affect only selected eligible '+sheet+' entries and keep recoverable void history',()=>{
  const storage=new Map(),ledger=openLedger(storage);
  if(sheet==='wtp')ledger.saveWtpCertificates([issuedCertificate(ledger)]);
  if(sheet==='journal')ledger.saveJournalEntries([journalVoucher(ledger,{status:'posted'})]);
  if(sheet==='cwt'){const sale=ledger.cwtSource(1);ledger.saveCwtCertificates([{id:'received',number:'2307R-000001',date:sale.date,saleId:sale.id,invoice:sale.number,customer:sale.customer,taxBase:sale.taxBase,amount:sale.withheld,atc:sale.cwt,certificate:'TEST-CWT',status:'posted'}]);}
  const target=ledger[read]().find(record=>record.status==='posted'),others=ledger[read]().filter(record=>record.id!==target.id).map(record=>record.id);
  const draft={id:'bulk-draft',number:prefix+'-000099',date:'2026-10-01',status:'draft',customer:'Keep until explicitly deleted'};
  ledger[save]([...ledger[read](),draft]);
  assert.equal(ledger.bulkTransactions(sheet,[target.id,draft.id],'void',' Duplicate entry ','Bulk QA').count,1);
  const voided=ledger[read]().find(record=>record.id===target.id);assert.equal(voided.status,'void');assert.equal(voided.voidAudit.reason,'Duplicate entry');assert.equal(voided.voidAudit.user,'Bulk QA');assert.equal(ledger[read]().find(record=>record.id===draft.id).status,'draft');
  assert.equal(ledger.bulkTransactions(sheet,[target.id,draft.id],'delete-voids').count,1);
  assert.equal(ledger[read]().find(record=>record.id===target.id).status,'archived');
  assert.equal(openLedger(storage)[read]().find(record=>record.id===target.id).status,'archived');
  ledger.bulkTransactions(sheet,[target.id],'undo-delete-voids');assert.equal(ledger[read]().find(record=>record.id===target.id).status,'void');
  assert.equal(ledger.bulkTransactions(sheet,[draft.id,target.id],'delete-drafts').count,1);
  assert.equal(ledger[read]().some(record=>record.id===draft.id),false);assert.equal(ledger[read]().find(record=>record.id===target.id).status,'void');
  for(const id of others)assert.ok(ledger[read]().some(record=>record.id===id));
 });
}
test('bulk void requires a reason and storage failures roll back every selected transaction',()=>{
 const storage=new Map(),ledger=openLedger(storage),before=JSON.stringify([ledger.sales(),ledger.collections(),ledger.payments()]);
 assert.throws(()=>ledger.bulkTransactions('sales',[1,2],'void',' '),/reason/);
 assert.throws(()=>ledger.bulkTransactions('sales',[],'delete-drafts'),/No selected/);
 storage.set=()=>{throw Error('Storage full');};assert.throws(()=>ledger.bulkTransactions('sales',[1,2],'void','Duplicate'),/storage/);
 assert.equal(JSON.stringify([ledger.sales(),ledger.collections(),ledger.payments()]),before);
});
test('bulk voiding source invoices reverses automatic cash settlement without recreating it on reload',()=>{
 const storage=new Map(),ledger=openLedger(storage);ledger.bulkTransactions('sales',[1],'void','Duplicate sale');
 assert.equal(ledger.collections().find(record=>record.saleId===1).status,'void');
 ledger.bulkTransactions('purchases',[1],'void','Duplicate bill');assert.equal(ledger.payments().find(record=>record.billId===1).status,'void');
 const reopened=openLedger(storage);assert.equal(reopened.collections().find(record=>record.saleId===1).status,'void');assert.equal(reopened.payments().find(record=>record.billId===1).status,'void');
});

test('journal posting accepts optional particulars and references while retaining balanced-line checks',()=>{
 const ledger=openLedger(),voucher=journalVoucher(ledger,{particulars:'',status:'posted'});assert.equal(ledger.journalError(voucher),null);ledger.saveJournalEntries([voucher]);assert.equal(ledger.journalEntries()[0].particulars,'');assert.equal(ledger.journalError({...voucher,reference:''}),null);assert.match(ledger.journalError({...voucher,lines:voucher.lines.map((line,index)=>index===0?{...line,debit:line.debit+1}:line)}),/debits|credits/i);
});

test('server depreciation vouchers enter Books once and remain separate from editable sheet journals',()=>{
    const storage=new Map(),journal={id:'DEP-2026-10',number:'DEP-2026-10',date:'2026-10-31',reference:'DEP-2026-10',status:'posted',lines:[{account:'7199 · Depreciation expense',debit:198,credit:0,party:'',remarks:'PPE-0001'},{account:'1590 · Accumulated Depreciation',debit:0,credit:198,party:'',remarks:'PPE-0001'}]};
    const ledger=openLedger(storage,'company-a',null,null,[journal]);
    const entries=ledger.bookEntries().filter(entry=>entry.sheet==='lapsing');
    assert.equal(entries.length,1);assert.equal(entries[0].number,'DEP-2026-10');
    assert.equal(entries[0].lines.reduce((sum,line)=>sum+line.debit-line.credit,0),0);
    assert.equal(ledger.journalEntries().some(entry=>entry.number==='DEP-2026-10'),false);
    ledger.saveJournalEntries([]);
    assert.equal(openLedger(storage,'company-a',null,null,[journal]).bookEntries().filter(entry=>entry.sheet==='lapsing').length,1);
});

test('CWT source selection resolves text invoice IDs and legacy numeric option values without changing the source',()=>{
    const ledger=openLedger(),legacy=ledger.sales().find(sale=>sale.status==='posted');
    assert.ok(legacy);assert.equal(ledger.cwtSource(String(legacy.id)).customer,legacy.customer);
    const id='b490fdad-4da2-4669-a40d-9660ec012fd3',sale={...legacy,id,number:'SI-UUID',customer:'Selected invoice customer',customerTin:'123-456-789-01234',tin:'',gross:50000,cwt:'WC160 · 2%',terms:'On Account'};
    const before=ledger.sales();ledger.saveSales([...before,sale]);const source=ledger.cwtSource(id);
    assert.equal(source.customer,'Selected invoice customer');assert.equal(source.tin,'123-456-789-01234');assert.equal(source.number,'SI-UUID');assert.equal(source.id,id);assert.equal(source.taxBase,44643);assert.equal(source.withheld,893);
    const certificate={id:'cwt-uuid',number:'2307R-000001',status:'draft',date:source.date,saleId:source.id,invoice:source.number,customer:source.customer,tin:source.tin,taxBase:source.taxBase,atc:source.cwt,amount:source.withheld};ledger.saveCwtCertificates([certificate]);
    assert.equal(ledger.cwtCertificates()[0].customer,'Selected invoice customer');assert.equal(ledger.cwtSource(ledger.cwtCertificates()[0].saleId).customer,source.customer);assert.equal(ledger.sales().find(item=>item.id===id).tin,'');
    for(const missing of ['',null,undefined,'missing-id'])assert.equal(ledger.cwtSource(missing),null);
    ledger.saveSales(ledger.sales().map(item=>item.id===id?{...item,status:'void'}:item));assert.equal(ledger.cwtSource(id),null);
});


test('blank CWT certificate numbers are assigned on posting and remain stable after reload',()=>{
    const storage=new Map(),ledger=openLedger(storage),sale=ledger.cwtSource(1);
    const record={id:'optional-cwt',number:'2307R-000001',date:sale.date,saleId:sale.id,invoice:sale.number,customer:sale.customer,tin:'123-456-789-00000',taxBase:sale.taxBase,atc:sale.cwt,amount:sale.withheld,certificate:'',status:'draft'};
    ledger.saveCwtCertificates([record]);assert.equal(record.certificate,'');
    record.status='posted';ledger.saveCwtCertificates([record]);assert.equal(record.certificate,'2307R-000001');
    assert.equal(openLedger(storage).cwtCertificates()[0].certificate,'2307R-000001');
    ledger.saveCwtCertificates(ledger.cwtCertificates());assert.equal(ledger.cwtCertificates()[0].certificate,'2307R-000001');
});

test('blank WTP certificate numbers avoid existing manual control numbers without changing them',()=>{
    const storage=new Map(),ledger=openLedger(storage),first=issuedCertificate(ledger,{billId:null,bill:'',certificate:'2307I-000002'});
    ledger.saveWtpCertificates([first]);
    const second=issuedCertificate(ledger,{...ledger.nextWtpCertificate(),billId:null,bill:'',certificate:''});
    assert.equal(ledger.wtpError(second), '');
    const records=[first,second];ledger.saveWtpCertificates(records);
    assert.equal(records[0].certificate,'2307I-000002');assert.equal(records[1].certificate,'2307I-000002-2');
    const saved=openLedger(storage).wtpCertificates();assert.equal(saved[1].certificate,'2307I-000002-2');
    assert.equal(ledger.wtpError({...second,id:'duplicate'}), 'This certificate number or source bill already has a posted WTP certificate.');
});

test('WTP resolves explicit certificate, source bill and matching Masters account ATCs without guessing rates',()=>{
 const masters={atcCodes:[{code:'WC100',category:'Income-tax CWT',rate:5}],taxCodes:[],parties:[]},catalog=[{code:'7110',name:'Rent',type:'Expense',ewt_atc_code:'WC100',archived:false}],ledger=openLedger(new Map(),'atc-resolution',catalog,masters);
 assert.equal(ledger.wtpAtc({account:'7110 · Rent',ewt:5}),'WC100');
 assert.equal(ledger.wtpAtc({account:'7110 · Rent',ewt:2}),'');
 assert.equal(ledger.wtpAtc({ewt:5}),'');
 assert.equal(ledger.wtpAtc({account:'7110 · Rent',ewt:5,ewtCode:'WC160'}),'WC160');
 assert.equal(ledger.wtpAtc({ewt:5,atc:'WI100 · 5%'}),'WI100');
 const bills=ledger.purchases();bills[1].ewtCode='WC100';ledger.savePurchases(bills);
 assert.equal(ledger.wtpSource(2).ewtCode,'WC100');assert.equal(ledger.wtpAtc({billId:2,ewt:5}),'WC100');
});

test('WTP automatically reads legacy source field names and bill numbers but never matches by rate alone',()=>{
 const ledger=openLedger(),bills=ledger.purchases();bills[1].ewt_atc_code='WC100';ledger.savePurchases(bills);
 assert.equal(ledger.wtpAtc({bill:'BILL-000002',ewt:5}),'WC100');
 assert.equal(ledger.wtpAtc({billId:2,ewt:5}),'WC100');
 assert.equal(ledger.wtpAtc({billId:'wrong-id',bill:'BILL-000002',ewt:5}),'');
 assert.equal(ledger.wtpAtc({atc_code:'wi010 · 5%',ewt:5}),'WI010');
 assert.equal(ledger.wtpAtc({ewt_atc_code:'WC160',billId:2,ewt:2}),'WC160');
 assert.equal(ledger.wtpAtc({ewt:5}),'');
});
test('purchase account selection copies its Masters ATC and rate and preserves manual source choices',()=>{
 const masters={atcCodes:[{code:'WC100',category:'Income-tax CWT',rate:5},{code:'WC160',category:'Income-tax CWT',rate:2}],taxCodes:[],parties:[]},catalog=[{code:'7110',name:'Rent',type:'Expense',ewt_atc_code:'WC100',archived:false}],storage=new Map(),ledger=openLedger(storage,'account-atc',catalog,masters);
 const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/js/sheet-masters.js'),'utf8');
 const context={window:{WorkspaceMasters:masters,WorkspaceAccounts:catalog},document:{addEventListener:()=>{},querySelectorAll:()=>[]},MutationObserver:class{observe(){}}};vm.createContext(context);vm.runInContext(source,context);
 const record={account:'7110 · Rent',ewt:0};context.window.SheetMasters.applyAccountWithholding(record,'6150 · Supplies');assert.equal(record.ewtCode,'WC100');assert.equal(record.ewt,5);
 const chosen={account:'7110 · Rent',ewtCode:'WC160',ewt:2};context.window.SheetMasters.applyAccountWithholding(chosen);assert.equal(chosen.ewtCode,'WC160');assert.equal(chosen.ewt,2);
});

test('QAP DAT and Form 2307 automatically share the linked account ATC without editing the certificate',()=>{
 const masters={atcCodes:[{code:'WC100',category:'Income-tax CWT',rate:5}],taxCodes:[],parties:[{id:1,name:'VARIOUS SUPPLIERS',classification:'Supplier'}]},catalog=[{code:'7110',name:'Rent Expense',type:'Expense',ewt_atc_code:'WC100',archived:false},{code:'2010',name:'Accounts Payable - Trade',type:'Liability',archived:false}],ledger=openLedger(new Map(),'qap-source-atc',catalog,masters),certificate=issuedCertificate(ledger,{partyId:1});
 ledger.saveWtpCertificates([certificate]);const before=JSON.stringify(ledger.wtpCertificates()),data=require('../../public/js/tax-report-data.js'),range={from:'2026-10-01',to:'2026-12-31'};
 assert.equal(data.build(ledger,'qap-ewt',range,false,{masters}).rows[0].cells[3],'WC100');
 assert.equal(data.qapDatPayload(ledger,{},range,{masters}).records[0].atc,'WC100');
 assert.equal(require('../../public/js/form2307.js').recordsFor(ledger,masters,1,2026,4)[0].atc_code,'WC100');
 assert.equal(JSON.stringify(ledger.wtpCertificates()),before);
});

test('a legacy withholding rate does not display the first same-rate ATC as if it were saved',()=>{
 class Option{constructor(text,value){this.textContent=text;this.value=value;this.dataset={};this.selected=false;}remove(){}}
 const masters={atcCodes:[],taxCodes:[],parties:[]},context={window:{WorkspaceMasters:masters},document:{addEventListener:()=>{},querySelectorAll:()=>[]},MutationObserver:class{observe(){}},Option};vm.createContext(context);vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../../public/js/sheet-masters.js'),'utf8'),context);
 const first=new Option('WC100 · 5%','5');first.dataset.masterCode='WC100';const select={options:[first],querySelectorAll:()=>[],add(option){this.options.push(option);}};
 context.window.SheetMasters.selectValue(select,5);assert.equal(select.options.at(-1).textContent,'5% · ATC not recorded');assert.equal(first.selected,false);
 context.window.SheetMasters.selectValue(select,5,'WC100');assert.equal(first.selected,true);
});
