const test = require('node:test');
const assert = require('node:assert/strict');
const {build, annualTax, excelRows} = require('../../public/js/alphalist-data.js');
const employees = [{id: 1, code: 'E-1', name: 'Test Employee', tin: '123-456-789-00000'}];
const line = overrides => ({name: 'Test Employee', payType: 'Taxable', basic: '50000', taxableAllowance: '2000', deMinimis: '1000', benefits: '5000', taxableBenefits: '1000', sssEe: '1000', phicEe: '500', hdmfEe: '200', withholding: '5000', sssEr: '2000', ...overrides});
const run = (id, date, employee, status = 'posted') => ({id, number: 'PY-' + id, date, status, employees: [employee]});

test('annual rollup excludes drafts, voids, other years and MWE', () => {
    const report = build([run('1', '2026-01-10', line()), run('2', '2026-12-10', line()), run('3', '2025-12-30', line()), run('4', '2026-02-10', line(), 'draft'), run('5', '2026-02-10', line(), 'void'), run('6', '2026-04-10', line({name: 'MWE employee', payType: 'Minimum Wage Earner'}))], employees, 2026);
    assert.equal(report.rows.length, 1);
    assert.equal(report.stats.base, 11600000);
    assert.equal(report.stats.tax, 1000000);
    assert.equal(report.stats.exempt, 1340000);
    assert.equal(report.stats.taxable, 10260000);
    assert.equal(report.stats.excluded, 1);
    assert.equal(report.rows[0].employee.lines.length, 2);
    assert.equal(report.issues.length, 0);
});
test('missing benefit allocation is never silently exempted', () => {
    const report = build([run('1', '2026-10-10', line({taxableBenefits: ''}))], employees, 2026);
    assert.equal(report.stats.taxable, null);
    assert.equal(report.stats.exempt, null);
    assert.equal(report.stats.due, null);
    assert.equal(report.stats.base, 5800000);
    assert.match(report.issues.join(' '), /taxable portion/);
});
test('invalid amounts make totals incomplete without mutating payroll', () => {
    const runs = [run('1', '2026-10-10', line({basic: 'not money'}))], before = JSON.stringify(runs);
    const report = build(runs, employees, 2026);
    assert.equal(report.stats.base, null);
    assert.equal(report.stats.tax, null);
    assert.equal(JSON.stringify(runs), before);
});
test('unknown pay types and ambiguous identities are flagged', () => {
    const unknown = build([run('1', '2026-01-10', line({payType: ''}))], employees, 2026);
    assert.equal(unknown.rows.length, 0);
    assert.match(unknown.issues[0], /classification/);
    const ambiguous = build([run('1', '2026-01-10', line())], [...employees, {...employees[0], id: 2}], 2026);
    assert.match(ambiguous.issues.join(' '), /multiple Masters/);
});
test('duplicate payroll IDs count once and mixed MWE status needs review', () => {
    const first = run('1', '2026-01-10', line());
    const report = build([first, first, run('2', '2026-02-10', line({payType: 'Minimum Wage Earner'}))], employees, 2026);
    assert.equal(report.stats.base, 5800000);
    assert.match(report.issues.join(' '), /duplicate payroll/);
    assert.match(report.issues.join(' '), /Both MWE/);
});
test('annual tax uses the verified 2023 onward brackets', () => {
    for (const [pesos, due] of [[250000, 0], [400000, 22500], [800000, 102500], [2000000, 402500], [8000000, 2202500], [9000000, 2552500]]) assert.equal(annualTax(pesos * 100, 2026), due * 100);
    assert.equal(annualTax(100000000, 2022), null);
    assert.equal(annualTax(null, 2026), null);
});
test('missing TIN, unsupported year and excessive benefits need review', () => {
    const report = build([run('1', '2022-01-10', line({benefits: '100000', taxableBenefits: '0'}))], [], 2022);
    assert.match(report.issues.join(' '), /TIN/);
    assert.match(report.issues.join(' '), /90,000/);
    assert.equal(report.stats.due, null);
});
test('Excel output preserves numeric peso amounts, totals, TIN text and review markers', () => {
    const {metadata, xlsx} = require('../../public/js/financial-report-template.js');
    const report = build([run('1', '2026-01-10', line({taxableBenefits: ''}))], employees, 2026);
    const rows = excelRows(report);
    assert.equal(rows[0].cells[4], 58000);
    assert.equal(rows[0].cells[5], 'Review');
    assert.equal(rows[0].cells[3], employees[0].tin);
    assert.equal(rows.at(-1).kind, 'grand');
    const meta = metadata({company_name: 'Annual QA'}, 'Alphalist Non-MWE', {startDate: '2026-01-01', endDate: '2026-12-31'}, 'income-statement');
    const bytes = Buffer.from(xlsx(meta, report.columns, rows, {wrap: true}));
    assert.equal(bytes.readUInt32LE(0), 0x04034b50);
    assert.ok(bytes.includes(Buffer.from('58000')));
    assert.ok(bytes.includes(Buffer.from('Review')));
    assert.ok(bytes.includes(Buffer.from('Recorded withheld')));
});

test('Form 2316 payload carries original posted components and scoped employee identity', () => {
    const {certificatePayload} = require('../../public/js/alphalist-data.js');
    const report = build([run('annual', '2026-01-10', line())], employees, 2026);
    const payload = certificatePayload(report.rows[0].employee, 2026);
    assert.equal(payload.records[0].basic, '50000.00');
    assert.equal(payload.records[0].employee_id, 1);
    assert.equal(payload.records[0].taxable_benefits, '1000.00');
    assert.equal(payload.records[0].contributions, '1700.00');
    assert.equal(payload.records[0].source_id, 'annual:0');
    assert.equal(payload.records[0].status, 'posted');
    assert.throws(() => certificatePayload({...report.rows[0].employee, employeeId: null}, 2026), /Masters/);
    assert.throws(() => certificatePayload({...report.rows[0].employee, mwe: 1}, 2026), /classification/);
});
