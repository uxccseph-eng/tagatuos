(function (root) {
    'use strict';
    const register = typeof module !== 'undefined' && module.exports ? require('./payroll-register.js') : root.PayrollRegister;
    const fields = ['sssEe', 'sssEr', 'phicEe', 'phicEr', 'hdmfEe', 'hdmfEr'];
    const tables = {
        monthly: [[20833, 0, 15], [33333, 1875, 20], [66667, 8541.80, 25], [166667, 33541.80, 30], [666667, 183541.80, 35]],
        semi_monthly: [[10417, 0, 15], [16667, 937.50, 20], [33333, 4270.70, 25], [83333, 16770.70, 30], [333333, 91770.70, 35]],
        weekly: [[4808, 0, 15], [7692, 432.60, 20], [15385, 1971.20, 25], [38462, 7740.45, 30], [153846, 42355.65, 35]],
    };
    const amount = value => register.cents(value);
    const formatted = value => (value / 100).toFixed(2);
    const nameKey = name => String(name || '').trim().replace(/\s+/g, ' ').toUpperCase();
    function withholding(taxableCents, frequency) {
        if (!tables[frequency]) throw new Error('Select Monthly, Semi-monthly or Weekly.');
        const bracket = [...tables[frequency]].reverse().find(([floor]) => taxableCents > floor * 100);
        return bracket ? Math.round(bracket[1] * 100) + Math.round((taxableCents - bracket[0] * 100) * bracket[2] / 100) : 0;
    }
    function monthlyContributions(basic, compensation, fundSalary) {
        if (basic <= 0 || compensation <= 0 || fundSalary <= 0) throw new Error('Enter positive monthly Basic, SSS compensation and Pag-IBIG fund salary bases.');
        const salaryCredit = Math.max(500000, Math.min(3500000, Math.floor((compensation + 25000) / 50000) * 50000));
        const phic = Math.round(Math.max(1000000, Math.min(10000000, basic)) * 25 / 1000);
        const fund = Math.min(1000000, fundSalary);
        return {
            sssEe: Math.round(salaryCredit * 5 / 100),
            sssEr: Math.round(salaryCredit * 10 / 100) + (salaryCredit >= 1500000 ? 3000 : 1000),
            phicEe: phic, phicEr: phic,
            hdmfEe: Math.round(fund * (fundSalary <= 150000 ? 1 : 2) / 100),
            hdmfEr: Math.round(fund * 2 / 100),
        };
    }
    function calculate(run, records = []) {
        if (run.status !== 'draft' || !run.automation) return run;
        if (!register.validDate(run.date) || run.date < '2025-01-01') throw new Error('Automatic contributions support pay dates from January 2025. Use manual mode for earlier payroll.');
        if (!tables[run.frequency]) throw new Error('Select a payroll frequency.');
        const share = run.contributionShare;
        if (!['100', '50', '25', '20', '0', 'remaining'].includes(String(share))) throw new Error('Select a monthly contribution share.');
        const employees = run.employees.map(employee => {
            const basic = amount(employee.basic), allowance = amount(employee.taxableAllowance);
            let taxableBenefits = 0;
            if (amount(employee.benefits) > 0) {
                if (String(employee.taxableBenefits ?? '').trim() === '') throw new Error('Enter the taxable portion of 13th / other benefits, including 0.00 when fully exempt.');
                taxableBenefits = amount(employee.taxableBenefits);
                if (taxableBenefits > amount(employee.benefits)) throw new Error('Taxable benefits cannot exceed total benefits.');
            }
            const monthly = run.frequency === 'monthly';
            const contribution = monthlyContributions(
                employee.monthlyBasic === '' || employee.monthlyBasic == null ? (monthly ? basic : 0) : amount(employee.monthlyBasic),
                employee.monthlyCompensation === '' || employee.monthlyCompensation == null ? (monthly ? basic + allowance : 0) : amount(employee.monthlyCompensation),
                employee.monthlyFundSalary === '' || employee.monthlyFundSalary == null ? (monthly ? basic + allowance : 0) : amount(employee.monthlyFundSalary),
            );
            const used = Object.fromEntries(fields.map(field => [field, 0]));
            for (const previous of records) {
                if (previous.id === run.id || previous.status !== 'posted' || previous.date.slice(0, 7) !== run.date.slice(0, 7)) continue;
                for (const paid of previous.employees.filter(item => nameKey(item.name) === nameKey(employee.name))) {
                    for (const field of fields) used[field] += amount(paid[field]);
                }
            }
            const calculated = {...employee};
            for (const field of fields) {
                const remaining = Math.max(0, contribution[field] - used[field]);
                calculated[field] = formatted(share === 'remaining' ? remaining : Math.min(remaining, Math.round(contribution[field] * Number(share) / 100)));
            }
            const deductible = ['sssEe', 'phicEe', 'hdmfEe'].reduce((sum, field) => sum + amount(calculated[field]), 0);
            const taxable = employee.payType === 'Non-taxable' ? 0 : Math.max(0, (employee.payType === 'Minimum Wage Earner' ? 0 : basic) + allowance + taxableBenefits - deductible);
            calculated.withholding = formatted(withholding(taxable, run.frequency));
            return calculated;
        });
        return {...run, employees, automationError: '', rateVersion: 'PH-2025-SSS-PHIC-HDMF-BIR2023'};
    }
    const api = {withholding, monthlyContributions, calculate, fields};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.PayrollAutomation = api;
})(globalThis);
