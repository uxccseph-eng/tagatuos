const test=require('node:test'),assert=require('node:assert/strict'),{build,purchasesExcelRows}=require('../../public/js/tax-report-data.js');
const range={from:'2026-10-01',to:'2026-12-31'};
const sales=[{id:1,number:'SI1',date:'2026-10-01',status:'posted',customer:'Customer',customerTin:'12345678900000',gross:11200,tax:'VAT'},{id:2,number:'SI2',date:'2026-10-02',status:'posted',customer:'Customer',customerTin:'12345678900000',gross:5000,tax:'EXEMPT'},{id:3,number:'SI3',date:'2026-10-03',status:'posted',customer:'Customer',customerTin:'12345678900000',gross:2000,tax:'NONVAT'},{id:4,number:'DRAFT',date:'2026-10-04',status:'draft',customer:'Customer',gross:99999,tax:'VAT'}];
const ledger={sales:()=>sales,purchases:()=>[{id:1,number:'BILL1',date:'2026-10-02',status:'posted',supplier:'Supplier',tin:'23456789000000',gross:11200,tax:'VAT',account:'7110 · Rent',ewt:2,ewtCode:'WC160'}],accountProfile:code=>code==='7110'?{relief_category:'services'}:undefined,taxAmounts:(gross,tax)=>({net:tax==='VAT'?Math.round(gross*100/112):gross,tax:{code:tax,kind:tax==='VAT'?'VAT':tax==='ZERO'?'Zero Rated':'Exempt',rate:tax==='VAT'?12:0}}),purchaseAmounts:()=>({net:10000,ewt:200}),cwtCertificates:()=>[{date:'2026-10-10',status:'posted',customer:'Customer',tin:'12345678900000',saleId:1,invoice:'SI1',amount:100,taxBase:10000,atc:'WC160',number:'2307R1'},{date:'2027-01-01',status:'posted',saleId:1,invoice:'SI1',amount:100}],cwtSource:id=>id===1?{withheld:200}:null,wtpCertificates:()=>[{status:'posted',date:'2026-10-02',billId:1,number:'LINKED'},{status:'posted',date:'2026-10-03',billId:null,number:'STANDALONE',supplier:'Supplier',tin:'23456789000000',ewt:2,ewtCode:'WC160'}],wtpAmounts:()=>({base:5000,ewt:100})};
test('RELIEF groups monthly party rows and separates non-VAT from exempt without including drafts',()=>{const report=build(ledger,'relief-sales',range);assert.equal(report.rows.length,1);assert.equal(report.stats.count,3);assert.equal(report.stats.base,10000);assert.equal(report.stats.tax,1200);assert.deepEqual(report.rows[0].cells.slice(3,7),[5000,0,10000,2000]);assert.equal(report.rows[0].cells[9],17000);assert.equal(report.rows[0].cells[10],18200);assert.equal(report.issues.length,0);assert.equal(build(ledger,'relief-sales',range,false).rows.length,3);});
test('purchases use posted purchase details and reconcile input VAT to gross',()=>{const report=build(ledger,'relief-purchases',range);assert.equal(report.stats.tax,1200);assert.equal(report.rows[0].cells[1],'Supplier');assert.equal(report.totals.at(-1),10000);assert.equal(report.totals.at(-2),1200);assert.equal(report.totals.at(-1)+report.totals.at(-2),11200);});
test('QAP schedule 1 reads posted WTP certificates only and never expected bill withholding',()=>{
 const input={...ledger,wtpCertificates:()=>[{id:'one',number:'LINKED',status:'posted',date:'2026-10-02',billId:1,supplier:'Supplier',tin:'23456789000000',ewt:2,ewtCode:'WC160'},{id:'two',number:'STANDALONE',status:'posted',date:'2026-11-02',supplier:'Supplier',tin:'23456789000000',ewt:2,ewtCode:'WC160'},{id:'draft',status:'draft',date:'2026-10-02',ewt:2}],wtpAmounts:()=>({base:5000,ewt:100})};
 const report=build(input,'qap-ewt',range);assert.equal(report.rows.length,1);assert.equal(report.stats.count,2);assert.equal(report.stats.tax,200);assert.equal(report.stats.base,10000);assert.equal(build(input,'qap-ewt',range,false).rows.length,2);assert.equal(build({...input,wtpCertificates:()=>[]},'qap-ewt',range).stats.tax,0);
});
test('SAWT includes only posted certificates in range and 2307 monitor excludes future receipts',()=>{assert.equal(build(ledger,'sawt',range).stats.tax,100);const monitor=build(ledger,'2307-monitor',range);assert.equal(monitor.rows[0].cells[4],200);assert.equal(monitor.rows[0].cells[5],100);assert.equal(monitor.rows[0].cells[6],100);assert.equal(monitor.rows[0].cells[7],'Incomplete');});
test('missing transaction TIN is flagged and missing source modules do not invent rows',()=>{const invalid={...ledger,sales:()=>[{...sales[0],customerTin:'000-000-000-00000'}]};assert.equal(build(invalid,'relief-sales',range).issues.length,1);for(const key of ['qap-fwt','alphalist-mwe','alphalist-non-mwe','sc-pwd']){const report=build(ledger,key,range);assert.equal(report.available,false);assert.equal(report.rows.length,0);assert.ok(report.note.length>20);}});
const {salesDatPayload}=require('../../public/js/tax-report-data.js');
test('DAT payload uses current company, saved party snapshots, posted date range and currency units',()=>{
    const input={...ledger,sales:()=>[
        {...sales[0],customerAddress:'Saved address'},
        {...sales[1]},
        {...sales[3]},
        {...sales[0],id:10,date:'2027-01-01'},
        {...sales[0],id:11,status:'void'}
    ]};
    const payload=salesDatPayload(input,{company_name:'Current Company',registered_name:'Registered Company',tin:'123-456-789',branch_code:'00001'},range);
    assert.equal(payload.company_tin,'12345678900001');
    assert.equal(payload.company_name,'Registered Company');
    assert.equal(payload.tax_period,'12/2026');
    assert.equal(payload.sales_records.length,2);
    assert.deepEqual(payload.sales_records[0],{date:'2026-10-01',source_key:'1',document_number:'SI1',account_code:'',account_name:'',relief_category:'',customer_tin:'12345678900000',customer_name:'Customer',customer_address:'Saved address',gross_amount:112,exempt_amount:0,zero_rated_amount:0,taxable_amount:100,output_vat_amount:12});
    assert.equal(payload.sales_records[1].exempt_amount,50);
});
test('DAT payload refuses to invent a company TIN or reclassify non-VAT sales',()=>{
    assert.equal(salesDatPayload({...ledger,sales:()=>[sales[0]]},{company_name:'Missing TIN'},range).company_tin,'');
    assert.throws(()=>salesDatPayload(ledger,{company_name:'Company',tin:'123456789'},range),/Non-VAT/);
});
test('DAT payload stops empty exports when there are no posted sales within the date range',()=>{
    const company={company_name:'Company',tin:'123456789'};
    for(const records of [[],[{...sales[0],status:'draft'}],[{...sales[0],status:'void'}],[{...sales[0],date:'2027-01-01'}]]){
        assert.throws(()=>salesDatPayload({...ledger,sales:()=>records},company,range),/No posted sales rows found/);
    }
});
test('DAT tax periods follow calendar quarter ending dates and filter the same selected range',()=>{
    const periods=require('../../public/js/financial-periods.js'),company={company_name:'Company',tin:'123456789'};
    for(const [quarter,expected] of [[1,'03/2027'],[2,'06/2027'],[3,'09/2027'],[4,'12/2027']]){
        const selected=periods.quarter(2027,quarter),records=[{...sales[0],date:selected.from},{...sales[0],id:2,date:selected.to},{...sales[0],id:3,date:'2028-01-01'}];
        const payload=salesDatPayload({...ledger,sales:()=>records},company,selected);
        assert.equal(payload.tax_period,expected);
        assert.equal(payload.sales_records.length,2);
    }
});
test('DAT uses the actual ending calendar year for fiscal quarters, monthly and custom ranges',()=>{
    const periods=require('../../public/js/financial-periods.js'),company={company_name:'Company',tin:'123456789',fiscal_end_month:3};
    for(const [selected,expected] of [[periods.quarter(2026,1,3),'06/2025'],[periods.quarter(2026,4,3),'03/2026'],[{from:'2027-02-01',to:'2027-02-28'},'02/2027'],[{from:'2026-12-15',to:'2027-01-20'},'01/2027']]){
        const payload=salesDatPayload({...ledger,sales:()=>[{...sales[0],date:selected.to}]},company,selected);
        assert.equal(payload.tax_period,expected);
    }
});
test('DAT maps PascalCase, camelCase and snake_case amounts without confusing currency with ledger cents',()=>{
    const company={company_name:'Company',tin:'123456789'};
    const variants=[
        {Customer:'Customer',TIN:'123-456-789-00000',Address:'Saved address','Gross amount':'1,120.00',Exempt:0,'Zero-rated':0,Taxable:'1,000.00','Output VAT':'120.00'},
        {customerName:'Customer',customerTin:'12345678900000',customerAddress:'Saved address',grossAmount:1120,exemptSales:0,zeroRatedSales:0,taxableSales:1000,outputVat:120},
        {customer_name:'Customer',customer_tin:'12345678900000',customer_address:'Saved address',gross_amount:1120,exempt_sales:0,zero_rated_sales:0,taxable_sales:1000,output_vat:120}
    ];
    for(const fields of variants){
        const payload=salesDatPayload({...ledger,sales:()=>[{status:'posted',date:'2026-10-01',number:'SI1',...fields}]},company,range);
        assert.deepEqual(payload.sales_records[0],{date:'2026-10-01',source_key:'SI1',document_number:'SI1',account_code:'',account_name:'',relief_category:'',customer_name:'Customer',customer_tin:'12345678900000',customer_address:'Saved address',gross_amount:1120,exempt_amount:0,zero_rated_amount:0,taxable_amount:1000,output_vat_amount:120});
    }
    const record={...sales[0],gross:112000};
    assert.equal(salesDatPayload({...ledger,sales:()=>[record]},company,range).sales_records[0].gross_amount,1120);
});
test('DAT preserves explicit zero values and calculates missing VAT from the supplied sales breakdown',()=>{
    const fields={...sales[0],gross_amount:500,taxable_sales:446.43,exempt_sales:0,zero_rated_sales:0,customer:'',customerName:'VARIOUS CUSTOMERS'};
    const payload=salesDatPayload({...ledger,sales:()=>[fields]},{company_name:'Company',tin:'123456789'},range);
    assert.equal(payload.sales_records[0].output_vat_amount,53.57);
    assert.equal(payload.sales_records[0].customer_name,'VARIOUS CUSTOMERS');
    const zero={...fields,gross_amount:0,taxable_sales:0,output_vat:0};
    assert.equal(salesDatPayload({...ledger,sales:()=>[zero]},{company_name:'Company',tin:'123456789'},range).sales_records[0].gross_amount,0);
});
test('DAT rejects invalid monetary text and inconsistent explicit totals',()=>{
    const company={company_name:'Company',tin:'123456789'};
    for(const fields of [{gross_amount:'1,2,3'},{gross_amount:'500abc'},{gross_amount:500,taxable_sales:446.43,output_vat:54}]){
        assert.throws(()=>salesDatPayload({...ledger,sales:()=>[{...sales[0],...fields}]},company,range),/invalid|do not equal/);
    }
});
const {purchasesDatPayload}=require('../../public/js/tax-report-data.js');
test('Purchases DAT uses Masters categories, preserves cent units and keeps withholding separate from input VAT',()=>{
    const source={...ledger,purchases:()=>[{id:1,date:'2026-10-20',number:'BILL1',status:'posted',supplier:'Supplier',supplierTin:'23456789000000',supplierAddress:'Saved address',gross:11200,tax:'VAT',account:'7110 · Rent Expense',ewt:5,wvat:5}],accountProfile:code=>({code,relief_category:'services'})};
    const payload=purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range);
    const row=payload.purchase_records[0];
    assert.equal(row.relief_category,'services');assert.equal(row.gross_amount,112);assert.equal(row.taxable_amount,100);assert.equal(row.input_vat_amount,12);assert.equal(row.non_creditable_vat_amount,0);assert.equal(row.supplier_address,'Saved address');assert.equal(payload.from,range.from);
});
test('Purchases DAT fills only missing supplier identity from Masters and leaves the original records unchanged',()=>{
    const rows=[{id:1,date:'2026-10-20',number:'BILL1',status:'posted',supplier:'Supplier',supplierTin:'000-000-000-00000',tin:'',gross:11200,tax:'VAT',account:'7110 · Rent Expense'}];
    const before=JSON.stringify(rows),source={...ledger,purchases:()=>rows,accountProfile:()=>({relief_category:null})};
    const masters={parties:[{name:'Supplier',classification:'Customer',tin:'99999999900000'},{name:'Supplier',classification:'Supplier',tin:'23456789000000',address:'Master address'}]};
    const payload=purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range,masters);
    assert.equal(payload.purchase_records[0].supplier_tin,'23456789000000');assert.equal(payload.purchase_records[0].supplier_address,'Master address');assert.equal(payload.purchase_records[0].relief_category,'');assert.equal(JSON.stringify(rows),before);
    rows[0].supplierTin='34567890100000';assert.equal(purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range,masters).purchase_records[0].supplier_tin,'34567890100000');
});
test('Purchases DAT review overrides cannot replace Masters categories and preserve explicit zero values',()=>{
    const record={id:1,date:'2026-10-20',number:'BILL1',status:'posted',supplier:'Supplier',gross:11200,tax:'VAT',relief_category:'services',non_creditable_vat_amount:5};
    const source={...ledger,purchases:()=>[record],accountProfile:()=>({relief_category:'goods'})};
    const payload=purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range,{}, {'1':{supplier_tin:'234567890',relief_category:'capital_goods',non_creditable_vat_amount:'0'}});
    assert.equal(payload.purchase_records[0].supplier_tin,'234567890');assert.equal(payload.purchase_records[0].relief_category,'goods');assert.equal(payload.purchase_records[0].non_creditable_vat_amount,0);assert.equal(record.relief_category,'services');assert.equal(record.non_creditable_vat_amount,5);
});
test('Purchases DAT respects posted date range and distinguishes exempt and zero-rated purchases',()=>{
    const base={id:1,number:'BILL1',status:'posted',supplier:'Supplier',date:'2026-10-20',gross:5000};
    const source={...ledger,purchases:()=>[{...base,tax:'EXEMPT'},{...base,id:2,tax:'ZERO'},{...base,id:3,status:'draft',tax:'VAT'},{...base,id:4,status:'void',tax:'VAT'},{...base,id:5,date:'2027-01-01',tax:'VAT'}]};
    const records=purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range).purchase_records;
    assert.equal(records.length,2);assert.equal(records[0].exempt_amount,50);assert.equal(records[0].input_vat_amount,0);assert.equal(records[1].zero_rated_amount,50);
    assert.throws(()=>purchasesDatPayload({...ledger,purchases:()=>[]},{company_name:'Company'},range),/No posted purchases/);
});
test('Sales DAT categories come exclusively from the linked Chart of Accounts record',()=>{
    const record={...sales[0],account:'4020 · Service income',relief_category:'capital_goods'};
    let category='services';
    const source={...ledger,sales:()=>[record],accountProfile:code=>({code,name:'Service income',relief_category:category})};
    let payload=salesDatPayload(source,{company_name:'Company',tin:'123456789'},range);
    assert.equal(payload.sales_records[0].account_code,'4020');assert.equal(payload.sales_records[0].relief_category,'services');
    category='goods';payload=salesDatPayload(source,{company_name:'Company',tin:'123456789'},range);assert.equal(payload.sales_records[0].relief_category,'goods');
    category=null;assert.equal(salesDatPayload(source,{company_name:'Company',tin:'123456789'},range).sales_records[0].relief_category,'');
});


test('Purchase schedule uses Masters categories and the reference column order without changing saved tax treatments',()=>{
    const base=ledger.purchases()[0],records=['services','capital_goods','goods'].map((category,index)=>({...base,id:index+1,number:'B'+index,account:category,gross:11201}));
    records.push({...base,id:4,number:'E',gross:100,tax:'EXEMPT'},{...base,id:5,number:'Z',gross:200,tax:'ZERO'});
    const source={...ledger,purchases:()=>records,accountProfile:code=>({relief_category:code})},before=JSON.stringify(records),report=build(source,'relief-purchases',range);
    assert.deepEqual(report.columns,['Month','Supplier','TIN','Exempt','Zero-Rated','Services','Capital Goods','Other Goods','Input VAT','Total Purchases']);
    assert.deepEqual(report.rows[0].cells.slice(3),[100,200,10001,10001,10001,3600,30303]);
    assert.equal(report.stats.base,30003);assert.equal(report.stats.count,5);assert.equal(JSON.stringify(records),before);assert.equal(build(source,'relief-purchases',range,false).rows.length,5);
});

test('Unclassified VAT purchases remain visible while Non-VAT purchases are excluded',()=>{
    const base=ledger.purchases()[0],source={...ledger,accountProfile:()=>({relief_category:null}),purchases:()=>[base,{...base,id:2,number:'N',tax:'NONVAT',gross:123}]},report=build(source,'relief-purchases',range);
    assert.ok(report.columns.includes('Unclassified'));assert.equal(report.columns.includes('Non-VAT'),false);assert.equal(report.rows[0].cells[report.columns.indexOf('Unclassified')],10000);assert.match(report.issues[0],/Masters/);assert.equal(report.totals.at(-1),10000);assert.equal(report.stats.count,1);
});

test('RELIEF Purchases excludes No Receipt legacy NA and Non-VAT from schedules Excel and DAT without changing Books sources',()=>{
    const base=ledger.purchases()[0],excluded=['NR','NA','NONVAT','Non-VAT','No Receipt','TAX|NR|Exempt|0','TAX|NA|Exempt|0','TAX|NONVAT|Exempt|0'];
    const records=[base,{...base,id:'exempt',number:'EXEMPT',tax:'EXEMPT',gross:100},{...base,id:'zero',number:'ZERO',tax:'ZERO',gross:200},...excluded.map((tax,index)=>({...base,id:'excluded-'+index,number:'EXCLUDED-'+index,tax,gross:999999,supplier:'Missing documentation',tin:''}))];
    const source={...ledger,purchases:()=>records},before=JSON.stringify(records);
    const report=build(source,'relief-purchases',range),detail=build(source,'relief-purchases',range,false);
    assert.equal(report.stats.count,3);assert.equal(report.totals.at(-1),10300);assert.equal(detail.rows.length,3);
    assert.equal(report.issues.length,0);assert.equal(report.rows.some(row=>row.documents.some(number=>number.startsWith('EXCLUDED'))),false);
    const excel=purchasesExcelRows(source,range);assert.equal(excel.length,1);assert.equal(excel[0][5],103);assert.equal(excel[0].at(-2),12);
    const dat=purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range);
    assert.equal(dat.purchase_records.length,3);assert.equal(dat.purchase_records.some(row=>row.document_number.startsWith('EXCLUDED')),false);
    assert.equal(JSON.stringify(records),before);assert.equal(source.purchases().length,records.length);
    assert.equal(build({...ledger,sales:()=>sales},'relief-sales',range).stats.count,3);
});

test('Importation uses saved customs centavo amounts and is excluded from domestic reports and DAT',()=>{
    const domestic=ledger.purchases()[0],imports={...domestic,id:2,number:'IMPORT1',importation:{entryNo:'E-001',country:'Japan',dutiable:8000,charges:2000,vatPaid:1200}},source={...ledger,purchases:()=>[domestic,imports,{...imports,id:3,status:'draft'},{...imports,id:4,date:'2027-01-01'}]},report=build(source,'relief-importation',range);
    assert.deepEqual(report.columns,['Month','Entry No.','Supplier','Country','Dutiable','Charges','Exempt','Taxable','VAT Paid']);assert.deepEqual(report.rows[0].cells,['2026-10','E-001','Supplier','Japan',8000,2000,0,10000,1200]);assert.equal(report.stats.count,1);assert.equal(report.issues.length,0);assert.equal(report.available,true);
    assert.equal(build(source,'relief-purchases',range).stats.count,1);assert.equal(purchasesDatPayload(source,{company_name:'Company',tin:'123456789'},range).purchase_records.length,1);
});

test('Import VAT profiles produce review issues instead of inventing customs metadata',()=>{
    const record={...ledger.purchases()[0],tax:'IMPORT'},source={...ledger,purchases:()=>[record],taxAmounts:()=>({net:10000,tax:{kind:'VAT',code:'VAT-IMPORT'}})},report=build(source,'relief-importation',range);
    assert.equal(report.rows.length,1);assert.equal(report.rows[0].cells[1],'');assert.equal(report.rows[0].cells[8],0);assert.equal(report.issues.length,3);
    const empty=build({...ledger,purchases:()=>[]},'relief-importation',range);assert.equal(empty.rows.length,0);assert.equal(empty.emptyTitle,'No importations in this window');
});


test('SAWT uses reference columns, five-digit branches and groups received certificates without counting expected CWT',()=>{
    const first={id:1,number:'R1',certificate:'CERT1',date:'2026-10-20',customer:'Customer',tin:'12345678901234',saleId:1,invoice:'SI1',taxBase:44643,amount:893,atc:'WC157',status:'posted'};
    const records=[first,{...first,id:2,number:'R2',certificate:'CERT2'},{...first,id:3,status:'draft'},{...first,id:4,status:'void'},{...first,id:5,date:'2027-01-01'}],source={...ledger,cwtCertificates:()=>records},before=JSON.stringify(records),report=build(source,'sawt',range);
    assert.deepEqual(report.columns,['Date','Payee','TIN','Branch','ATC','Income payment','Rate','Tax withheld','Status']);
    assert.deepEqual(report.rows[0].cells,['2026-10-20','Customer','123456789','01234','WC157',89286,'2%',1786,'Linked']);
    assert.equal(report.stats.count,2);assert.equal(report.totals[5],89286);assert.equal(report.totals[7],1786);assert.equal(JSON.stringify(records),before);
    const detailed=build(source,'sawt',range,false);assert.equal(detailed.rows.length,2);assert.equal(detailed.rows[0].cells[9],'CERT1');assert.equal(detailed.rows[0].cells[10],'SI1');
});

test('SAWT tax toggles separate income and VAT certificates and never generate a certificate from an expected sale',()=>{
    const base={id:1,number:'R1',date:'2026-10-20',customer:'Customer',tin:'12345678900000',saleId:1,invoice:'SI1',taxBase:10000,amount:200,atc:'WC160',status:'posted'};
    const source={...ledger,cwtCertificates:()=>[base,{...base,id:2,number:'VAT1',atc:'WV010',rate:5,amount:500}]};
    assert.equal(build(source,'sawt',range).stats.tax,200);const vat=build(source,'sawt',range,true,{sawtTax:'vat'});assert.equal(vat.stats.count,1);assert.equal(vat.stats.tax,500);assert.equal(vat.rows[0].cells[6],'5%');
    const noVat=build({...source,cwtCertificates:()=>[base]},'sawt',range,true,{sawtTax:'vat'});assert.equal(noVat.rows.length,0);assert.match(noVat.emptyTitle,/VAT withholding/);
    assert.equal(build({...source,cwtCertificates:()=>[]},'sawt',range).stats.count,0);
});

test('SAWT keeps old source invoice links outside the reporting period and flags missing identities and void sources',()=>{
    const record={id:1,number:'R1',date:'2026-10-20',customer:'Customer',tin:'',saleId:1,invoice:'SI1',taxBase:10000,amount:200,atc:'',status:'posted'},source={...ledger,sales:()=>[{...sales[0],date:'2025-01-01'}],cwtCertificates:()=>[record]};
    const report=build(source,'sawt',range);assert.equal(report.rows[0].cells[8],'Linked');assert.equal(report.rows[0].cells[2],'');assert.equal(report.rows[0].cells[3],'');assert.equal(report.issues.length,2);
    const missing=build({...source,sales:()=>[{...sales[0],status:'void'}]},'sawt',range);assert.equal(missing.rows[0].cells[8],'Unlinked');assert.equal(missing.issues.length,3);
});


const {sawtDatPayload}=require('../../public/js/tax-report-data.js');
test('SAWT DAT payload maps saved certificates and source descriptions without altering the ledger',()=>{
    const first={id:'certificate-uuid',number:'2307R-000001',date:'2026-10-20',status:'posted',saleId:1,invoice:'SI1',customer:'Withholding Agent',tin:'234-567-890-01234',taxBase:44643,amount:893,atc:'WC157 · 2%',certificate:'CERT1'};
    const records=[first,{...first,id:2,status:'draft'},{...first,id:3,status:'void'},{...first,id:4,date:'2027-01-01'}],source={...ledger,cwtCertificates:()=>records,sales:()=>[{id:1,number:'SI1',memo:'Consulting services'}]},before=JSON.stringify(records);
    const payload=sawtDatPayload(source,{company_name:'Company',registered_name:'Registered Company',tin:'123456789',branch_code:'00007',rdo:'033'},range,{formType:'1702Q'});
    assert.equal(payload.records.length,1);assert.equal(payload.records[0].source_id,'certificate-uuid');assert.equal(payload.records[0].tin,'234-567-890-01234');assert.equal(payload.records[0].atc,'WC157');assert.equal(payload.records[0].tax_rate,'2.00');assert.equal(payload.records[0].income_payment,'446.43');assert.equal(payload.records[0].amount_withheld,'8.93');assert.equal(payload.records[0].nature_income,'');assert.equal(payload.taxpayer.tin,'12345678900007');assert.equal(payload.taxpayer.registered_name,'Registered Company');assert.equal(payload.form_type,'1702Q');assert.equal(JSON.stringify(records),before);
});

test('SAWT DAT fills missing agent identity from customer Masters and separates VAT received certificates',()=>{
    const base={id:1,number:'R1',date:'2026-10-20',status:'posted',customer:'Agent',tin:'000-000-000-00000',taxBase:10000,amount:200,atc:'WC160'};
    const source={...ledger,cwtCertificates:()=>[base,{...base,id:2,number:'R2',tin:'34567890100000',atc:'WV020',amount:500}]},masters={parties:[{name:'Agent',classification:'Customer',tin:'23456789000000'}],atcCodes:[{code:'WC160',name:'Services income'}]};
    const income=sawtDatPayload(source,{company_name:'Company',tin:'123456789'},range,{masters});assert.equal(income.records.length,1);assert.equal(income.records[0].tin,'23456789000000');assert.equal(income.records[0].nature_income,'');
    const vat=sawtDatPayload(source,{company_name:'Company',tin:'123456789'},range,{taxKind:'vat',formType:'2550Q',masters});assert.equal(vat.records.length,1);assert.equal(vat.records[0].atc,'WV020');assert.equal(vat.records[0].tax_rate,'5.00');assert.equal(vat.tax_kind,'vat');assert.equal(vat.form_type,'2550Q');assert.equal(base.tin,'000-000-000-00000');
    assert.throws(()=>sawtDatPayload({...source,cwtCertificates:()=>[]},{},range),/No posted received/);
    assert.throws(()=>sawtDatPayload({...source,cwtCertificates:()=>[{...base,taxBase:1.5}]},{},range),/correct the saved certificate/);
});


test('SAWT DAT takes individual name fields from the selected Party master',()=>{
    const record={id:1,number:'R1',date:'2026-10-20',status:'posted',customer:'Cruz, Juan Santos',partyId:7,taxBase:10000,amount:200,atc:'WI160'};
    const source={...ledger,cwtCertificates:()=>[record]},masters={parties:[{id:7,name:record.customer,classification:'Customer',entity_type:'individual',last_name:'Cruz',first_name:'Juan',middle_name:'Santos',tin:'23456789000000'}]};
    const result=sawtDatPayload(source,{company_name:'Company',tin:'123456789'},range,{masters}).records[0];
    assert.equal(result.entity_type,'individual');assert.equal(result.last_name,'Cruz');assert.equal(result.first_name,'Juan');assert.equal(result.middle_name,'Santos');assert.equal(result.tin,'23456789000000');
});


test('SAWT DAT does not infer nature of income from ATC descriptions or invoice details',()=>{
    const record={id:1,number:'R1',date:'2026-10-20',status:'posted',customer:'Agent',tin:'23456789000000',taxBase:10000,amount:200,atc:'WC160',saleId:1};
    const source={...ledger,cwtCertificates:()=>[record],sales:()=>[{id:1,memo:'Invoice memo',description:'Invoice description',account:'4000 · Revenue'}]},masters={atcCodes:[{code:'WC160',name:'TOP WITHHOLDING AGENTS - SERVICES'}]};
    assert.equal(sawtDatPayload(source,{},range,{masters}).records[0].nature_income,'');
    record.nature_income='Explicitly entered income';
    assert.equal(sawtDatPayload(source,{},range,{masters}).records[0].nature_income,'Explicitly entered income');
    record.nature_income='';
    assert.equal(sawtDatPayload(source,{},range,{masters}).records[0].nature_income,'');
});


test('purchase Excel template groups monthly records and maps corporate/individual parties to fourteen columns',()=>{
    const {purchasesExcelRows}=require('../../public/js/tax-report-data.js');
    const bills=[
        {id:1,number:'BILL1',date:'2026-10-02',status:'posted',supplier:'Company',gross:11200,tax:'VAT',account:'SERV'},
        {id:2,number:'BILL2',date:'2026-10-03',status:'posted',supplier:'Company',gross:22400,tax:'VAT',account:'GOODS'},
        {id:3,number:'BILL3',date:'2026-10-04',status:'posted',supplier:'Person',gross:5000,tax:'EXEMPT',account:'OTHER'},
        {id:4,number:'DRAFT',date:'2026-10-05',status:'draft',supplier:'Company',gross:99999,tax:'VAT',account:'SERV'},
        {id:5,number:'IMPORT',date:'2026-10-06',status:'posted',supplier:'Company',gross:99999,tax:'VAT',account:'SERV',isImportation:true},
    ];
    const source={...ledger,purchases:()=>bills,accountProfile:code=>({relief_category:{SERV:'services',GOODS:'goods'}[code]})};
    const masters={parties:[{name:'Company',classification:'Supplier',entity_type:'corporation',tin:'001-002-003-00000',address:'Company address'},{name:'Person',classification:'Both',entity_type:'individual',last_name:'Person',first_name:'Example',middle_name:'Middle',tin:'004-005-006-00000',address:'Home address'}]};
    const rows=purchasesExcelRows(source,range,masters);
    assert.equal(rows.length,2);assert.equal(rows[0].length,14);
    assert.deepEqual(rows[0],['2026-10','001-002-003','Company','','Company address',300,0,0,300,100,0,200,36,336]);
    assert.deepEqual(rows[1],['2026-10','004-005-006','','Person, Example Middle','Home address',50,50,0,0,0,0,0,0,0]);
});
test('purchase Excel categories follow Masters and preserve zero-rated and unclassified amounts without guessing VAT categories',()=>{
    const {purchasesExcelRows}=require('../../public/js/tax-report-data.js');
    const source={...ledger,purchases:()=>[{id:1,number:'BILL1',date:'2026-10-02',status:'posted',supplier:'Supplier',gross:11200,tax:'VAT',account:'CAP'},{id:2,number:'BILL2',date:'2026-11-02',status:'posted',supplier:'Supplier',gross:7000,tax:'ZERO',account:'CAP'}],accountProfile:()=>({relief_category:'capital_goods'})};
    const rows=purchasesExcelRows(source,range);
    assert.equal(rows[0][10],100);assert.equal(rows[0][13],112);assert.equal(rows[1][7],70);assert.equal(rows[1][13],0);
    const unclassified=purchasesExcelRows({...source,accountProfile:()=>({})},range);
    assert.equal(unclassified[0][8],100);assert.deepEqual(unclassified[0].slice(9,12),[0,0,0]);assert.equal(unclassified[0][13],112);
    assert.deepEqual(purchasesExcelRows({...source,purchases:()=>[]},range),[]);
});

test('QAP schedule 2 excludes withholding-tagged, draft and void bills',()=>{
 const purchases=[{id:1,number:'NO-WHT',supplier:'Supplier',tin:'23456789000000',status:'posted',date:'2026-10-01',ewt:0},{id:2,number:'TAGGED',status:'posted',date:'2026-10-01',ewtCode:'WC160',ewt:2},{id:3,number:'RATE-ONLY',status:'posted',date:'2026-10-01',ewt:2},{id:4,number:'DRAFT',status:'draft',date:'2026-10-01'},{id:5,number:'VOID',status:'void',date:'2026-10-01'}];
 const report=build({...ledger,purchases:()=>purchases},'qap-ewt',range,false,{qapSchedule:'2'});assert.equal(report.rows.length,1);assert.equal(report.stats.base,10000);assert.equal(report.stats.tax,0);assert.equal(report.rows[0].printRecord,null);assert.equal(report.rows[0].cells[8],'NO-WHT');
});
test('QAP DAT maps quarterly WTP snapshots and Masters individual names without changing data',()=>{
 const {qapDatPayload}=require('../../public/js/tax-report-data.js');const records=[{id:'one',number:'WTP1',status:'posted',date:'2026-12-31',partyId:5,supplier:'Supplier',ewt:2,ewtCode:'WC160'}],input={...ledger,wtpCertificates:()=>records},masters={parties:[{id:5,name:'Supplier',classification:'Supplier',tin:'23456789000000',entity_type:'individual',last_name:'Cruz',first_name:'Juan',middle_name:'Santos'}]},before=JSON.stringify(records);
 const payload=qapDatPayload(input,{},range,{masters});assert.equal(payload.quarter,4);assert.equal(payload.year,2026);assert.equal(payload.records[0].last_name,'Cruz');assert.equal(payload.records[0].income_payment,'50.00');assert.equal(payload.records[0].amount_withheld,'1.00');assert.equal(JSON.stringify(records),before);
 assert.throws(()=>qapDatPayload(input,{}, {from:'2026-01-01',to:'2026-12-31'},{masters}),/complete calendar quarter/);
 assert.throws(()=>qapDatPayload({...input,wtpCertificates:()=>[]},{},range,{masters}),/No posted/);
});

test('QAP displays resolved source ATC and exposes unresolved certificates for explicit correction',()=>{
 const certificate={id:'one',number:'WTP1',status:'posted',date:'2026-10-01',supplier:'Supplier',tin:'23456789000000',ewt:5},input={...ledger,wtpCertificates:()=>[certificate]};
 const unresolved=build(input,'qap-ewt',range,false);assert.equal(unresolved.rows[0].cells[3],'');assert.equal(unresolved.rows[0].atcRecords[0].id,'one');assert.equal(unresolved.rows[0].printRecord,null);
 const resolved=build({...input,wtpAtc:()=> 'WC100'},'qap-ewt',range,false);assert.equal(resolved.rows[0].cells[3],'WC100');assert.equal(resolved.rows[0].printRecord.id,'one');assert.equal(resolved.rows[0].atcRecords.length,0);
});

test('1604E payload reads all four calendar quarters regardless of the visible QAP period',()=>{
 const {qapAnnualDatPayload}=require('../../public/js/tax-report-data.js'),certificates=['2026-01-01','2026-04-01','2026-07-01','2026-12-31','2025-12-31','2027-01-01'].map((date,index)=>({id:'annual-'+index,number:'WTP-'+index,date,status:'posted',supplier:'Supplier',tin:'23456789000000',ewt:2,ewtCode:'WC160'}));certificates.push({...certificates[0],id:'draft',status:'draft'});
 const input={...ledger,wtpCertificates:()=>certificates},before=JSON.stringify(certificates),payload=qapAnnualDatPayload(input,{},2026);
 assert.equal(payload.year,2026);assert.equal(payload.quarter,undefined);assert.deepEqual(payload.records.map(row=>row.date),['2026-01-01','2026-04-01','2026-07-01','2026-12-31']);assert.equal(JSON.stringify(certificates),before);assert.throws(()=>qapAnnualDatPayload(input,{},NaN),/valid reporting year/);
});
