const test = require('node:test');
const assert = require('node:assert/strict');
const payroll = require('../../public/js/payroll-register.js');
const posting = require('../../public/js/sheet-posting.js');
const employee = {name: 'Sample Employee', payType: 'Taxable', basic: '20000', sssEe: '900', phicEe: '500', hdmfEe: '200', withholding: '100', sssEr: '2030', phicEr: '500', hdmfEr: '200'};
const draft = () => ({id: 'run-1', number: 'PYRJ-000001', status: 'draft', name: '', date: '2026-10-20', periodStart: '2026-10-01', periodEnd: '2026-10-15', employees: [{...employee}]});

test('editing posted payroll keeps its identity and snapshots the previous posting without mutating it', () => {
    const posted = {...draft(), status: 'posted', postedAt: '2026-10-20T00:00:00.000Z', attachments: [{name: 'payroll.pdf'}]};
    const original = structuredClone(posted);
    const edited = payroll.reopenForEditing([posted], posted.id)[0];
    assert.equal(edited.status, 'draft');
    assert.equal(edited.id, posted.id);
    assert.equal(edited.number, posted.number);
    assert.equal(edited.draftTouched, true);
    assert.equal(edited.draftSaved, true);
    assert.equal(edited.postedAt, undefined);
    assert.deepEqual(edited.editHistory[0].previous, original);
    assert.deepEqual(posted, original);
    const reposted = payroll.post([edited], [edited.id])[0];
    assert.equal(reposted.status, 'posted');
    assert.equal(reposted.editHistory.length, 1);
    assert.equal(payroll.reopenForEditing([reposted], reposted.id)[0].editHistory.length, 2);
});

test('edit keeps one draft per page and rejects void or missing entries', () => {
    const posted = {...draft(), id: 'posted', status: 'posted'};
    assert.throws(() => payroll.reopenForEditing([posted, draft()], posted.id), /existing draft/);
    assert.throws(() => payroll.reopenForEditing([{...posted, status: 'void'}], posted.id), /Only draft or posted/);
    assert.throws(() => payroll.reopenForEditing([], 'missing'), /Only draft or posted/);
    const records = [draft()];
    assert.equal(payroll.reopenForEditing(records, 'run-1'), records);
});

test('payroll calculates gross, net and employer cost in exact cents', () => {
    assert.deepEqual(payroll.totals(employee), {gross: 2000000, net: 1830000, cost: 2273000});
    assert.deepEqual(payroll.runTotals({...draft(), employees: [employee, {...employee, basic: '20000.01'}]}), {gross: 4000001, net: 3660001, cost: 4546001});
    assert.equal(payroll.cents('0.29'), 29);
    for (const value of ['-1', '1.001', '1e3', 'NaN', '12abc', '1000000000']) assert.throws(() => payroll.cents(value));
});
test('posting validates dates and employees while allowing optional run names', () => {
    assert.equal(payroll.error(draft()), '');
    for (const changes of [{date: '2026-02-30'}, {periodStart: '2026-11-01'}, {periodEnd: ''}, {employees: []}, {employees: [{...employee, name: ' '}]}, {employees: [{...employee, basic: '0'}]}, {employees: [{...employee, basic: '5'}]}, {employees: [{...employee, basic: 'oops'}]}, {employees: [employee, {...employee, name: ' sample  EMPLOYEE '}]}]) {
        assert.notEqual(payroll.error({...draft(), ...changes}), '');
    }
});
test('new runs reuse the existing draft and do not start touched', () => {
    const pending = draft();
    assert.equal(payroll.createRun([pending], {}), pending);
    const blank = payroll.createRun([], {date: '2026-10-20', from: '2026-10-01', to: '2026-10-31'});
    assert.equal(blank.draftTouched, false);
    assert.equal(blank.status, 'draft');
    assert.deepEqual(blank.employees, []);
});
test('toolbar posts the single visible draft without opening an entry popup', () => {
    const records = [draft(), {...draft(), id: 'posted', status: 'posted'}];
    const result = payroll.post(records, posting.candidates(records).map(run => run.id));
    assert.equal(result[0].status, 'posted');
    assert.equal(records[0].status, 'draft');
    assert.ok(result[0].postedAt);
    assert.equal(result[1], records[1]);
    assert.throws(() => payroll.post(records, ['posted']));
    assert.throws(() => payroll.post([{...draft(), employees: []}], ['run-1']));
});
test('deletion closes numbering gaps without changing stable identifiers', () => {
    const records = [draft(), {...draft(), id: 'run-3', number: 'PYRJ-000003'}];
    const result = payroll.renumber(records);
    assert.equal(result[1].number, 'PYRJ-000002');
    assert.equal(result[1].id, 'run-3');
    assert.equal(records[1].number, 'PYRJ-000003');
});
test('import previews a single run atomically and blocks duplicates or incomplete employee rows', () => {
    const row = ['2026-10-20', 'October payroll', '2026-10-01', '2026-10-15', employee.name, 'Taxable', ...payroll.amountFields.map(field => employee[field] || '')];
    const rows = [payroll.importHeaders, row];
    const preview = payroll.importRun(rows, []);
    assert.equal(preview.status, 'draft');
    assert.equal(preview.employees.length, 1);
    assert.equal(payroll.runTotals(preview).net, 1830000);
    assert.throws(() => payroll.importRun(rows, [draft()]), /already exists/);
    assert.throws(() => payroll.importRun(rows, [{...draft(), date: '2026-10-25'}]), /existing draft/);
    assert.throws(() => payroll.importRun([...rows, row], []), /more than once/);
    assert.throws(() => payroll.importRun([rows[0], [...row.slice(0, 4), '', ...row.slice(5)]], []), /employee’s name/);
    const differentRun = [...row]; differentRun[0] = '2026-10-21';
    assert.throws(() => payroll.importRun([...rows, differentRun], []), /same payroll run/);
    assert.throws(() => payroll.importRun([['wrong'], row], []), /template headers/);
    assert.throws(() => payroll.importRun([rows[0], [...row.slice(0, 6), 'oops', ...row.slice(7)]], []), /non-negative amount/);
});
