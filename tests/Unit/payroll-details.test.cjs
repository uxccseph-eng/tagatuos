const test = require('node:test');
const assert = require('node:assert/strict');
const details = require('../../public/js/payroll-details.js');
const run = () => ({status: 'posted', employees: [{name: 'Example employee', basic: '30000', taxableAllowance: '1000', benefits: '500', deMinimis: '200', sssEe: '1500', sssEr: '3030', phicEe: '750', phicEr: '750', hdmfEe: '200', hdmfEr: '200', withholding: '1000'}]});

test('journal preview balances actual payroll earnings, contributions and liabilities in cents', () => {
    const result = details.journal(run());
    assert.equal(result.debit, 3568000);
    assert.equal(result.credit, 3568000);
    assert.deepEqual(result.lines.find(line => line[0] === 'SSS Payable'), ['SSS Payable', 0, 453000]);
    assert.deepEqual(result.lines.find(line => line[0] === 'Withholding Tax Payable'), ['Withholding Tax Payable', 0, 100000]);
    assert.deepEqual(result.lines.find(line => line[0] === 'Salaries Payable'), ['Salaries Payable', 0, 2825000]);
});
test('journal preview aggregates multiple employees without altering a posted run', () => {
    const source = run(), snapshot = structuredClone(source);
    source.employees.push({...source.employees[0]});
    const result = details.journal(source);
    assert.equal(result.debit, 7136000);
    assert.equal(result.debit, result.credit);
    assert.deepEqual(source.employees[0], snapshot.employees[0]);
    assert.equal(source.status, 'posted');
});
test('empty runs have no invented journal lines; invalid amounts are reported', () => {
    assert.deepEqual(details.journal({employees: []}), {lines: [], debit: 0, credit: 0});
    const source = run(); source.employees[0].basic = 'invalid';
    assert.throws(() => details.journal(source), /non-negative amount/);
});
test('links accept https only and reject unsafe protocols, credentials and relative URLs', () => {
    assert.equal(details.safeLink(' https://example.com/payroll '), 'https://example.com/payroll');
    for (const url of ['javascript:alert(1)', 'data:text/html,test', 'http://example.com', '/payroll', 'https://user:password@example.com']) assert.throws(() => details.safeLink(url));
});
test('attachments enforce document types, per-file and total limits, count and duplicates', () => {
    const file = {name: 'payroll.pdf', type: 'application/pdf', size: 500000};
    assert.doesNotThrow(() => details.validateAttachment(file, []));
    assert.throws(() => details.validateAttachment({...file, type: 'text/html'}, []), /PDF/);
    assert.throws(() => details.validateAttachment({...file, size: 1048577}, []), /1 MB/);
    assert.throws(() => details.validateAttachment(file, [file]), /already attached/);
    assert.throws(() => details.validateAttachment(file, Array.from({length: 5}, () => ({name: 'other', size: 1}))), /five/);
    assert.throws(() => details.validateAttachment(file, [{name: 'other', size: 2000000}]), /2 MB/);
});
