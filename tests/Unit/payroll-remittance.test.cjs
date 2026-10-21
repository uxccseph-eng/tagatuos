const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const remittance = require('../../public/js/payroll-remittance.js');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/js/workspace-ledger.js'), 'utf8');
const catalog = [
    {code: '1020', name: 'Cash in Bank', type: 'Asset', is_cash_bank: true},
    ...[['2340', 'Salaries Payable'], ['2310', 'SSS Payable'], ['2320', 'PhilHealth Payable'], ['2330', 'Pag-IBIG Payable'], ['2350', 'Payroll Withholding Tax Payable']].map(([code, name]) => ({code, name, type: 'Liability'})),
];
const run = () => ({id: 'payroll-one', number: 'PYRJ-000001', status: 'posted', postedAt: '2026-10-21T00:00:00Z', date: '2026-10-21', periodStart: '2026-10-01', periodEnd: '2026-10-31', employees: [{name: 'Test employee', payType: 'Taxable', basic: '30000', sssEe: '1500', sssEr: '3030', phicEe: '750', phicEr: '750', hdmfEe: '200', hdmfEr: '200', withholding: '1000'}]});
const choices = () => ({date: '2026-10-31', bank: '1020', accounts: {salary: '2340', sss: '2310', phic: '2320', hdmf: '2330', withholding: '2350'}});
function ledger(storage = new Map(), key = 'workspace-one') {
    const storageKey = `kontadurya.ledger.v1.${key}`;
    if (!storage.has(storageKey)) storage.set(storageKey, JSON.stringify({sales: [], collections: [], purchases: [], payments: [], journalEntries: [], cwtCertificates: [], wtpCertificates: []}));
    const context = {document: {getElementById: () => ({textContent: '{"period":"2026-10"}', dataset: {key}}), querySelectorAll: () => []}, sessionStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value)}, window: {WorkspaceAccounts: catalog, addEventListener: () => {}}, location: {reload: () => {}}};
    vm.createContext(context); vm.runInContext(source, context);
    return context.window.KontaduryaLedger;
}
test('posted remittance debits all liabilities including employer shares and credits bank once', () => {
    const store = new Map(), books = ledger(store), payroll = run(), snapshot = structuredClone(payroll);
    const entry = remittance.post(payroll, choices(), books, catalog);
    assert.equal(entry.status, 'posted');
    assert.equal(entry.number, 'GJ-000001');
    assert.equal(entry.payrollRunId, payroll.id);
    assert.equal(entry.lines[0].debit, 2655000);
    assert.equal(entry.lines[1].debit, 453000);
    assert.equal(entry.lines[2].debit, 150000);
    assert.equal(entry.lines[3].debit, 40000);
    assert.equal(entry.lines[4].debit, 100000);
    assert.equal(entry.lines.at(-1).credit, 3398000);
    assert.equal(books.journalAmounts(entry).variance, 0);
    assert.equal(ledger(store).journalEntries()[0].number, entry.number);
    assert.ok(books.bookEntries().some(item => item.number === entry.number));
    assert.deepEqual(payroll, snapshot);
    assert.equal(ledger(store, 'another-workspace').journalEntries().length, 0);
});
test('repeated remittance is blocked; voiding permits replacement but prevents restoring a duplicate', () => {
    const books = ledger(), payroll = run();
    remittance.post(payroll, choices(), books, catalog);
    assert.throws(() => remittance.post(payroll, choices(), books, catalog), /already remitted/);
    const entries = books.journalEntries(); entries[0].status = 'void'; entries[0].voidReason = 'Incorrect payment account'; books.saveJournalEntries(entries);
    const replacement = remittance.post(payroll, choices(), books, catalog);
    assert.equal(replacement.number, 'GJ-000002');
    const restored = books.journalEntries(); restored[0].status = 'posted';
    assert.throws(() => books.saveJournalEntries(restored), /already has a posted remittance/);
    assert.equal(books.journalEntries()[0].status, 'void');
    assert.equal(remittance.linked(payroll.id, books).length, 2);
});
test('drafts, invalid dates, inactive accounts and incorrect account types cannot remit', () => {
    const books = ledger();
    assert.throws(() => remittance.build({...run(), status: 'draft'}, choices(), books, catalog), /Post the payroll/);
    for (const date of ['2026-10-20', '2026-02-30', 'invalid']) assert.throws(() => remittance.build(run(), {...choices(), date}, books, catalog), /valid remittance date/);
    assert.throws(() => remittance.build(run(), {...choices(), bank: '2310'}, books, catalog), /cash \/ bank/);
    assert.throws(() => remittance.build(run(), {...choices(), accounts: {...choices().accounts, sss: '1020'}}, books, catalog), /liability account/);
    assert.throws(() => remittance.build(run(), choices(), books, catalog.map(item => ({...item, archived: true}))), /cash \/ bank/);
    assert.equal(books.journalEntries().length, 0);
});
test('zero liability categories are omitted; financial links and posted voucher amounts stay protected', () => {
    const books = ledger(), payroll = run(); payroll.employees = [{name: 'Test', basic: '100'}];
    const entry = remittance.post(payroll, {date: payroll.date, bank: '1020', accounts: {salary: '2340'}}, books, catalog);
    assert.equal(entry.lines.length, 2);
    const changed = books.journalEntries(); changed[0].date = '2026-11-01';
    assert.throws(() => books.saveJournalEntries(changed), /generate a replacement/);
    const detached = books.journalEntries(); delete detached[0].origin;
    assert.throws(() => books.saveJournalEntries(detached), /link cannot be removed/);
});
test('failed persistence rolls back the journal without marking payroll remitted', () => {
    const store = new Map(), books = ledger(store);
    store.set = () => { throw new Error('Quota exceeded'); };
    assert.throws(() => remittance.post(run(), choices(), books, catalog), /storage is unavailable/);
    assert.equal(books.journalEntries().length, 0);
    assert.doesNotThrow(() => remittance.assertUnremitted(run().id, books));
});

test('one voucher remits selected liabilities across multiple runs and leaves other categories outstanding', () => {
    const books = ledger(), first = run(), second = {...run(), id: 'payroll-two', number: 'PYRJ-000002'};
    const selected = {...choices(), liabilities: ['salary', 'withholding'], reference: 'TRANSFER-100'};
    const entry = remittance.saveBatch([first, second], selected, books, catalog);
    assert.equal(entry.reference, 'TRANSFER-100');
    assert.equal(entry.lines.length, 3);
    assert.equal(entry.lines.at(-1).credit, 5510000);
    assert.equal(entry.payrollAllocations.length, 2);
    for (const source of [first, second]) {
        assert.equal(remittance.linked(source.id, books).length, 1);
        const outstanding = Object.fromEntries(remittance.remaining(source, books).map(item => [item.key, item.amount]));
        assert.equal(outstanding.salary, 0);
        assert.equal(outstanding.withholding, 0);
        assert.equal(outstanding.sss, 453000);
    }
    const rest = remittance.post(first, choices(), books, catalog);
    assert.equal(rest.lines.length, 4);
    assert.equal(rest.lines.at(-1).credit, 643000);
    assert.throws(() => remittance.saveBatch([first, second], selected, books, catalog), /already remitted/);
});

test('saved remittance draft preserves run choices and can be posted in place without a new voucher number', () => {
    const books = ledger(), source = run();
    const draft = remittance.saveBatch([source], {...choices(), bank: '', accounts: {}, liabilities: ['sss']}, books, catalog, 'draft');
    assert.equal(draft.status, 'draft');
    assert.equal(draft.lines[0].account, '');
    assert.equal(remittance.remaining(source, books).find(item => item.key === 'sss').amount, 453000);
    assert.throws(() => remittance.assertUnremitted(source.id, books), /reserved/);
    assert.throws(() => remittance.post(source, choices(), books, catalog), /Continue remittance draft/);
    const posted = remittance.saveBatch([source], {...choices(), liabilities: ['sss']}, books, catalog, 'posted', draft.id);
    assert.equal(posted.number, draft.number);
    assert.equal(posted.id, draft.id);
    assert.equal(books.journalEntries().length, 1);
    assert.equal(books.journalError(posted), null);
    assert.equal(remittance.remaining(source, books).find(item => item.key === 'sss').amount, 0);
});

test('draft remittances obey the Journal draft limit; selections and latest source dates are validated', () => {
    const books = ledger();
    books.saveJournalEntries([{...books.nextJournalEntry(), status: 'draft', lines: []}]);
    assert.throws(() => remittance.saveBatch([run()], choices(), books, catalog, 'draft'), /existing Journal draft/);
    const empty = ledger();
    assert.throws(() => remittance.saveBatch([], choices(), empty, catalog), /Select at least one/);
    assert.throws(() => remittance.saveBatch([run(), run()], choices(), empty, catalog), /distinct/);
    assert.throws(() => remittance.saveBatch([run()], {...choices(), liabilities: []}, empty, catalog), /Select at least one liability/);
    assert.throws(() => remittance.saveBatch([run(), {...run(), id: 'later', date: '2026-11-01'}], choices(), empty, catalog), /every selected payroll date/);
    assert.equal(empty.journalEntries().length, 0);
});

test('editing a generated draft cannot post changed amounts that disagree with its allocations', () => {
    const books = ledger();
    const draft = remittance.saveBatch([run()], {...choices(), liabilities: ['sss']}, books, catalog, 'draft');
    const changed = books.journalEntries(); changed[0].status = 'posted';
    changed[0].lines[0].debit += 100; changed[0].lines.at(-1).credit += 100;
    assert.throws(() => books.saveJournalEntries(changed), /amounts must match/);
    assert.equal(books.journalEntries()[0].status, 'draft');
    assert.equal(books.journalEntries()[0].id, draft.id);
});
