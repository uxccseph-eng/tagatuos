const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/company-ledger-sync.js', 'utf8');
const empty = () => ({sales: [], purchases: [], collections: [], payments: [], cwtCertificates: [], wtpCertificates: [], journalEntries: [], nextCollectionId: 1, nextCollectionNumber: 1});
function boot({initialized = true, legacy = null, canWrite = true, fetcher = null, storage = new Map(), snapshot = {}} = {}) {
    class Element {
        constructor() { this.children = []; this.listeners = {}; this.dataset = {}; this.textContent = ''; }
        append(...items) { this.children.push(...items); }
        replaceChildren() { this.children = []; }
        setAttribute() {}
        addEventListener(name, fn) { this.listeners[name] = fn; }
        click() { return this.listeners.click?.(); }
    }
    if (legacy) storage.set('kontadurya.ledger.v1.company-test', JSON.stringify(legacy));
    const requests = [], intervals = [], database = {company_id: 1, revision: 0, initialized, state: empty(), payroll: [], journals: [], url: '/sheets/database', csrf: 'csrf', canWrite, ...snapshot};
    const body = new Element();
    const document = {body, hidden: false, documentElement: {classList: {toggle() {}}}, getElementById: () => ({dataset: {key: 'company-test'}}), createElement: () => new Element(), addEventListener() {}};
    const sessionStorage = {getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)};
    const window = {CompanyLedgerDatabase: database, addEventListener() {}, dispatchEvent() {}};
    let revision = database.revision;
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

const recoveryKey = 'kontadurya.ledger.v1.company-test.pending-database-save';
test('failed save survives reload and requires explicit restore and reviewed save', async () => {
    const first = boot({fetcher: async () => ({ok:false, status:422, json:async()=>({message:'Select an expense account'})})});
    const state = empty(); state.purchases.push({id:1,status:'posted'});
    first.api.saveState(state); await assert.rejects(first.api.flush(), /expense account/);
    const requestId = first.requests[0].request_id;
    const locked = boot({storage:first.storage});
    assert.equal(locked.api.state().purchases.length, 0);
    assert.equal(locked.requests.length, 0);
    await assert.rejects(locked.api.flush(), /expense account/);
    locked.buttons().find(button => button.textContent === 'Restore unsaved work').click();
    const restored = boot({storage:first.storage});
    assert.equal(restored.api.state().purchases.length, 1);
    assert.equal(restored.api.postingLabel('purchases',state.purchases[0]), 'Not saved');
    await assert.rejects(restored.api.flush(), /Review/);
    restored.buttons().find(button => button.textContent === 'Save recovered work').click();
    await restored.api.flush();
    assert.equal(restored.requests[0].request_id, requestId);
    assert.equal(restored.api.postingLabel('purchases',state.purchases[0]), 'Posted');
    assert.equal(first.storage.has(recoveryKey), false);
});
test('a recovery never overwrites a different database revision or another company', () => {
    for (const change of [{revision:2},{company_id:2}]) {
        const state = empty(); state.sales.push({id:1,status:'posted'});
        const storage = new Map([[recoveryKey,JSON.stringify({company_id:1,revision:0,state,payroll:[],...change})]]);
        const p = boot({storage});
        assert.equal(p.buttons().some(button=>button.textContent==='Restore unsaved work'),false);
        assert.throws(()=>p.api.saveState(state), /unsaved work|different version/);
        assert.equal(storage.has(recoveryKey),true);
    }
});
test('an invalid successful HTTP response does not discard unsaved records', async () => {
    const p = boot({fetcher:async()=>({ok:true,json:async()=>({message:'OK'})})});
    const state = empty(); state.sales.push({id:1,status:'posted'}); p.api.saveState(state);
    assert.equal(p.api.postingLabel('sales',state.sales[0]),'Saving\u2026');
    await assert.rejects(p.api.flush(), /did not confirm/);
    assert.equal(p.api.postingLabel('sales',state.sales[0]),'Not saved');
    assert.equal(p.storage.has(recoveryKey),true);
});
test('already confirmed recovery is recognized without posting again', () => {
    const state = empty(); state.sales.push({id:1,status:'posted'});
    const storage = new Map([[recoveryKey,JSON.stringify({company_id:1,revision:0,state,payroll:[]})]]);
    const p = boot({storage,snapshot:{revision:1,state}});
    assert.equal(p.api.pending(),false);
    assert.equal(storage.has(recoveryKey),false);
    assert.equal(p.requests.length,0);
});

test('correcting a rejected entry submits the corrected data with a fresh request identifier', async () => {
    const p = boot({fetcher:async(payload,count)=>count===1 ? {ok:false,status:422,json:async()=>({message:'Invalid account'})} : {ok:true,json:async()=>({...p.database,state:payload.state,payroll:payload.payroll,revision:1})}});
    const state=empty(); state.sales.push({id:1,status:'posted',account:'invalid'}); p.api.saveState(state);
    await assert.rejects(p.api.flush(),/Invalid account/);
    state.sales[0].account='corrected'; p.api.saveState(state); await p.api.flush();
    assert.equal(p.requests[1].state.sales[0].account,'corrected');
    assert.notEqual(p.requests[0].request_id,p.requests[1].request_id);
});
test('lost acknowledgements preserve the original request before saving newer edits', async () => {
    let rejectFirst;
    const p=boot({fetcher:(payload,count)=> count===1 ? new Promise((resolve,reject)=>{rejectFirst=reject;}) : Promise.resolve({ok:true,json:async()=>({...p.database,state:payload.state,payroll:payload.payroll,revision:count-1})})});
    const state=empty(); state.sales.push({id:1,status:'posted'}); p.api.saveState(state);
    const saving=p.api.flush(); state.sales.push({id:2,status:'posted'}); p.api.saveState(state);
    rejectFirst(Error('Response lost')); await assert.rejects(saving,/Response lost/);
    p.buttons().find(button=>button.textContent==='Retry save').click(); await p.api.flush();
    assert.equal(p.requests[0].request_id,p.requests[1].request_id);
    assert.equal(p.requests[1].state.sales.length,1);
    assert.equal(p.requests[2].state.sales.length,2);
    assert.equal(p.requests[2].revision,1);
    assert.equal(p.api.pending(),false);
});


test('editing an already posted entry remains unconfirmed until its new values are saved', async () => {
    const state = empty(); state.sales = [{id:1,status:'posted',gross:10000}];
    let release;
    const p = boot({snapshot:{state,revision:1},fetcher:payload=>new Promise(resolve=>{release=()=>resolve({ok:true,json:async()=>({...p.database,state:payload.state,revision:2})});})});
    assert.equal(p.api.postingLabel('sales',{id:1}),'Posted');
    const changed=p.api.state(); changed.sales[0].gross=20000; p.api.saveState(changed);
    assert.equal(p.api.postingLabel('sales',{id:1}),'Saving\u2026');
    const saving=p.api.flush(); release(); await saving;
    assert.equal(p.api.postingLabel('sales',{id:1}),'Posted');
});

test('a rejected posted edit shows Not saved even though the original entry was posted', async () => {
    const state=empty(); state.sales=[{id:1,status:'posted',gross:10000}];
    const p=boot({snapshot:{state,revision:1},fetcher:async()=>({ok:false,status:422,json:async()=>({message:'Correction rejected'})})});
    const changed=p.api.state(); changed.sales[0].gross=0; p.api.saveState(changed);
    await assert.rejects(p.api.flush(),/Correction rejected/);
    assert.equal(p.api.postingLabel('sales',{id:1}),'Not saved');
    assert.equal(p.database.state.sales[0].gross,10000);
    assert.equal(p.storage.has(recoveryKey),true);
});
