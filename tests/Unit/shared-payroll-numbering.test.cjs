const test = require('node:test');
const assert = require('node:assert/strict');
const payroll = require('../../public/js/payroll-register.js');
test('database payroll keeps posted and void numbers while drafts remain unnumbered', () => {
    const records = [{id: 'one', number: 'PYRJ-000001', status: 'posted'}, {id: 'three', number: 'PYRJ-000003', status: 'void'}, {id: 'new', number: '', status: 'draft'}];
    const result = payroll.renumber(records, true);
    assert.deepEqual(result.map(run => run.number), ['PYRJ-000001', 'PYRJ-000003', '']);
    assert.equal(records[2].number, '');
});

for(const historical of [false,true])test(`payroll reclaims ${historical?'previously archived':'newly deleted'} numbers and preserves custom references`,()=>{
    const previous=[{id:'deleted',number:'PYRJ-000001',status:historical?'archived':'void'},{id:'kept',number:'PYRJ-000003',status:'posted'},{id:'custom',number:'CLIENT-PAYROLL-99',status:'posted'},{id:'draft',number:'',status:'draft'}];
    const rows=previous.map(row=>row.id==='deleted'?{...row,status:'archived'}:{...row});
    const result=payroll.renumber(rows,true,previous);
    assert.deepEqual(result.map(row=>row.number),['PYRJ-000001','PYRJ-000001','CLIENT-PAYROLL-99','']);
    const posted=payroll.renumber([...result,{id:'new',number:'',status:'posted'}],true,result);
    assert.equal(posted.at(-1).number,'PYRJ-000002');
    assert.deepEqual(payroll.renumber(posted,true,posted),posted);
    assert.equal(previous[1].number,'PYRJ-000003');
});
