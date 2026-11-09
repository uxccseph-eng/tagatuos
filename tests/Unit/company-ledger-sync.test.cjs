const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/company-ledger-sync.js', 'utf8');
const empty = () => ({sales: [], purchases: [], collections: [], payments: [], cwtCertificates: [], wtpCertificates: [], journalEntries: [], nextCollectionId: 1, nextCollectionNumber: 1});
function boot({initialized = true, legacy = null, canWrite = true, fetcher = null, collaborative = false, storage = new Map(), snapshot = {}} = {}) {
    class Element {
        constructor() { this.children = []; this.listeners = {}; this.dataset = {}; this.textContent = ''; }
        append(...items) { this.children.push(...items); }
        replaceChildren() { this.children = []; }
        setAttribute() {}
        addEventListener(name, fn) { this.listeners[name] = fn; }
        click() { return this.listeners.click?.(); }
        showModal() { this.open = true; }
        close() { this.open = false; this.listeners.close?.(); }
        remove() { const index = body.children.indexOf(this); if (index >= 0) body.children.splice(index, 1); }
    }
    if (legacy) storage.set('kontadurya.ledger.v1.company-test', JSON.stringify(legacy));
    const requests = [], intervals = [], database = {company_id: 1, revision: 0, initialized, state: empty(), payroll: [], journals: [], url: '/sheets/database', csrf: 'csrf', canWrite, ...snapshot};
    const body = new Element();
    let reloads = 0;
    const document = {body, hidden: false, documentElement: {classList: {toggle() {}}}, getElementById: () => ({dataset: {key: 'company-test'}}), createElement: () => new Element(), querySelector: () => null, querySelectorAll: () => [], addEventListener() {}};
    const sessionStorage = {getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)};
    const local = new Map();
    const localStorage = {getItem: key => local.get(key) || null, setItem: (key, value) => local.set(key, value), removeItem: key => local.delete(key)};
    const window = {localStorage, CompanyLedgerDatabase: database, addEventListener() {}, dispatchEvent() {}};
    let revision = database.revision;
    const fetch = async (url, options) => {
        const payload = options.body ? JSON.parse(options.body) : null;
        requests.push(payload);
        if (fetcher) return fetcher(payload, requests.length);
        return {ok: true, json: async () => ({company_id: 1, initialized: true, state: payload.state, payroll: payload.payroll, revision: ++revision, journals: []})};
    };
    if (collaborative) {
        database.record_versions ||= {};
        vm.runInNewContext(fs.readFileSync('public/js/company-ledger-records.js', 'utf8'), {window});
    }
    vm.runInNewContext(source, {window, document, sessionStorage, fetch, crypto: require('node:crypto').webcrypto, location: {href: 'http://localhost/sheets/sales', origin: 'http://localhost', reload() { reloads++; }}, URL, Blob, CustomEvent: class {}, setTimeout: () => 1, clearTimeout() {}, setInterval: fn => intervals.push(fn), console});
    return {api: window.CompanyLedger, local, storage, requests, sessionStorage, database, buttons: () => body.children[0].children[1].children, dialog: () => body.children.find(element => element.className === 'company-conflict-dialog'), reloads: () => reloads, intervals};
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
    const state = empty(); state.sales.push({id: 'retry'}); p.api.saveState(state); await assert.rejects(p.api.flush(), /Connection lost/);
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
    const restored = locked;
    assert.equal(restored.reloads(), 0);
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


test('export removes company recovery data without reloading or clearing another company', async () => {
    const p = boot({fetcher: async () => ({ok: false, status: 409, json: async () => ({message: 'Version conflict'})})});
    const state = empty(); state.sales.push({id: 'a'}); p.api.saveState(state);
    await assert.rejects(p.api.flush(), /Version conflict/);
    p.storage.set('other-company.pending-database-save', 'keep');
    p.local.set(recoveryKey, 'draft'); p.local.set('other-company.pending-database-save', 'keep');
    p.buttons().find(button => button.textContent === 'Export unsaved work (.json)').click();
    assert.equal(p.storage.has(recoveryKey), false);
    assert.equal(p.storage.get('other-company.pending-database-save'), 'keep');
    assert.equal(p.local.has(recoveryKey), false);
    assert.equal(p.local.get('other-company.pending-database-save'), 'keep');
    assert.equal(p.reloads(), 0);
    assert.equal(p.api.state().sales[0].id, 'a');
    assert.ok(p.dialog());
    assert.equal(boot({storage: p.storage}).api.pending(), false);
});

test('only an explicit discard clears the draft and reloads', async () => {
    const p = boot({fetcher: async () => ({ok: false, status: 409, json: async () => ({message: 'Conflict'})})});
    const state = empty(); state.sales.push({id: 'a'}); p.api.saveState(state);
    await assert.rejects(p.api.flush(), /Conflict/);
    assert.equal(p.reloads(), 0);
    p.dialog().children[3].children[0].click();
    assert.equal(p.reloads(), 1);
    assert.equal(p.storage.has(recoveryKey), false);
});

test('explicit overwrite replaces changed entries only and uses fresh versions with a new request id', async () => {
    const base = empty(); base.sales = [{id: 'a', gross: 100}];
    const latest = empty(); latest.sales = [{id: 'a', gross: 200}, {id: 'b', gross: 300}];
    let submitted;
    const p = boot({collaborative: true, snapshot: {state: base, revision: 1, record_versions: {sales: {a: 1}}}, fetcher: async (payload, count) => {
        if (count === 1) return {ok: false, status: 409, json: async () => ({message: 'Conflict', record_conflict: true})};
        if (!payload) return {ok: true, json: async () => ({...p.database, state: latest, revision: 2, record_versions: {sales: {a: 2, b: 1}}})};
        submitted = payload;
        const state = empty(); state.sales = [{id: 'a', gross: 500}, {id: 'b', gross: 300}];
        return {ok: true, json: async () => ({...p.database, state, revision: 3, record_versions: {sales: {a: 3, b: 1}}})};
    }});
    const changed = p.api.state(); changed.sales[0].gross = 500; p.api.saveState(changed);
    await assert.rejects(p.api.flush(), /Conflict/);
    await p.dialog().children[3].children[1].click();
    assert.equal(submitted.revision, 2);
    assert.equal(submitted.operations.length, 1);
    assert.equal(submitted.operations[0].expected_version, 2);
    assert.equal(submitted.operations[0].record.gross, 500);
    assert.notEqual(submitted.request_id, p.requests[0].request_id);
    assert.equal(p.api.state().sales[1].gross, 300);
    assert.equal(p.api.pending(), false);
    assert.equal(p.reloads(), 0);
});

test('overwrite still shows server validation failures and does not reload', async () => {
    const p = boot({collaborative: true, fetcher: async (payload, count) => {
        if (count === 2) return {ok: true, json: async () => ({...p.database, revision: 1, record_versions: {}})};
        return {ok: false, status: count === 1 ? 409 : 422, json: async () => ({message: count === 1 ? 'Conflict' : 'Invalid account'})};
    }});
    const state = empty(); state.sales.push({id: 'a', account: 'invalid'}); p.api.saveState(state);
    await assert.rejects(p.api.flush(), /Conflict/);
    await p.dialog().children[3].children[1].click();
    assert.equal(p.dialog().children[2].textContent, 'Invalid account');
    assert.equal(p.api.pending(), true);
    assert.equal(p.reloads(), 0);
});


test('exported recovery can be explicitly overwritten from its original entry history without restoring its storage', async () => {
    const base = empty(); base.sales = [{id:'a',gross:100}];
    const local = empty(); local.sales = [{id:'a',gross:500}];
    const latest = empty(); latest.sales = [{id:'a',gross:200},{id:'b',gross:300}];
    const storage = new Map([[recoveryKey, JSON.stringify({company_id:1,revision:1,state:local,payroll:[],base_state:base,base_payroll:[],base_versions:{sales:{a:1}}})]]);
    let saved;
    const p = boot({storage,collaborative:true,snapshot:{state:latest,revision:2,record_versions:{sales:{a:2,b:1}}},fetcher:async payload=> {
        if (!payload) return {ok:true,json:async()=>({...p.database})};
        saved = payload;
        return {ok:true,json:async()=>({...p.database,revision:3,state:{...latest,sales:[{id:'a',gross:500},{id:'b',gross:300}]},record_versions:{sales:{a:3,b:1}}})};
    }});
    p.buttons().find(button=>button.textContent==='Export unsaved work (.json)').click();
    await p.dialog().children[3].children[1].click();
    assert.equal(saved.operations.length, 1);
    assert.equal(saved.operations[0].record.gross, 500);
    assert.equal(storage.has(recoveryKey), false);
    assert.equal(p.api.pending(), false);
});

test('a second writer racing an explicit overwrite still produces a conflict instead of an unchecked overwrite', async () => {
    const p = boot({collaborative:true,fetcher:async(payload,count)=> {
        if (!payload) return {ok:true,json:async()=>({...p.database,revision:2,record_versions:{}})};
        return {ok:false,status:409,json:async()=>({message:count===1?'First conflict':'Changed again; review latest',record_conflict:true})};
    }});
    const state=empty();state.sales=[{id:'a'}];p.api.saveState(state);
    await assert.rejects(p.api.flush(), /First conflict/);
    await p.dialog().children[3].children[1].click();
    assert.equal(p.dialog().children[2].textContent, 'Changed again; review latest');
    assert.equal(p.api.pending(), true);
    assert.equal(p.reloads(), 0);
});
