const test=require('node:test'),assert=require('node:assert/strict');
const {recordsFor,supplierFor,createPreviewCache}=require('../../public/js/form2307.js');
const masters={parties:[{id:1,name:'Current Supplier',classification:'Supplier'},{id:2,name:'Other Supplier',classification:'Both'}]};
const entry={id:'wtp-1',date:'2026-10-15',status:'posted',partyId:1,supplier:'Old Supplier Name',taxBase:10001,ewt:2,ewtCode:'WC157',billId:'purchase-1'};
const ledger=records=>({wtpCertificates:()=>records,wtpAmounts:record=>({base:record.taxBase,ewt:Math.round(record.taxBase*record.ewt/100)}),purchases:()=>[{id:'purchase-1',ewtCode:'WC160'}]});
test('Form 2307 collects all quarter months from posted WTP for the scoped supplier',()=>{
 const records=[entry,{...entry,id:'nov',date:'2026-11-30'},{...entry,id:'dec',date:'2026-12-31'},{...entry,id:'draft',status:'draft'},{...entry,id:'void',status:'void'},{...entry,id:'outside',date:'2027-01-01'},{...entry,id:'other',partyId:2},{...entry,id:'vat-only',ewt:0}];
 const before=JSON.stringify(records),result=recordsFor(ledger(records),masters,1,2026,4);
 assert.deepEqual(result.map(row=>row.source_id),['wtp-1','nov','dec']);assert.equal(result[0].income_payment,'100.01');assert.equal(result[0].tax_withheld,'2.00');assert.equal(result[0].atc_code,'WC157');assert.equal(result[0].supplier_id,1);assert.equal(JSON.stringify(records),before);
});
test('Form 2307 uses Party IDs after a rename and resolves legacy names only from Supplier Masters',()=>{
 assert.equal(supplierFor(entry,masters).id,1);assert.equal(supplierFor({supplier:' current supplier '},masters).id,1);assert.equal(supplierFor({supplier:'Unknown'},masters),undefined);
 assert.equal(supplierFor({supplier:'Customer only'},{parties:[{id:3,name:'Customer only',classification:'Customer'}]}),undefined);
});
test('Form 2307 retains saved ATCs, can use the linked bill ATC, and never invents one',()=>{
 assert.equal(recordsFor(ledger([{...entry,ewtCode:''}]),masters,1,2026,4)[0].atc_code,'WC160');
 assert.throws(()=>recordsFor(ledger([{...entry,ewtCode:'',billId:null}]),masters,1,2026,4),/select its income withholding ATC/);
 assert.deepEqual(recordsFor(ledger([entry]),masters,1,2026,1),[]);
});


test('PDF previews reuse the displayed blob for unchanged downloads and release replaced URLs',async()=>{
 const created=[],revoked=[];let fetches=0;
 const cache=createPreviewCache(blob=>{const url='blob:'+created.length;created.push({url,blob});return url;},url=>revoked.push(url));
 const generate=async()=>{fetches++;return {blob:'PDF '+fetches,filename:'certificate.pdf'};};
 const first=await cache.load('quarter-1',generate),same=await cache.load('quarter-1',generate);
 assert.equal(first,same);assert.equal(fetches,1);assert.equal(cache.current.blob,'PDF 1');
 const next=await cache.load('quarter-2',generate);assert.equal(fetches,2);assert.equal(next.blob,'PDF 2');assert.deepEqual(revoked,[first.url]);
 cache.clear();assert.equal(cache.current,null);assert.deepEqual(revoked,[first.url,next.url]);
});

test('a stale preview response cannot replace a newer signatory or quarter',async()=>{
 const created=[],revoked=[],cache=createPreviewCache(blob=>{created.push(blob);return 'blob:'+blob;},url=>revoked.push(url));let finish;
 const stale=cache.load('old',()=>new Promise(resolve=>{finish=resolve;}));
 cache.clear();await cache.load('new',async()=>({blob:'new PDF',filename:'new.pdf'}));
 finish({blob:'old PDF',filename:'old.pdf'});assert.equal(await stale,null);assert.equal(cache.current.key,'new');assert.deepEqual(created,['new PDF']);assert.deepEqual(revoked,[]);
});

test('closing the popup while generating a PDF releases state without exposing the pending preview',async()=>{
 const created=[],cache=createPreviewCache(blob=>{created.push(blob);return 'blob:pdf';},()=>{});let finish;
 const pending=cache.load('pending',()=>new Promise(resolve=>{finish=resolve;}));cache.clear();finish({blob:'PDF'});
 assert.equal(await pending,null);assert.equal(cache.current,null);assert.deepEqual(created,[]);
});

test('Form 2307 uses the shared WTP ATC resolver when a legacy certificate has no code',()=>{
 const record={...entry,ewtCode:undefined},input={...ledger([record]),purchases:()=>[],wtpAtc:()=> 'WC100'};
 assert.equal(recordsFor(input,masters,1,2026,4)[0].atc_code,'WC100');
});
