const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/wtp.js'), 'utf8');

function inputFor(record, resolvedCode = '', key = 'ewt') {
    const options = [{value: '0'}, {value: '2', dataset: {masterCode: 'WC160'}}, {value: '5', dataset: {masterCode: 'WC100'}}, {value: '5', dataset: {masterCode: 'WI100'}}];
    const field = {tagName: 'SELECT', options, dataset: {}, events: {}, removeAttribute() {}, setAttribute() {}, addEventListener(name, callback) { this.events[name] = callback; }};
    const messages = [], selections = [];
    const context = vm.createContext({record, key, fields: {[key]: {cloneNode: () => field}}, ledger: {wtpAtc: () => resolvedCode, wtpVatAtc: () => resolvedCode}, window: {SheetMasters: {selectValue: (select, value, code) => { selections.push({value, code}); select.value = String(value); }}}, announce: message => messages.push(message), store() {}, records: [record], refresh() {}});
    vm.runInContext(source.slice(source.indexOf('    const atcCode='), source.indexOf('    const ewtLabel=')) + source.slice(source.indexOf('    function input(record,key)'), source.indexOf('    function post(candidates)')) + '\ninput(record,key);', context);
    return {field, selections, messages};
}

test('WTP draft selector displays ATC resolved from the source or Masters without changing the certificate', () => {
    const record = {number: '2307I-1', billId: 2, ewt: 5};
    const {field, selections} = inputFor(record, 'WC100');
    assert.equal(selections[0].code, 'WC100');
    assert.equal(field.disabled, true);
    assert.equal(record.ewtCode, undefined);
});

test('linked certificates keep ATC selection in Purchases even when the source code is missing', () => {
    const {field} = inputFor({number: '2307I-1', billId: 2, ewt: 5});
    assert.equal(field.disabled, true);
});

test('a source-linked certificate rejects changing the saved withholding rate', () => {
    const record = {number: '2307I-1', billId: 2, ewt: 5};
    const {field, messages} = inputFor(record);
    field.value = '2';
    field.events.input();
    assert.equal(record.ewt, 5);
    assert.equal(record.ewtCode, undefined);
    assert.match(messages[0], /must match the linked purchase/);
});

test('VAT ATC uses the same source-resolution and correction rules', () => {
    const {field, selections} = inputFor({number: '2307I-1', billId: 2, wvat: 5}, 'WV010', 'wvat');
    assert.equal(selections[0].code, 'WV010');
    assert.equal(field.disabled, true);
    assert.equal(inputFor({number: '2307I-1', billId: 2, wvat: 0}, '', 'wvat').field.disabled, true);
});

test('standalone certificates keep the full Masters ATC choice editable', () => {
    const {field} = inputFor({number: '2307I-1', billId: null, ewt: 5});
    assert.equal(field.disabled, false);
});
