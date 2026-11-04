const test = require('node:test');
const assert = require('node:assert/strict');
const amounts = require('../../public/js/sheet-amounts.js');
const payroll = require('../../public/js/payroll-register.js');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('amount display groups thousands without rounding or losing partial decimals', () => {
    for (const [input, expected] of [['83464.80','83,464.80'], ['1234567.89','1,234,567.89'], ['1000.','1,000.'], ['1000.0','1,000.0'], ['', ''], ['0.00','0.00'], ['12.345','12.345'], ['invalid','invalid']]) {
        assert.equal(amounts.format(input), expected);
    }
});

test('formatting and caret positions preserve the underlying amount', () => {
    const value = '1234567.89', formatted = amounts.format(value);
    assert.equal(amounts.raw(formatted), value);
    for (let index = 0; index <= value.length; index++) {
        assert.equal(amounts.position(formatted, amounts.caret(formatted, index)), index);
    }
    assert.equal(amounts.format('1,2345.60'), '12,345.60');
});

test('payroll totals accept displayed commas and still calculate exact centavos', () => {
    assert.equal(payroll.cents('83,464.80'), 8346480);
    assert.deepEqual(payroll.totals({basic:'83,464.80',holidayPay:'1,000.25',unionDues:'100.05'}), {gross:8446505,net:8436500,cost:8446505});
    assert.throws(()=>payroll.cents('1,000.123'), /two decimal/);
});

for (const sheet of ['purchases', 'payments', 'wtp', 'journal']) {
    test(`${sheet} parses a grouped amount without losing centavos`, () => {
        const source = fs.readFileSync(path.join(__dirname, '../../public/js', sheet + '.js'), 'utf8');
        const parser = source.slice(source.indexOf('const cents='), source.indexOf('\n', source.indexOf('const cents=')));
        const cents = vm.runInNewContext(parser + ';cents;');
        assert.equal(cents('83,464.80'), 8346480);
        assert.equal(cents('1,234,567.89'), 123456789);
        assert.notEqual(cents('1,000.123'), 100012);
    });
}
