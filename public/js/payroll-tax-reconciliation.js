(function (root) {
    'use strict';
    const payroll = typeof module !== 'undefined' && module.exports ? require('./payroll-register.js') : root.PayrollRegister;
    const automation = typeof module !== 'undefined' && module.exports ? require('./payroll-automation.js') : root.PayrollAutomation;
    const annual = typeof module !== 'undefined' && module.exports ? require('./alphalist-data.js') : root.AlphalistData;
    const normalize = value => String(value ?? '').trim().replace(/\s+/g, ' ').toUpperCase();

    function build(runs, employees, {year, month = null, mode = 'monthly'}) {
        if (!Number.isInteger(year) || year < 2023 || !['monthly', 'annual'].includes(mode)) throw Error('Choose a supported year and comparison.');
        const groups = new Map(), seen = new Set(), issues = [];
        for (const run of runs) {
            if (run.status !== 'posted') continue;
            if (!payroll.validDate(run.date)) { issues.push('A posted payroll has an invalid pay date.'); continue; }
            if (Number(run.date.slice(0, 4)) !== year || (mode === 'monthly' && month && run.date.slice(0, 7) !== month)) continue;
            if (seen.has(run.id)) { issues.push('Duplicate payroll '+run.id+' counted once.'); continue; }
            seen.add(run.id);
            for (const [index, employee] of run.employees.entries()) {
                const matches = employees.filter(master => employee.employeeId ? String(master.id) === String(employee.employeeId) : normalize(master.name) === normalize(employee.name));
                const master = matches.length === 1 ? matches[0] : null;
                const period = mode === 'annual' ? String(year) : run.date.slice(0, 7);
                const key = period+':'+(master ? master.id : 'unmatched:'+run.id+':'+index);
                if (!groups.has(key)) groups.set(key, {period, employeeId: master?.id ?? null, name: master?.name || employee.name, actual: 0, taxable: 0, exemptBenefits: 0, months: new Set(), notes: new Set(), invalid: false, classification: new Set(), sources: 0});
                const row = groups.get(key);
                row.months.add(run.date.slice(0, 7)); row.sources++; row.classification.add(employee.payType);
                if (!master) { row.invalid = true; row.notes.add('Employee identity must match one Masters record.'); }
                try {
                    const c = field => payroll.cents(employee[field]);
                    if (row.actual !== null) row.actual += c('withholding');
                    const benefits = c('benefits');
                    if (benefits && String(employee.taxableBenefits ?? '').trim() === '') throw Error('Taxable benefits are not allocated.');
                    const taxableBenefits = payroll.cents(employee.taxableBenefits);
                    if (taxableBenefits > benefits) throw Error('Taxable benefits exceed total benefits.');
                    const deductible = ['sssEe', 'phicEe', 'hdmfEe', 'gsisEe', 'unionDues'].reduce((sum, field) => sum+c(field), 0);
                    if (!['Taxable', 'Non-taxable', 'Minimum Wage Earner'].includes(employee.payType)) throw Error('Tax classification is missing.');
                    const mwe = employee.payType === 'Minimum Wage Earner';
                    const taxable = (mwe ? 0 : c('basic'))+c('taxableAllowance')+payroll.taxableExtras(employee, mwe)+taxableBenefits-deductible;
                    row.taxable += Math.max(0, taxable);
                    row.exemptBenefits += benefits-taxableBenefits;
                    if (employee.payType === 'Non-taxable') row.notes.add('Non-taxable classification: regular-employee monthly benchmark only; verify the exemption basis.');
                    if (mwe && c('taxableAllowance')+payroll.taxableExtras(employee, true)+taxableBenefits > 0) { row.invalid = true; row.notes.add('MWE with additional taxable earnings: eligibility needs a separate calculation.'); }
                    if (Object.values(employee.contributionSources || {}).includes('calculated')) row.notes.add('Includes calculated government contributions.');
                    if (run.frequency && run.frequency !== 'monthly' && mode === 'monthly') row.notes.add('Monthly aggregate benchmark; actual payroll-period timing may differ.');
                } catch (error) { row.invalid = true; row.actual = null; row.notes.add(error.message); }
            }
        }
        const rows = [...groups.values()].map(row => {
            if (mode === 'annual') {
                const benefitExcess = Math.max(0, row.exemptBenefits-9000000);
                row.taxable += benefitExcess;
                if (benefitExcess && row.classification.has('Minimum Wage Earner')) { row.invalid = true; row.notes.add('MWE benefits exceed the annual exemption cap; eligibility needs a separate calculation.'); }
                row.notes.add(row.months.size+' payroll month(s) recorded. Confirm complete year / final pay and prior-employer income and withholding before issuing a refund.');
                if (row.classification.has('Non-taxable') || row.classification.size > 1) { row.invalid = true; row.notes.add('Annual amount unavailable until the tax classification is resolved.'); }
            }
            const benchmark = row.invalid ? null : mode === 'annual' ? annual.annualTax(row.taxable, year) : automation.withholding(row.taxable, 'monthly');
            const difference = benchmark === null ? null : row.actual-benchmark;
            return {...row, months: row.months.size, notes: [...row.notes], classification: [...row.classification].join(' / '), benchmark, difference, over: difference === null ? null : Math.max(0, difference), short: difference === null ? null : Math.max(0, -difference)};
        }).sort((a, b) => a.period.localeCompare(b.period) || String(a.name).localeCompare(String(b.name)));
        return {rows, issues, mode};
    }

    function csv(result) {
        const number = amount => amount === null ? '' : (amount/100).toFixed(2);
        const quote = value => '"'+String(value ?? '').replace(/^[=+@\-\t\r]/, "'$&").replace(/"/g, '""')+'"';
        const header = ['Period', 'Employee', 'Classification', 'Actual tax deducted', result.mode === 'annual' ? 'Annual tax preview on recorded income' : 'Monthly table benchmark', result.mode === 'annual' ? 'Potential excess withholding - not final refund' : 'Above benchmark', 'Below benchmark', 'Payroll months recorded', 'Notes'];
        return '\uFEFF'+[header, ...result.rows.map(row => [row.period, row.name, row.classification, number(row.actual), number(row.benchmark), number(row.over), number(row.short), row.months, row.notes.join(' ')])].map(row => row.map(quote).join(',')).join('\r\n')+'\r\n';
    }
    const api = {build, csv};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.PayrollTaxReconciliation = api;
})(typeof window === 'undefined' ? null : window);
