const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/company-ledger-sync.js', 'utf8');
const empty = () => ({sales: [], purchases: [], collections: [], payments: [], cwtCertificates: [], wtpCertificates: [], journalEntries: [], nextCollectionId: 1, nextCollectionNumber: 1});
function boot({initialized = true, legacy = null, canWrite = true, fetcher = null} = {}) {
    class Element {
        constructor() { this.children = []; this.listeners = {}; this.dataset = {}; this.textContent = ''; }
        append(...items) { this.children.push(...items); }
        replaceChildren() { this.children = []; }
        setAttribute() {}
        addEventListener(name, fn) { this.listeners[name] = fn; }
        click() { return this.listeners.click?.(); }
    }
    const storage = new Map();
    if (legacy) storage.set('kontadurya.ledger.v1.company-test', JSON.stringify(legacy));
    const requests = [], intervals = [], database = {company_id: 1, revision: 0, initialized, state: empty(), payroll: [], journals: [], url: '/sheets/database', csrf: 'csrf', canWrite};
    const body = new Element();
    const document = {body, hidden: false, documentElement: {classList: {toggle() {}}}, getElementById: () => ({dataset: {key: 'company-test'}}), createElement: () => new Element(), addEventListener() {}};
    const sessionStorage = {getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)};
    const window = {CompanyLedgerDatabase: database, addEventListener() {}, dispatchEvent() {}};
    let revision = 0;
    const fetch = async (url, options) => {
        const payload = JSON.parse(options.body);
        requests.push(payload);
        if (fetcher) return fetcher(payload, requests.length);
        return {ok: true, json: async () => ({company_id: 1, initialized: true, state: payload.state, payroll: payload.payroll, revision: ++revision, journals: []})};
    };
    vm.runInNewContext(source, {window, document, sessionStorage, fetch, crypto: require('node:crypto').webcrypto, location: {href: 'http://localhost/sheets/sales', origin: 'http://localhost', reload() {}}, URL, Blob, CustomEvent: class {}, setTimeout: () => 1, clearTimeout() {}, setInterval: fn => intervals.push(fn), console});
    return {api: window.CompanyLedger, storage, requests, sessionStorage, database, buttons: () => body.children[0].children[1].children, intervals};
}
test('database bootstrap takes precedence over old browser cache', () => {
    const legacy = empty(); legacy.sales.push({id: 'old'});
    const p = boot({legacy});
    assert.equal(p.api.state().sales.length, 0);
    assert.equal(p.requests.length, 0);
});
test('a save remains pending until acknowledged and writes a recoverable copy', async () => {
    const p = boot(); const state = empty(); state.sales.push({id: 1});
    p.api.saveState(state);
    assert.equal(p.api.pending(), true);
    assert.equal(p.storage.has('kontadurya.ledger.v1.company-test.pending-database-save'), true);
    await p.api.flush();
    assert.equal(p.requests.length, 1);
    assert.equal(p.requests[0].revision, 0);
    assert.equal(p.api.pending(), false);
    assert.equal(p.storage.has('kontadurya.ledger.v1.company-test.pending-database-save'), false);
});
test('changes made during a save serialize behind its acknowledged revision', async () => {
    let release;
    const p = boot({fetcher: (payload, count) => count === 1 ? new Promise(resolve => { release = () => resolve({ok: true, json: async () => ({...p.database, revision: 1, state: payload.state, payroll: payload.payroll})}); }) : Promise.resolve({ok: true, json: async () => ({...p.database, revision: 2, state: payload.state, payroll: payload.payroll})})});
    const state = empty(); state.sales.push({id: 1}); p.api.saveState(state);
    const saving = p.api.flush();
    state.sales.push({id: 2}); p.api.saveState(state);
    release(); await saving;
    assert.equal(p.requests.length, 2);
    assert.equal(p.requests[1].revision, 1);
    assert.equal(p.requests[1].state.sales.length, 2);
    assert.equal(p.api.pending(), false);
});
test('conflict retains unsaved data and blocks overwriting the newer version', async () => {
    const p = boot({fetcher: async () => ({ok: false, status: 409, json: async () => ({message: 'Another accountant saved this book'})})});
    const state = empty(); state.sales.push({id: 1}); p.api.saveState(state);
    await assert.rejects(p.api.flush(), /Another accountant/);
    assert.equal(p.api.pending(), true);
    assert.equal(JSON.parse(p.storage.get('kontadurya.ledger.v1.company-test.pending-database-save')).state.sales.length, 1);
    assert.throws(() => p.api.saveState(empty()), /Another accountant/);
});
test('network retry reuses the original save identifier to avoid duplicate posting', async () => {
    const p = boot({fetcher: async (payload, count) => {
        if (count === 1) throw Error('Connection lost');
        return {ok: true, json: async () => ({...p.database, revision: 1, state: payload.state, payroll: payload.payroll})};
    }});
    p.api.saveState(empty()); await assert.rejects(p.api.flush(), /Connection lost/);
    p.buttons().find(button => button.textContent === 'Retry save').click();
    await p.api.flush();
    assert.equal(p.requests[0].request_id, p.requests[1].request_id);
    assert.equal(p.api.pending(), false);
});
test('existing browser records are preserved and require an explicit reviewed transfer', async () => {
    const legacy = empty(); legacy.sales.push({id: 'legacy-sale'});
    const p = boot({initialized: false, legacy});
    assert.equal(p.requests.length, 0);
    assert.equal(p.api.state().sales[0].id, 'legacy-sale');
    assert.equal(p.storage.has('kontadurya.ledger.v1.company-test.before-database-transfer'), true);
    p.buttons().find(button => button.textContent === 'Transfer reviewed records').click();
    await p.api.flush();
    assert.equal(p.requests[0].state.sales[0].id, 'legacy-sale');
    assert.equal(p.storage.has('kontadurya.ledger.v1.company-test.before-database-transfer'), true);
});
test('read-only members cannot stage changes and storage failure cannot leave a hidden mutation', () => {
    const readOnly = boot({canWrite: false});
    assert.throws(() => readOnly.api.savePayroll([]), /permission/);
    const p = boot(); p.sessionStorage.setItem = () => { throw Error('Quota exceeded'); };
    const state = empty(); state.sales.push({id: 'never-staged'});
    assert.throws(() => p.api.saveState(state), /preserve/);
    assert.equal(p.api.state().sales.length, 0);
});
