const test = require('node:test');
const assert = require('node:assert/strict');
const {metadata, csv, xlsx} = require('../../public/js/financial-report-template.js');
const company = {company_name: 'Example & Co', registered_name: 'Example Registered', trade_name: 'Example Trade', tin: '000-000-000', branch_code: '00000', vat_type: 'vat', full_name: 'Preview User', street: 'Sample Street', city: 'Sample City'};
const annual = {startDate: '2026-01-01', endDate: '2026-12-31'};
const time = new Date('2026-10-20T03:47:00Z');
const columns = ['Code', 'Account', 'Debit', 'Credit', 'Balance'];
const rows = [{kind: 'account', cells: ['0010', 'Cash & bank <test>', 200, 391.07, -191.07]}, {kind: 'grand', cells: ['Totals', '', 200, 391.07, -191.07]}];
function unzipStored(bytes) {
    const data = Buffer.from(bytes), files = {};
    let offset = 0;
    while (data.readUInt32LE(offset) === 0x04034b50) {
        assert.equal(data.readUInt16LE(offset + 8), 0);
        const size = data.readUInt32LE(offset + 18), nameLength = data.readUInt16LE(offset + 26), extraLength = data.readUInt16LE(offset + 28);
        const name = data.subarray(offset + 30, offset + 30 + nameLength).toString('utf8');
        const start = offset + 30 + nameLength + extraLength;
        files[name] = data.subarray(start, start + size).toString('utf8');
        offset = start + size;
    }
    assert.equal(data.readUInt32LE(offset), 0x02014b50);
    assert.equal(data.readUInt32LE(data.length - 22), 0x06054b50);
    assert.equal(data.readUInt32LE(data.length - 6), offset);
    return files;
}
test('report heading uses current company, registered identity, five digit branch and Manila generation time', () => {
    const meta = metadata(company, 'Trial Balance', annual, 'trial-balance', time);
    assert.equal(meta.companyName, 'Example Registered'); assert.equal(meta.trade, 'Trade name: Example Trade');
    assert.equal(meta.registration, 'VAT REG TIN 000-000-000-00000'); assert.equal(meta.address, 'Sample Street, Sample City');
    assert.equal(meta.period, 'For the year ended December 31, 2026'); assert.match(meta.generated, /Preview User.*11:47 AM/);
    assert.equal(meta.branch, 'Branch: 00000'); assert.equal(meta.lines.length, 10);
});
test('monthly, fiscal, arbitrary and balance sheet periods describe the selected dates accurately', () => {
    assert.equal(metadata(company, '', {startDate:'2026-10-01',endDate:'2026-10-31'}, 'trial-balance').period, 'For the month ended October 31, 2026');
    assert.equal(metadata(company, '', {startDate:'2025-07-01',endDate:'2026-06-30'}, 'income-statement').period, 'For the year ended June 30, 2026');
    assert.equal(metadata(company, '', {startDate:'2026-02-10',endDate:'2026-03-20'}, 'cash-flow').period, 'For the period ended March 20, 2026');
    assert.equal(metadata(company, '', annual, 'balance-sheet').period, 'As at December 31, 2026');
});
test('CSV matches template columns, uses decimal values and guards formula text without changing negative numbers', () => {
    const text = csv(columns, [...rows, {cells: ['=1+1', '@customer', 0, 0, -10.71]}]);
    assert.ok(text.startsWith('\uFEFF"Code","Account","Debit","Credit","Balance"'));
    assert.ok(text.includes('200.00,391.07,-191.07')); assert.ok(text.includes('"Totals","",200.00'));
    assert.ok(text.includes('"\'=1+1","\'@customer"')); assert.ok(text.includes('-10.71'));
});
test('Excel export is a real OOXML package with company header, typed account codes and decimal numeric cells', () => {
    const files = unzipStored(xlsx(metadata(company, 'Trial Balance', annual, 'trial-balance', time), columns, rows));
    assert.equal(Object.keys(files).length, 6);
    assert.ok(files['[Content_Types].xml'].includes('spreadsheetml.sheet.main+xml'));
    const sheet = files['xl/worksheets/sheet1.xml'];
    assert.ok(sheet.includes('Example Registered')); assert.ok(sheet.includes('Cash &amp; bank &lt;test&gt;'));
    assert.match(sheet, /r="A13"[^>]*t="inlineStr".*?<t[^>]*>0010<\/t>/);
    assert.match(sheet, /r="E13"[^>]*><v>-191\.07<\/v>/); assert.match(sheet, /r="C14"[^>]*><v>200\.00<\/v>/);
    assert.ok(sheet.includes('state="frozen"')); assert.ok(sheet.includes('orientation="landscape"'));
    assert.ok(files['xl/styles.xml'].includes('#,##0.00;(#,##0.00);0.00'));
    assert.ok(files['xl/workbook.xml'].includes('_xlnm.Print_Titles'));
});
test('comparison export supports its own heading count, string formula literals, Unicode and safe sheet names', () => {
    const files = unzipStored(xlsx(metadata({...company, registered_name: '=1+1'}, 'Cash / Flow & Equity', annual, 'cash-flow', time), ['Account', '2026', '2025'], [{kind: 'account', cells: ['Ñ Account', 100.1, -100.1]}]));
    assert.ok(files['xl/worksheets/sheet1.xml'].includes('=1+1')); assert.ok(!files['xl/worksheets/sheet1.xml'].includes('<f>'));
    assert.ok(files['xl/worksheets/sheet1.xml'].includes('Ñ Account')); assert.ok(files['xl/workbook.xml'].includes('Cash  Flow &amp; Equity'));
    assert.ok(files['xl/worksheets/sheet1.xml'].includes('ref="A1:C13"'));
});

test('quarterly metadata follows calendar and company fiscal quarters without changing balance sheet headings',()=>{
    assert.equal(metadata(company,'Trial Balance',{startDate:'2026-10-01',endDate:'2026-12-31'},'trial-balance',time).period,'For the quarter ended December 31, 2026');
    assert.equal(metadata({...company,fiscal_end_month:3},'Income Statement',{startDate:'2025-04-01',endDate:'2025-06-30'},'income-statement',time).period,'For the quarter ended June 30, 2025');
    assert.equal(metadata(company,'Balance Sheet',{startDate:'2026-10-01',endDate:'2026-12-31'},'balance-sheet',time).period,'As at December 31, 2026');
});
