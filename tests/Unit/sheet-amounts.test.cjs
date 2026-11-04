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

test('pasted spaces are normalized without rounding amounts or accepting excess precision', () => {
    for (const value of ['212,245.45 ', ' 212,245.45', '\u00a0212,245.45\u00a0', '212245.45\r\n']) {
        assert.equal(amounts.format(value), '212,245.45');
        assert.equal(amounts.error(amounts.format(value), '0.01', '999999999.99'), '');
    }
    assert.equal(amounts.format('.5'), '0.5');
    assert.match(amounts.error('212,245.456'), /two decimal places/);
    assert.match(amounts.error('0.00', '0.01'), /minimum/);
    assert.match(amounts.error('1,000.00', null, '999.99'), /maximum/);
});

function browserAmounts(names = ['Purchases']) {
    const listeners = new Map();
    const fields = names.map(name => ({
        name, type:'text', inputMode:'decimal', value:'', min:'0.01', max:'999999999.99', message:'', selectionStart:0, selectionEnd:0,
        get validity() { return {customError:!!this.message}; },
        get validationMessage() { return this.message; },
        matches() { return true; }, hasAttribute(name) { return ['min','max'].includes(name); },
        setCustomValidity(message) { this.message=message; },
        setSelectionRange(start,end) { this.selectionStart=start; this.selectionEnd=end; },
    }));
    const form={querySelectorAll:()=>fields};fields.forEach(field=>field.form=form);
    const document={activeElement:null,documentElement:{},querySelectorAll:()=>fields,addEventListener(type,callback,capture=false){if(!listeners.has(type))listeners.set(type,[]);listeners.get(type).push({callback,capture});}};
    const context={document,window:{},MutationObserver:class {observe() {}}};vm.createContext(context);
    vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/sheet-amounts.js'),'utf8'),context);
    const fire=(type,target,extra={})=>{
        const event={target,isComposing:false,...extra};
        for(const capture of [true,false])for(const item of listeners.get(type)||[])if(Boolean(item.capture)===capture)item.callback(event);
    };
    return {fields,form,document,fire,api:context.window.SheetAmounts};
}

test('every sheet clears its numeric error on correction and before a post click', () => {
    const browser=browserAmounts(['Sales','Collections','Purchases','Payments','CWT','WTP','Payroll','Journal','PPE']);
    for(const field of browser.fields) {
        field.value='212245.456';browser.fire('input',field);assert.match(field.message,/two decimal/);
        field.value='212,245.45 ';browser.fire('change',field);assert.equal(field.value,'212,245.45');assert.equal(field.message,'');
        field.value='100.123';browser.fire('input',field);assert.notEqual(field.message,'');
        field.value='212245.45';const button={type:'submit',form:browser.form};browser.fire('click',{closest:()=>button});assert.equal(field.message,'');
    }
});

test('format refresh preserves business-specific errors and unfinished decimal typing', () => {
    const browser=browserAmounts(['First','Second']),field=browser.fields[1];
    field.value='1000.';browser.api.refresh();assert.equal(field.value,'1,000.');
    browser.fire('focusout',field);assert.equal(field.value,'1,000.00');
    field.setCustomValidity('The amount exceeds the unpaid invoice balance.');browser.api.refresh();assert.match(field.message,/unpaid invoice/);
    field.setCustomValidity('');browser.document.activeElement=field;field.value='.5';field.selectionStart=field.selectionEnd=2;browser.fire('input',field);assert.equal(field.value,'0.5');assert.equal(field.selectionStart,3);
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
