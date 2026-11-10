const test=require('node:test');
const assert=require('node:assert/strict');
const worksheet=require('../../public/js/corporate-income-worksheet.js');
const definitions=require('../../resources/templates/bir-corporate-inputs.json');
function input(form){const v=Object.fromEntries(Object.entries(definitions[form]).map(([key,d])=>[key,d.type==='text'?'':'0']));return {...v,sales:'1000000',cost:'400000',rate:'25',mcit:'not_applicable',small_eligible:false,method:'itemized',...(form==='1702Q'?{deductions:'100000'}:{expense_13:'100000',book_net:'500000'}),credit_cwt_current:'20000'};}
test('corporate UI and server examples agree on regular tax and reviewed credits',()=>{
 for(const form of ['1702Q','1702-RT']){const result=worksheet.calculate(form,input(form),1,'itemized',definitions[form]);assert.equal(result.tax,12500000);assert.equal(result.credits,2000000);assert.equal(result.taxable,500000);}
});
test('OSD, small-corporation eligibility and MCIT losses produce explicit results',()=>{
 for(const form of ['1702Q','1702-RT']){
  const v=input(form);v[form==='1702Q'?'deductions':'expense_13']='0';if(form==='1702-RT')v.book_net='360000';
  assert.equal(worksheet.calculate(form,v,1,'osd',definitions[form]).tax,9000000);
  const small=input(form);small.rate='20';assert.throws(()=>worksheet.calculate(form,small,1,'itemized',definitions[form]),/eligibility/);small.small_eligible=true;assert.equal(worksheet.calculate(form,small,1,'itemized',definitions[form]).tax,10000000);
  const loss=input(form);loss.sales='1000';loss.cost='0';loss[form==='1702Q'?'deductions':'expense_13']='2000';loss.mcit='applicable';if(form==='1702-RT')loss.book_net='-1000';assert.equal(worksheet.calculate(form,loss,1,'itemized',definitions[form]).tax,2000);
 }
});
test('reconciliation and fractional amounts do not create a plausible UI tax total',()=>{
 const v=input('1702-RT');v.book_net='1';assert.throws(()=>worksheet.calculate('1702-RT',v,1,'itemized',definitions['1702-RT']),/reconciliation/);
 const q=input('1702Q');q.sales='1.25';assert.throws(()=>worksheet.calculate('1702Q',q,1,'itemized',definitions['1702Q']),/whole pesos/);
});
