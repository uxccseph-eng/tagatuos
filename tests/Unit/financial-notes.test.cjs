const test = require('node:test');
const assert = require('node:assert/strict');
const notes = require('../../public/js/financial-notes-data.js');
const company = {company_name: 'Test & Partners <Demo>', city: 'Manila'};
const settings = {framework: 'PFRS for SMEs', operations: 'Sample services', sec: 'Not answered', regulated: 'Not answered', materiality: '', policies: '', events: '', tax: true};
const report = (year, rows = []) => ({startDate: year + '-01-01', endDate: year + '-12-31', entries: 2, rows, warnings: [], variance: 0, postedVariance: 0});
const row = (code, label, balance) => ({kind: 'account', code, label, values: [Math.max(0, balance), Math.max(0, -balance), balance]});

test('notes use actual current and comparative cents and retain prior-only accounts', () => {
    const current = report(2026, [row('1010', 'Cash', 123456), row('2210', 'Output VAT', -23456)]);
    const previous = report(2025, [row('1010', 'Cash', 10000), row('2010', 'Old payable', -10000)]);
    const result = notes.build(company, settings, current, previous, 12);
    assert.deepEqual(result.balances.map(item => [item.code, item.current, item.previous]), [['1010',123456,10000],['2010',0,-10000],['2210',-23456,0]]);
    const text = JSON.stringify(result.sections);
    assert.match(text, /1,234.56/); assert.match(text, /\(234.56\)/);
    assert.match(text, /Confirmed book version: 12/);
    assert.match(text, /not a taxes-paid schedule/);
    assert.match(text, /Materiality has not been assessed/);
});

test('empty books and missing disclosures are explicit rather than invented', () => {
    const result = notes.build(company, {...settings, tax:false}, report(2026), report(2025), null);
    const text = JSON.stringify(result.sections);
    assert.match(text, /No posted account balances/);
    assert.match(text, /Do not interpret an empty disclosure/);
    assert.match(text, /not assert compliance/);
    assert.equal(result.sections.some(section => section.title === 'Tax-related book balances'), false);
});

test('invalid settings are rejected and ledger warnings remain in the draft', () => {
    for (const override of [{framework:'Unknown'}, {operations:''}, {materiality:'-1'}, {materiality:'1.001'}, {materiality:'99999999999999999999'}]) {
        assert.throws(() => notes.build(company, {...settings,...override}, report(2026), report(2025)), /Choose|Enter/);
    }
    const result = notes.build(company, settings, {...report(2026), variance:1, warnings:['Unmapped account']}, report(2025), 1);
    assert.match(result.warnings.join(' '), /Unmapped account/);
    assert.match(result.warnings.join(' '), /does not balance/);
});

function unzip(bytes) {
    const data = Buffer.from(bytes), files = {}; let offset = 0;
    while (data.readUInt32LE(offset) === 0x04034b50) {
        const size = data.readUInt32LE(offset + 18), length = data.readUInt16LE(offset + 26), extra = data.readUInt16LE(offset + 28);
        const name = data.subarray(offset + 30, offset + 30 + length).toString();
        const start = offset + 30 + length + extra;
        files[name] = data.subarray(start, start + size).toString(); offset = start + size;
    }
    assert.equal(data.readUInt32LE(offset), 0x02014b50);
    return files;
}
test('Word export is an OOXML package with escaped disclosure text and a demo warning', () => {
    const result = notes.build(company, {...settings, events:'<script> & sample'}, report(2026), report(2025), 1);
    const files = unzip(notes.docx(result));
    assert.match(files['[Content_Types].xml'], /wordprocessingml.document.main/);
    assert.match(files['_rels/.rels'], /Target="word\/document.xml"/);
    assert.match(files['word/document.xml'], /Test &amp; Partners &lt;Demo&gt;/);
    assert.match(files['word/document.xml'], /&lt;script&gt; &amp; sample/);
    assert.match(files['word/document.xml'], /DEMO DRAFT/);
    assert.match(files['word/document.xml'], /w:pgSz/);
    assert.equal(notes.rows(result).every(row => row.cells.length === 2), true);
});
