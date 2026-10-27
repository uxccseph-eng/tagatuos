const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname, '../../public/js', name), 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
function fixture() {
    const account = {id: 1, code: '4000', name: 'Current Sales Name', type: 'Income'};
    const expense = {id: 2, code: '6100', name: 'Office Supplies', type: 'Expense'};
    const catalog = {parties: [{id: 10, name: 'Sample Customer', classification: 'Customer', tin: '000-000-000-00000'}, {id: 20, name: 'Sample Supplier', classification: 'Supplier', tin: '000-000-001-00000'}], taxCodes: [{code: 'VAT', name: 'VAT', kind: 'VAT', rate: 12}, {code: 'CUSTOM', name: 'Special', kind: 'VAT', rate: 8}], atcCodes: [{code: 'WC100', name: 'Income', rate: 1, category: 'Income-tax CWT'}, {code: 'WV010', name: 'VAT', rate: 5, category: 'VAT Withholding'}]};
    const sales = [{id: 's1', number: 'SI-001', partyId: 10, customer: 'Sample Customer', status: 'posted', date: '2026-10-01', postedAt: '2026-10-20T10:00:00Z', account: '4000 · Previous Sales Name', tax: '12% VAT', cwt: 'WC100 · 2%', vatCwt: 'WV010 · 3%', gross: 50000, reference: 'OLD-123', bank: '1010 · Cash', terms: 'On Account'}];
    const purchases = [{id: 'p1', number: 'BILL-001', partyId: 20, supplier: 'Sample Supplier', status: 'posted', date: '2026-10-02', postedAt: '2026-10-21T10:00:00Z', account: '6100 · Office Supplies', tax: 'TAX|CUSTOM|VAT|7.5', ewtCode: 'WC100', ewt: 2, wvatCode: 'WV010', wvat: 3}];
    const window = {WorkspaceAccounts: [account, expense], WorkspaceMasters: catalog, KontaduryaLedger: {sales: () => sales, purchases: () => purchases}};
    vm.runInNewContext(read('sheet-masters.js'), {window, document: {addEventListener() {}}});
    return {window, catalog, account, expense, sales, purchases, m: window.SheetMasters};
}

test('Sales suggestions resolve current active account names and rates without copying transaction details', () => {
    const f = fixture(), record = {status: 'draft', customer: 'Sample Customer', account: '', tax: '', cwt: '', vatCwt: ''};
    const before = JSON.stringify(f.sales), result = f.m.partyDefaults(record, true);
    assert.deepEqual(plain(result), {sourceNumber: 'SI-001', values: {account: '4000 · Current Sales Name', tax: '12% VAT', cwt: 'WC100 · 1%', vatCwt: 'WV010 · 5%'}});
    assert.equal(record.account, '');
    assert.equal(JSON.stringify(f.sales), before);
    for (const field of ['date', 'gross', 'reference', 'bank', 'terms', 'memo']) assert.equal(Object.hasOwn(result.values, field), false);
});

test('Purchases suggestions use numeric current withholding rates with their exact Masters codes', () => {
    const f = fixture(), result = f.m.partyDefaults({status: 'draft', supplier: 'Sample Supplier', ewt: '', wvat: ''});
    assert.deepEqual(plain(result.values), {account: '6100 · Office Supplies', tax: 'TAX|CUSTOM|VAT|8', ewt: 1, ewtCode: 'WC100', wvat: 5, wvatCode: 'WV010'});
    assert.equal(result.sourceNumber, 'BILL-001');
});

test('filled fields including an explicit None or zero withholding choice are preserved', () => {
    const f = fixture();
    assert.deepEqual(plain(f.m.partyDefaults({status: 'draft', customer: 'Sample Customer', account: 'manual', tax: 'manual', cwt: 'None', vatCwt: 'None'}, true).values), {});
    const result = f.m.partyDefaults({status: 'draft', supplier: 'Sample Supplier', account: 'manual', tax: 'manual', ewt: 0, wvat: 0});
    assert.deepEqual(plain(result.values), {});
    assert.equal(result.sourceNumber, '');
});

test('latest posting wins across periods, while drafts, voids and archived rows never become defaults', () => {
    const f = fixture();
    f.sales.push({...f.sales[0], id: 's2', number: 'SI-002', date: '2025-01-01', postedAt: '2026-10-22T10:00:00Z', tax: 'TAX|CUSTOM|VAT|7.5'});
    for (const status of ['draft', 'void', 'archived']) f.sales.push({...f.sales[0], id: status, status, number: status, postedAt: '2030-01-01T00:00:00Z'});
    const result = f.m.partyDefaults({customer: 'Sample Customer'}, true);
    assert.equal(result.sourceNumber, 'SI-002');
    assert.equal(result.values.tax, 'TAX|CUSTOM|VAT|8');
});

test('inactive or unsuitable Masters values are skipped without falling back to an older transaction', () => {
    const f = fixture();
    f.account.archived = true;
    f.catalog.taxCodes[0].archived = true;
    f.catalog.atcCodes.forEach(atc => atc.archived = true);
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
    f.account.archived = false;
    f.account.type = 'Liability';
    assert.equal(f.m.partyDefaults({customer: 'Sample Customer'}, true).values.account, undefined);
    f.sales.push({...f.sales[0], id: 's2', number: 'SI-002', postedAt: '2027-01-01', account: 'missing', tax: 'invalid'});
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
});

test('party identity blocks same-name rows from a different party, and legacy matches check recorded TIN', () => {
    const f = fixture();
    f.sales[0].partyId = 999;
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
    delete f.sales[0].partyId;
    f.sales[0].tin = '999-999-999-00000';
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
    f.sales[0].tin = '00000000000000';
    assert.equal(f.m.partyDefaults({customer: 'Sample Customer'}, true).sourceNumber, 'SI-001');
    f.catalog.parties.push({...f.catalog.parties[0], id: 30});
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
});

test('renamed parties retain ID-based history, while unknown, archived or wrong-kind parties get no suggestions', () => {
    const f = fixture();
    f.sales[0].customer = 'Previous Registered Name';
    assert.equal(f.m.partyDefaults({customer: 'Sample Customer'}, true).sourceNumber, 'SI-001');
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'missing'}, true).values), {});
    assert.deepEqual(plain(f.m.partyDefaults({supplier: 'Sample Customer'}).values), {});
    f.catalog.parties[0].archived = true;
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
});

test('suggestions use only the currently bound company ledger and never change posted or void targets', () => {
    const f = fixture();
    for (const status of ['posted', 'void', 'archived']) assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer', status}, true).values), {});
    f.window.KontaduryaLedger = {sales: () => [], purchases: () => []};
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
    assert.equal(fixture().m.partyDefaults({customer: 'Sample Customer'}, true).sourceNumber, 'SI-001');
});

test('legacy date fallback is deterministic and the target record is never reused as history', () => {
    const f = fixture();
    delete f.sales[0].postedAt;
    f.sales.push({...f.sales[0], id: 's2', number: 'SI-002', date: '2026-10-02'});
    assert.equal(f.m.partyDefaults({customer: 'Sample Customer'}, true).sourceNumber, 'SI-002');
    assert.equal(f.m.partyDefaults({id: 's2', customer: 'Sample Customer'}, true).sourceNumber, 'SI-001');
});

test('Sales inline and popup controls receive suggestions while custom selections stay intact', () => {
    const f = fixture(), names = ['Date','Customer','Gross amount','Tax type','Revenue account','Income withholding','VAT withholding'];
    const editorFields = names.map(name => ({value: name === 'Customer' ? 'Sample Customer' : '', syncCount: 0, dispatchEvent(event) {if(event.type === 'masters:sync') this.syncCount++;}}));
    const entryFields = Object.fromEntries(names.map(name => [name, {value: name === 'Customer' ? 'Sample Customer' : '', syncCount: 0, dispatchEvent(event) {if(event.type === 'masters:sync') this.syncCount++;}}]));
    const notice = {}, messages = [];
    f.m.selectValue = (field, value) => field.value = value;
    const source = read('sales.js'), helper = source.slice(source.indexOf('function suggestSalesFields('), source.indexOf('Object.values(entryFields).forEach(field => {'));
    const context = {Event: class {constructor(type) {this.type = type;}}, window: f.window, editorFields, entryFields, fieldNames: names, entryIsEditable: true, editingEntry: null, document: {getElementById: () => notice}, announce: message => messages.push(message), setEntryValue: (name, value) => entryFields[name].value = value};
    vm.createContext(context); vm.runInContext(helper, context);
    context.suggestSalesFields(false); context.suggestSalesFields(true);
    assert.equal(editorFields[4].value, '4000 · Current Sales Name');
    assert.equal(entryFields['Income withholding'].value, 'WC100 · 1%');
    assert.equal(entryFields['Income withholding'].syncCount, 1);
    assert.equal(editorFields[4].syncCount, 1);
    assert.equal(entryFields.Date.value, ''); assert.equal(entryFields['Gross amount'].value, '');
    assert.match(notice.textContent, /SI-001/);
    entryFields['Revenue account'].value = 'custom account'; context.suggestSalesFields(true);
    assert.equal(entryFields['Revenue account'].value, 'custom account');
    entryFields['Revenue account'].value = ''; context.editingEntry = {status: 'posted'}; context.suggestSalesFields(true);
    assert.equal(entryFields['Revenue account'].value, '');
});

test('Purchases row and popup suggestions synchronize the exact ATC option as well as the draft record', () => {
    const f = fixture(), fields = Object.fromEntries(['account','tax','ewt','wvat'].map(key => [key, {value: '', dispatchEvent() {}}]));
    f.m.selectValue = (field, value, code) => {field.value = value; field.selectedCode = code;};
    const source = read('purchases.js'), helper = source.slice(source.indexOf('    function suggestPurchaseSettings('), source.indexOf('    function post(candidates)'));
    const context = {window: f.window, Event: class {}, announce() {}}; vm.createContext(context); vm.runInContext(helper, context);
    const record = {supplier: 'Sample Supplier', status: 'draft', ewt: '', wvat: ''};
    const scope = {querySelector: selector => selector.startsWith('[data-purchase') ? fields[selector.match(/"(.*?)"/)[1]] : null};
    context.suggestPurchaseSettings(record, scope);
    assert.equal(record.ewtCode, 'WC100'); assert.equal(fields.ewt.value, 1); assert.equal(fields.ewt.selectedCode, 'WC100');
    const rowRecord = {number: 'BILL-NEW', supplier: 'Sample Supplier', status: 'draft', ewt: '', wvat: ''};
    context.suggestPurchaseSettings(rowRecord, {querySelector: selector => selector.startsWith('[aria-label=') ? fields[selector.match(/BILL-NEW ([^"]+)/)[1]] : null});
    assert.equal(fields.ewt.selectedCode, 'WC100');
    assert.equal(record.wvatCode, 'WV010'); assert.equal(fields.tax.value, 'TAX|CUSTOM|VAT|8');
    record.ewt = 0; record.ewtCode = ''; context.suggestPurchaseSettings(record, scope);
    assert.equal(record.ewt, 0); assert.equal(record.ewtCode, '');
});

 test('a restored Sales draft with a null withholding selection still calculates its preview', () => {
    const source = read('sales.js'), helper = source.slice(source.indexOf('function amounts(entry) {'), source.indexOf('const rowTemplate'));
    const context = {window: {KontaduryaLedger: {taxAmounts: gross => ({net: gross})}}}; vm.createContext(context); vm.runInContext(helper, context);
    assert.deepEqual(plain(context.amounts({gross: 10000, tax: null, cwt: null})), {net: 10000, vat: 0, cwt: 0});
});

test('real company suggestions use acknowledged database rows rather than optimistic unsaved browser posts', () => {
    const f = fixture();
    f.window.CompanyLedgerDatabase = {state: {sales: []}};
    assert.deepEqual(plain(f.m.partyDefaults({customer: 'Sample Customer'}, true).values), {});
    f.window.CompanyLedgerDatabase.state.sales.push({...f.sales[0], number: 'SI-SAVED'});
    assert.equal(f.m.partyDefaults({customer: 'Sample Customer'}, true).sourceNumber, 'SI-SAVED');
});

test('typing a matching name does not apply defaults until the Sales party selection is committed', () => {
    const f = fixture(), source = read('sales.js');
    const names = ['Date','Customer','Gross amount','Tax type','Revenue account','Income withholding','VAT withholding'];
    const fields = names.map(name => ({value: name === 'Customer' ? 'Sample Customer' : '', listeners: {}, addEventListener(type, callback) {this.listeners[type] = callback;}, setCustomValidity() {}, dispatchEvent() {}}));
    f.m.selectValue = (field, value) => field.value = value;
    let saves = 0;
    const context = {window: f.window, Event: class {}, editorFields: fields, fieldNames: names, entryFields: {}, entryIsEditable: true, editingEntry: null, announce() {}, autoSaveSalesForm() {saves++;}};
    vm.createContext(context);
    const helper = source.slice(source.indexOf('function suggestSalesFields('), source.indexOf('Object.values(entryFields).forEach(field => {'));
    vm.runInContext(helper, context);
    const start = source.indexOf('editorFields.forEach((field,index)=>{', source.indexOf('function saveDraft()'));
    vm.runInContext(source.slice(start, source.indexOf("draft.addEventListener('click'", start)), context);
    fields[1].listeners.input();
    assert.equal(fields[4].value, '');
    fields[1].listeners.change();
    assert.equal(fields[4].value, '4000 · Current Sales Name');
    assert.equal(saves, 2);
});
