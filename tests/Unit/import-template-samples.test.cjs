const test = require('node:test'), assert = require('node:assert/strict');
const sheets = require('../../public/js/sheet-import.js');
const masters = require('../../public/js/masters-import-data.js');
const payroll = require('../../public/js/payroll-import.js');
const register = require('../../public/js/payroll-register.js');

test('every sheet sample aligns with supported headers while blank templates stay empty', () => {
    for (const sheet of Object.keys(sheets.columns)) {
        const rows = sheets.parseCsv(sheets.template(sheet, true));
        assert.deepEqual(rows[0], sheets.columns[sheet]);
        assert.ok(rows.length > 1);
        for (const row of rows.slice(1)) assert.equal(row.length, rows[0].length);
        assert.ok(rows.slice(1).flat().some(value => /REPLACE|SAMPLE/.test(value)));
        assert.equal(sheets.parseCsv(sheets.template(sheet)).length, 1);
    }
});
test('journal example groups two balanced lines with matching voucher details', () => {
    const rows = sheets.sampleRows('journal');
    assert.equal(rows.length, 2);
    for (const field of ['Voucher Key','Date','Reference','Particulars','Entry Type','Notes']) assert.equal(rows[0][field], rows[1][field]);
    assert.equal(rows.reduce((sum,row)=>sum+Number(row.Debit)-Number(row.Credit),0),0);
});
test('all Masters samples round-trip through mapping with optional blanks preserved', () => {
    for (const registry of ['chart-of-accounts','parties','employees','bank-accounts','tax-codes','atc-codes']) {
        const samples = masters.sampleRows(registry), columns = [...new Set(samples.flatMap(row=>Object.keys(row)))];
        const matrix = sheets.parseCsv(masters.template(registry, columns, true));
        assert.deepEqual(matrix[0], columns);
        const rows = masters.mapRows(masters.source(matrix), Object.fromEntries(columns.map((column,index)=>[column,index])), columns);
        assert.equal(rows.length, samples.length);
        for (const [index,row] of rows.entries()) for (const column of columns) assert.equal(row.record[column], samples[index][column] ?? '');
        assert.equal(sheets.parseCsv(masters.template(registry,columns)).length,1);
    }
    assert.equal(masters.sampleRows('bank-accounts')[0].account_number,'001234567890');
    const parties = masters.sampleRows('parties');
    assert.equal(parties[0].street,'');
    const individual = parties.find(row=>row.entity_type==='individual'), pettyCash = parties.find(row=>row.is_petty_cash==='Yes');
    assert.equal(individual.first_name,'JUAN'); assert.equal(individual.last_name,'DELA CRUZ');
    assert.equal(pettyCash.tin,''); assert.equal(pettyCash.classification,'Supplier');
});
test('payroll example includes every required actual amount and maps without column errors', () => {
    const matrix = sheets.parseCsv(payroll.template(true));
    assert.deepEqual(matrix[0], payroll.headers);
    const rows = payroll.rowsFromMatrix(matrix);
    assert.equal(rows.length,1); assert.equal(rows[0].columnError,false);
    for (const field of register.amountFields) assert.match(rows[0].values[field], /^\d+\.\d{2}$/);
    assert.equal(rows[0].values.pay_type,'Taxable');
    assert.equal(rows[0].values.review_basis,'regular');
    assert.equal(sheets.parseCsv(payroll.template()).length,1);
});
