const test = require('node:test');
const assert = require('node:assert/strict');
const automation = require('../../public/js/payroll-automation.js');
const register = require('../../public/js/payroll-register.js');
const employee = () => ({name: 'Employee One', payType: 'Taxable', basic: '30000', taxableAllowance: '0'});
const draft = (attributes = {}) => ({id: 'one', status: 'draft', automation: true, frequency: 'monthly', contributionShare: '100', date: '2026-10-30', periodStart: '2026-10-01', periodEnd: '2026-10-31', employees: [employee()], ...attributes});

test('SSS uses salary-credit brackets, minimum, ceiling, MPF and employer EC', () => {
    const contribution = compensation => automation.monthlyContributions(1000000, register.cents(compensation), 1000000);
    assert.equal(contribution('1000').sssEe, 25000);
    assert.equal(contribution('5249.99').sssEe, 25000);
    assert.equal(contribution('5250').sssEe, 27500);
    assert.equal(contribution('14749.99').sssEr, 146000);
    assert.equal(contribution('14750').sssEr, 153000);
    assert.equal(contribution('34749.99').sssEe, 172500);
    assert.equal(contribution('34750').sssEe, 175000);
    assert.equal(contribution('100000').sssEr, 353000);
});
test('PhilHealth uses monthly BASIC with floor and ceiling; Pag-IBIG uses fund salary', () => {
    assert.equal(automation.monthlyContributions(500000, 500000, 150000).phicEe, 25000);
    assert.equal(automation.monthlyContributions(15000000, 15000000, 150000).phicEe, 250000);
    assert.equal(automation.monthlyContributions(3000000, 3000000, 150000).hdmfEe, 1500);
    assert.equal(automation.monthlyContributions(3000000, 3000000, 150001).hdmfEe, 3000);
    assert.equal(automation.monthlyContributions(3000000, 3000000, 3000000).hdmfEe, 20000);
    assert.equal(automation.monthlyContributions(3000000, 3000000, 3000000).hdmfEr, 20000);
});
for (const [frequency, threshold, upper, base, maximum, topBase] of [
    ['monthly', 20833, 33333, 1875, 666667, 183541.80],
    ['semi_monthly', 10417, 16667, 937.50, 333333, 91770.70],
    ['weekly', 4808, 7692, 432.60, 153846, 42355.65],
]) {
    test(`${frequency} uses its own BIR table including threshold and top bracket`, () => {
        assert.equal(automation.withholding(threshold * 100, frequency), 0);
        assert.equal(automation.withholding((threshold + 100) * 100, frequency), 1500);
        assert.equal(automation.withholding(upper * 100, frequency), Math.round(base * 100));
        assert.equal(automation.withholding((maximum + 100) * 100, frequency), Math.round((topBase + 35) * 100));
    });
}
test('monthly payroll calculates tax after EE contributions and excludes exempt earnings', () => {
    const run = automation.calculate(draft());
    const paid = run.employees[0];
    assert.equal(paid.sssEe, '1500.00');
    assert.equal(paid.sssEr, '3030.00');
    assert.equal(paid.phicEe, '750.00');
    assert.equal(paid.hdmfEe, '200.00');
    assert.equal(paid.withholding, '1007.55');
    assert.equal(register.totals(paid).net, 2654245);
    const exempt = automation.calculate(draft({employees: [{...employee(), deMinimis: '3000', benefits: '10000', taxableBenefits: '0'}]}));
    assert.equal(exempt.employees[0].withholding, paid.withholding);
});
test('non-monthly runs require explicit monthly bases instead of multiplying earnings', () => {
    assert.throws(() => automation.calculate(draft({frequency: 'weekly'})), /monthly/);
    const run = automation.calculate(draft({frequency: 'weekly', contributionShare: '25', employees: [{...employee(), basic: '7500', monthlyBasic: '30000', monthlyCompensation: '30000', monthlyFundSalary: '30000'}]}));
    assert.equal(run.employees[0].sssEe, '375.00');
    assert.equal(run.employees[0].phicEe, '187.50');
    assert.equal(run.employees[0].withholding, '311.93');
});
test('20 percent weekly shares, remaining balance and posted monthly caps prevent duplicate contributions', () => {
    const run = draft({frequency: 'weekly', contributionShare: '20', employees: [{...employee(), basic: '6000', monthlyBasic: '30000', monthlyCompensation: '30000', monthlyFundSalary: '30000'}]});
    const records = [];
    for (let index = 0; index < 5; index++) {
        const calculated = automation.calculate({...run, id: `week-${index}`}, records);
        assert.equal(calculated.employees[0].sssEe, '300.00');
        records.push({...calculated, status: 'posted'});
    }
    assert.equal(automation.calculate(run, records).employees[0].sssEe, '0.00');
    const remaining = automation.calculate({...run, contributionShare: 'remaining'}, records.slice(0, 4));
    assert.equal(remaining.employees[0].sssEe, '300.00');
    assert.equal(remaining.employees[0].hdmfEe, '40.00');
    assert.equal(automation.calculate(run, records.map(item => ({...item, status: 'void'}))).employees[0].sssEe, '300.00');
    assert.equal(automation.calculate(run, records.map(item => ({...item, date: '2026-09-30'}))).employees[0].sssEe, '300.00');
});
test('semi-monthly final balance settles rounding to the exact monthly total', () => {
    const first = automation.calculate(draft({frequency: 'semi_monthly', contributionShare: '50', employees: [{...employee(), basic: '15000', monthlyBasic: '30000.20', monthlyCompensation: '30000', monthlyFundSalary: '30000'}]}));
    const second = automation.calculate({...first, id: 'two', contributionShare: 'remaining'}, [{...first, status: 'posted'}]);
    assert.equal(register.cents(first.employees[0].phicEe) + register.cents(second.employees[0].phicEe), 75001);
});
test('benefits require a reviewed taxable portion, invalid data cannot be posted', () => {
    assert.throws(() => automation.calculate(draft({employees: [{...employee(), benefits: '1000'}]})), /taxable portion/);
    assert.throws(() => automation.calculate(draft({employees: [{...employee(), benefits: '1000', taxableBenefits: '1001'}]})), /cannot exceed/);
    assert.throws(() => automation.calculate(draft({date: '2024-12-31'})), /January 2025/);
    assert.throws(() => automation.calculate(draft({contributionShare: 'bad'})), /share/);
    assert.throws(() => register.post([draft({automationError: 'Review bases'})], ['one']), /Review bases/);
});
test('MWE basic is exempt while additional taxable earnings are taxed; non-taxable is exempt', () => {
    const run = automation.calculate(draft({employees: [{...employee(), payType: 'Minimum Wage Earner', taxableAllowance: '40000'}]}));
    assert.equal(run.employees[0].withholding, '2668.40');
    assert.equal(automation.calculate(draft({employees: [{...employee(), payType: 'Non-taxable'}]})).employees[0].withholding, '0.00');
});
test('posted and manual payroll amounts are immutable and calculation does not mutate inputs', () => {
    const run = draft(), before = structuredClone(run);
    automation.calculate(run);
    assert.deepEqual(run, before);
    for (const frozen of [draft({status: 'posted'}), draft({status: 'void'}), draft({automation: false})]) assert.equal(automation.calculate(frozen), frozen);
});
