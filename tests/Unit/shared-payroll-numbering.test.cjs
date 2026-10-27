const test = require('node:test');
const assert = require('node:assert/strict');
const payroll = require('../../public/js/payroll-register.js');
test('database payroll keeps posted and void numbers and allocates a new unused number', () => {
    const records = [{id: 'one', number: 'PYRJ-000001', status: 'posted'}, {id: 'three', number: 'PYRJ-000003', status: 'void'}, {id: 'new', number: '', status: 'draft'}];
    const result = payroll.renumber(records, true);
    assert.deepEqual(result.map(run => run.number), ['PYRJ-000001', 'PYRJ-000003', 'PYRJ-000004']);
    assert.equal(records[2].number, '');
});
