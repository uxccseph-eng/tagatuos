const test = require('node:test'), assert = require('node:assert/strict');
const data = require('../../public/js/masters-import-data.js');

test('friendly headers map conservatively and ambiguous names remain unmapped', () => {
    assert.deepEqual(data.guessMapping(['Supplier Name', 'Tax Identification Number', 'Petty Cash'], ['name','tin','is_petty_cash','code'], 'parties'), {name:0,tin:1,is_petty_cash:2,code:null});
    assert.equal(data.guessMapping(['Name','Supplier Name'], ['name'], 'parties').name, null);
    assert.deepEqual(data.guessMapping(['Description'], ['name','description'], 'atc-codes'), {name:0,description:null});
});
test('header offsets and blank rows preserve source numbers and text identifiers', () => {
    const source = data.source([['Report title'], ['Bank Account Number','Name'], ['001234','Savings'], [], ['002345','Checking']], 2);
    const rows = data.mapRows(source, {account_number:0,name:1,bank:''}, ['account_number','name','bank']);
    assert.equal(rows[0].sourceRow, 3); assert.equal(rows[1].sourceRow, 5);
    assert.equal(rows[0].record.account_number, '001234'); assert.equal(rows[0].record.bank, '');
});
test('invalid mappings cannot silently reuse or guess source columns', () => {
    const source = data.source([['Name'],['Sample']]);
    for (const mapping of [{name:null}, {name:0,code:0}, {name:-1}, {name:'0'}, {name:1}]) {
        assert.throws(() => data.mapRows(source, mapping, Object.keys(mapping)));
    }
});
test('source limits reject malformed rows binary content and excess data', () => {
    for (const matrix of [[['Name']], [['Name'],['A','B']], [['Name'],['\0']], [['Name'],['a'.repeat(4001)]], [['Name'],...Array.from({length:501},()=>['A'])], [Array(101).fill('header'),['A']], [['Name'],null]]) {
        assert.throws(() => data.source(matrix));
    }
    assert.equal(data.source([['Name'],...Array.from({length:500},()=>['A'])]).rows.length, 500);
});
test('bulk filling only affects blanks in included rows', () => {
    const rows = [{included:true,record:{type:''}}, {included:true,record:{type:'Asset'}}, {included:false,record:{type:''}}];
    assert.equal(data.fillBlanks(rows,'type',' Expense '), 1);
    assert.deepEqual(rows.map(row=>row.record.type), ['Expense','Asset','']);
    assert.equal(data.included(rows).length, 2);
});
test('downloaded CSV protects formula cells and preserves commas quotes and line breaks', () => {
    const csv = data.csv([['=HYPERLINK("x")','+1','-1','@foo','Normal, value','Line\nTwo']]);
    assert.ok(csv.startsWith('\uFEFF"\'=HYPERLINK(""x"")"'));
    for (const value of ['"\'+1"','"\'-1"','"\'@foo"','"Normal, value"','"Line\nTwo"']) assert.ok(csv.includes(value));
});
