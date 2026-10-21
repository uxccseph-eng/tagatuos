const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
function catalog(){
 const context={window:{WorkspaceMasters:{atcCodes:[{code:'WC010',name:'Professional fees',rate:10,category:'Income-tax CWT'},{code:'WI010',name:'Professional fees',rate:5,category:'Income-tax CWT'},{code:'WC999',name:'Company custom ATC',rate:2.5,category:'Income-tax CWT'},{code:'WV010',name:'VAT withholding',rate:5,category:'VAT Withholding'},{code:'WC900',name:'Final tax',rate:20,category:'Final Tax'},{code:'WC998',rate:1,category:'Income-tax CWT',archived:true}]}},document:{addEventListener(){},querySelectorAll(){return [];}},MutationObserver:class{observe(){}}};vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/sheet-masters.js'),'utf8'),context);return {masters:context.window.SheetMasters,context};
}
test('ATC choices come from the workspace Masters catalog including custom codes and exclude archived entries',()=>{
 const {masters}=catalog();assert.deepEqual(Array.from(masters.atcCodes('Income-tax CWT'),item=>item.code),['WC010','WI010','WC999']);assert.equal(masters.atcChoice('WC999 · 2.5%').rate,2.5);assert.equal(masters.atcChoice('WC900 · 20%'),undefined);assert.equal(masters.atcChoice('WV010 · 5%',true).code,'WV010');
});
test('Sales posting validates both withholding categories and the exact Masters rate',()=>{
 const {masters}=catalog();assert.equal(masters.withholdingError({cwt:'WC010 · 10%',vatCwt:'WV010 · 5%'},true),'');assert.match(masters.withholdingError({cwt:'WC010 · 5%'},true),/10%/);assert.match(masters.withholdingError({cwt:'WC998 · 1%'},true),/Masters/);assert.match(masters.withholdingError({cwt:'WV010 · 5%'},true),/income/);assert.equal(masters.withholdingError({cwt:'None (0%)',vatCwt:'None'},true),'');
});
test('Purchases and WTP require a saved Masters code rather than matching any code with the same rate',()=>{
 const {masters}=catalog();assert.match(masters.withholdingError({ewt:5}),/percentage alone/);assert.equal(masters.withholdingError({ewt:5,ewtCode:'WI010',wvat:5,wvatCode:'WV010'}),'');assert.match(masters.withholdingError({ewt:5,ewtCode:'WC010'}),/10%/);assert.match(masters.withholdingError({ewt:0,wvat:5,wvatCode:'WI010'}),/VAT/);
});
test('toolbar and popup posting share the live Masters catalog and accept company-specific rates',()=>{
 const {context,masters}=catalog();vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/sheet-posting.js'),'utf8'),context);const sale={date:'2026-10-21',customer:'Customer',gross:10000,account:'4020 · Services',tax:'VAT',terms:'Cash',bank:'1010 · Cash',cwt:'WC999 · 2.5%',vatCwt:'None'};assert.equal(context.window.SheetPosting.salesError(sale),'');assert.match(context.window.SheetPosting.salesError({...sale,cwt:'WC999 · 5%'}),/2.5%/);assert.equal(masters.withholdingError({ewt:2.5,ewtCode:'WC999'}),'');
});
