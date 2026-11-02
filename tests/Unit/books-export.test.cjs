const test = require('node:test');
const assert = require('node:assert/strict');
const {payload} = require('../../public/js/books-export.js');
const range = {from: '2026-10-01', to: '2026-10-31'};

test('cash disbursements follows reference order and preserves zero, blank VAT and withholding cents', () => {
    const keys = ['date', 'number', 'reference', 'party', 'tin', 'remarks', 'sourceBill', 'gross', 'ewt', 'net', 'vat', 'bank', 'offset', 'branch'];
    const report = {columns: keys.map(key => ({key, title: key, ...(['gross','ewt','net','vat'].includes(key) ? {format: 'money'} : {})})), rows: [{date: range.from, number: 'PV-1', gross: 3000, ewt: 0, net: 3000, sourceEwt: 500, sheet: 'payments'}]};
    const data = payload(report, 'cash-disbursements', range);
    assert.deepEqual(data.columns.slice(7, 11).map(column => column.key), ['gross', 'vat', 'ewt', 'net']);
    assert.equal(data.columns[1].title, 'DV/CV No.');
    assert.equal(data.rows[0].gross, 3000); assert.equal(data.rows[0].ewt, 0);
    assert.equal(data.rows[0].vat, undefined); assert.equal(data.rows[0].sourceEwt, undefined);
    assert.deepEqual([data.from,data.to], [range.from,range.to]);
});

test('exports retain ledger groups, forwarded and closing balances and optional tax columns', () => {
    const report = {columns: [{key:'party',title:'Party / Subsidiary'}, {key:'debit',title:'Debit (PHP)',format:'money'}, {key:'balance',title:'Balance (PHP)',format:'money'}, {key:'nonVat',title:'Non-VAT Purchases',format:'money'}, {key:'wvat',title:'Withholding VAT',format:'money'}], rows:[{kind:'opening',group:'Cash | Customer',groupAccount:'Cash',groupParty:'Customer',balance:-12345,debit:0}]};
    const data = payload(report, 'subsidiary-ledger', range);
    assert.equal(data.columns[0].title,'Subsidiary');
    assert.equal(data.columns[1].title,'Debit');
    assert.deepEqual(data.rows,report.rows);
    assert.equal(data.columns.length,5);
});
