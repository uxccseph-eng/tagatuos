const test = require('node:test'), assert = require('node:assert/strict');
const reconciliation = require('../../public/js/payroll-tax-reconciliation.js');
const employees = [{id:1,name:'Fictional Employee'}];
const employee = changes => ({employeeId:1,name:'Fictional Employee',payType:'Taxable',basic:'30000.00',sssEe:'1500.00',phicEe:'750.00',hdmfEe:'200.00',withholding:'1100.00',...changes});
const run = (month, changes={}) => ({id:'run-'+month,status:'posted',date:`2026-${month}-28`,frequency:'monthly',employees:[employee(changes)]});

test('monthly report separates actual deductions and benchmark without changing payroll',()=>{
    const runs=[run('01')],snapshot=JSON.stringify(runs);
    const row=reconciliation.build(runs,employees,{year:2026,month:'2026-01'}).rows[0];
    assert.equal(row.actual,110000);assert.equal(row.benchmark,100755);assert.equal(row.over,9245);assert.equal(row.short,0);assert.equal(JSON.stringify(runs),snapshot);
});
test('annual preview uses the annual table on recorded taxable pay, not the sum of monthly differences',()=>{
    const runs=Array.from({length:12},(_,i)=>run(String(i+1).padStart(2,'0')));
    const row=reconciliation.build(runs,employees,{year:2026,mode:'annual'}).rows[0];
    assert.equal(row.months,12);assert.equal(row.taxable,33060000);assert.equal(row.benchmark,1209000);assert.equal(row.actual,1320000);assert.equal(row.over,111000);
    assert.ok(row.notes.some(note=>note.includes('prior-employer')));
});
test('annual benefits exemption is capped and unknown tax classifications do not produce false refunds',()=>{
    const runs=[run('01',{benefits:'100000.00',taxableBenefits:'0.00'})];
    const row=reconciliation.build(runs,employees,{year:2026,mode:'annual'}).rows[0];assert.equal(row.taxable,3755000);
    const nonTaxable=reconciliation.build([run('01',{payType:'Non-taxable'})],employees,{year:2026,mode:'annual'}).rows[0];assert.equal(nonTaxable.benchmark,null);assert.equal(nonTaxable.over,null);
    const monthly=reconciliation.build([run('01',{payType:'Non-taxable',withholding:'0.00'})],employees,{year:2026}).rows[0];assert.equal(monthly.short,100755);assert.ok(monthly.notes.some(note=>note.includes('exemption')));
});
test('drafts voids duplicates other years and unmatched employee identities are handled explicitly',()=>{
    const posted=run('01');const result=reconciliation.build([posted,posted,{...run('02'),status:'draft'},{...run('03'),status:'void'},{...run('04'),date:'2025-04-28'}],employees,{year:2026});assert.equal(result.rows.length,1);assert.equal(result.issues.length,1);
    const unknown=reconciliation.build([posted],[],{year:2026,mode:'annual'}).rows[0];assert.equal(unknown.benchmark,null);
});
test('CSV distinguishes potential annual excess and escapes spreadsheet formula names',()=>{
    const result=reconciliation.build([run('01')],[{id:1,name:'=unsafe'}],{year:2026,mode:'annual'});const csv=reconciliation.csv(result);assert.ok(csv.includes('not final refund'));assert.ok(csv.includes("'=unsafe"));assert.ok(csv.includes('1100.00'));
});

test('archived employee masters remain available for final-pay reconciliation',()=>{
 const result=reconciliation.build([run('01')],[{...employees[0],archived:true}],{year:2026,mode:'annual'});
 assert.equal(result.rows[0].benchmark,0);assert.equal(result.rows[0].over,110000);
});
