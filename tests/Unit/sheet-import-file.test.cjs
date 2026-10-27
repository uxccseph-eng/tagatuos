const test=require('node:test'),assert=require('node:assert/strict');
const engine=require('../../public/js/sheet-import.js');
test('CSV import still reads locally without an upload',async()=>{
    const file=new File(['Date,Amount\n2026-10-01,1000'],'entries.csv');
    assert.equal(await engine.fileText(file,{},()=>{throw Error('must not upload');}),'Date,Amount\n2026-10-01,1000');
});
test('legacy sheets convert Excel rows to CSV without losing identifiers or quoted text',async()=>{
    const rows=[['Date','Supplier TIN','Notes'],['2026-10-01','001-234-567-00000','Line 1, "quoted"\nLine 2']],calls=[];
    const file=new File(['xlsx'],'entries.xlsx');
    const text=await engine.fileText(file,{companyId:14,csrf:'csrf',workbook:'/sheets/import/workbook'},async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>({rows})};});
    assert.deepEqual(engine.parseCsv(text),rows);
    assert.equal(calls.length,1);assert.equal(calls[0].options.method,'POST');
    assert.equal(calls[0].options.headers['X-Company-ID'],'14');assert.equal(calls[0].options.headers['X-CSRF-TOKEN'],'csrf');
    assert.equal(calls[0].options.body.get('worksheet'),'0');
});
test('invalid file sizes formats and company context are rejected before upload',async()=>{
    for(const file of [{name:'old.xls',size:1},{name:'big.xlsx',size:2*1024*1024+1},{name:'big.csv',size:5*1024*1024+1}])await assert.rejects(()=>engine.fileText(file,{},()=>{throw Error('must not upload');}),/Choose/);
    await assert.rejects(()=>engine.fileText(new File(['xlsx'],'entries.xlsx'),{},()=>{}),/assigned company/);
});
test('Excel errors retain server validation and never retry or post entries',async()=>{
    let calls=0;const file=new File(['xlsx'],'entries.xlsx'),config={companyId:14,workbook:'/workbook',csrf:'csrf'};
    await assert.rejects(()=>engine.fileText(file,config,async()=>{calls++;return {ok:false,status:422,json:async()=>({errors:{file:['Remove workbook formulas.']}})};}),/Remove workbook formulas/);
    assert.equal(calls,1);
    await assert.rejects(()=>engine.fileText(file,config,async()=>({ok:false,status:403,json:async()=>{throw Error('html');}})),/HTTP 403/);
    await assert.rejects(()=>engine.fileText(file,config,async()=>({ok:true,json:async()=>({rows:[null]})})),/spreadsheet rows/);
});
