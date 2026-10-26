(function (root) {
    const payroll = typeof module !== 'undefined' && module.exports ? require('./payroll-register.js') : root.PayrollRegister;
    const normalize = value => String(value ?? '').trim().replace(/\s+/g, ' ').toUpperCase();
    function annualTax(taxable, year) {
        if (year < 2023 || taxable === null) return null;
        const brackets = [[800000000, 220250000, 35], [200000000, 40250000, 30], [80000000, 10250000, 25], [40000000, 2250000, 20], [25000000, 0, 15]];
        for (const [threshold, base, rate] of brackets) {
            if (taxable > threshold) return base + Math.round((taxable - threshold) * rate / 100);
        }
        return 0;
    }
    function build(runs, employees, year) {
        const groups = new Map(), issues = [], seenRuns = new Set();
        let excluded = 0, sourceCount = 0;
        for (const run of runs) {
            if (!run || typeof run !== 'object') { issues.push('An invalid payroll record was excluded.'); continue; }
            if (run.status !== 'posted') continue;
            if (!payroll.validDate(run.date)) { issues.push((run.number || 'Payroll') + ': missing or invalid pay date; excluded from annual totals.'); continue; }
            if (Number(run.date.slice(0, 4)) !== year) continue;
            if (seenRuns.has(run.id)) { issues.push((run.number || 'Payroll') + ': duplicate payroll ID; counted once.'); continue; }
            seenRuns.add(run.id);
            if (!Array.isArray(run.employees)) { issues.push((run.number || 'Payroll') + ': employee rows are unavailable.'); continue; }
            for (const employee of run.employees) {
                if (!employee || typeof employee !== 'object') { issues.push((run.number || 'Payroll') + ': invalid employee row was excluded.'); continue; }
                sourceCount++;
                const candidates = employees.filter(master => employee.employeeId ? String(master.id) === String(employee.employeeId) : normalize(master.name) === normalize(employee.name));
                const master = candidates.length === 1 ? candidates[0] : null;
                const key = master ? 'id:' + master.id : 'name:' + normalize(employee.name);
                if (!groups.has(key)) groups.set(key, {employeeId: master?.id || null, name: master?.name || employee.name || 'Unnamed employee', code: master?.code || '', tin: master?.tin || '', lines: [], issues: [], gross: 0, exempt: 0, taxable: 0, withheld: 0, exemptBenefits: 0, mwe: 0, nonMwe: 0});
                const row = groups.get(key), reference = run.number || run.id || 'Payroll';
                if (employee.payType === 'Minimum Wage Earner') { row.mwe++; excluded++; continue; }
                if (!['Taxable', 'Non-taxable'].includes(employee.payType)) { row.issues.push(reference + ': pay type is missing; classification must be reviewed.'); continue; }
                row.nonMwe++;
                if (!master) row.issues.push(candidates.length > 1 ? 'Employee name matches multiple Masters records.' : 'Employee is not linked to an employee in Masters.');
                if (!/^(?:\d{9}|\d{12}|\d{13}|\d{14})$/.test(row.tin.replace(/\D/g, '')) || /^0+$/.test(row.tin.replace(/\D/g, ''))) row.issues.push('A valid employee TIN is needed in Masters.');
                let values;
                try { values = Object.fromEntries(payroll.amountFields.map(field => [field, payroll.cents(employee[field])])); }
                catch (error) { row.issues.push(reference + ': invalid monetary amount; totals are incomplete.'); row.incomplete = true; continue; }
                const gross = values.basic + values.taxableAllowance + values.deMinimis + values.benefits;
                const contributions = values.sssEe + values.phicEe + values.hdmfEe;
                let taxable = null, exempt = null;
                const benefitProvided = employee.taxableBenefits !== undefined && employee.taxableBenefits !== null && String(employee.taxableBenefits).trim() !== '';
                let taxableBenefits = 0;
                try { if (benefitProvided) taxableBenefits = payroll.cents(employee.taxableBenefits); }
                catch (error) { taxableBenefits = null; }
                if ((values.benefits > 0 && !benefitProvided) || taxableBenefits === null || taxableBenefits > values.benefits) {
                    row.issues.push(reference + ': review the taxable portion of benefits.');
                } else if (employee.payType === 'Non-taxable') {
                    exempt = gross; taxable = 0;
                    row.issues.push(reference + ': verify the basis for the non-taxable classification.');
                } else {
                    taxable = values.basic + values.taxableAllowance + taxableBenefits - contributions;
                    if (taxable < 0) { taxable = null; row.issues.push(reference + ': employee contributions exceed taxable earnings.'); }
                    else exempt = gross - taxable;
                    row.exemptBenefits += values.benefits - taxableBenefits;
                }
                row.gross += gross; row.withheld += values.withholding;
                row.exempt = row.exempt === null || exempt === null ? null : row.exempt + exempt;
                row.taxable = row.taxable === null || taxable === null ? null : row.taxable + taxable;
                row.lines.push({reference, date: run.date, gross, contributions, exempt, taxable, withheld: values.withholding, sourceId: String(run.id)+':'+run.employees.indexOf(employee), payType: employee.payType, basic: values.basic, allowance: values.taxableAllowance, deMinimis: values.deMinimis, benefits: values.benefits, taxableBenefits: values.benefits > 0 && !benefitProvided ? null : taxableBenefits});
            }
        }
        const rows = [];
        for (const row of groups.values()) {
            if (!row.nonMwe) { if (row.issues.length) issues.push(row.name + ': ' + [...new Set(row.issues)].join(' ')); continue; }
            if (row.mwe) row.issues.push('Both MWE and Non-MWE payroll exist in this year; review classification.');
            if (row.exemptBenefits > 9000000) row.issues.push('Recorded exempt benefits exceed the annual ₱90,000 threshold; review qualifying benefits.');
            if (row.incomplete) { row.gross = null; row.exempt = null; row.taxable = null; row.withheld = null; }
            row.due = annualTax(row.taxable, year);
            if (year < 2023) row.issues.push('Tax preview is supported for 2023 onward only.');
            row.issues = [...new Set(row.issues)];
            for (const issue of row.issues) issues.push(row.name + ': ' + issue);
            rows.push(row);
        }
        rows.sort((a, b) => a.name.localeCompare(b.name));
        const sum = field => rows.some(row => row[field] === null) ? null : rows.reduce((total, row) => total + row[field], 0);
        return {
            available: true, columns: ['#', 'Employee name', 'Employee code', 'TIN', 'Gross compensation', 'Non-taxable / exempt', 'Taxable compensation', 'Tax due preview', 'Recorded withheld', 'Review status'], numeric: [4, 5, 6, 7, 8],
            rows: rows.map((row, index) => ({cells: [index + 1, row.name, row.code, row.tin, row.gross, row.exempt, row.taxable, row.due, row.withheld, row.issues.length ? 'Review needed' : 'Locally checked'], employee: row})),
            totals: ['TOTAL', '', '', '', sum('gross'), sum('exempt'), sum('taxable'), sum('due'), sum('withheld'), ''],
            stats: {base: sum('gross'), tax: sum('withheld'), count: rows.length, exempt: sum('exempt'), taxable: sum('taxable'), due: sum('due'), excluded, sourceCount}, issues,
            baseLabel: 'Gross compensation', taxLabel: 'Recorded withheld',
            note: 'Annual Non-MWE review from posted payroll, by pay date. Exempt totals include recorded de minimis, exempt benefits and employee contributions. Tax due is a current-employer preview; prior-employer compensation and year-end adjustments are not captured. Verify exemptions before filing. ' + excluded + ' MWE employee-run row(s) excluded.',
            emptyTitle: 'No posted Non-MWE payroll for ' + year,
            emptyDescription: 'Post payroll with a Taxable or Non-taxable pay type, and maintain employee names and TINs in Masters. Drafts and void payroll are excluded.'
        };
    }
    function excelRows(report) {
        const cells = row => row.map((value, index) => report.numeric.includes(index) ? value === null ? 'Review' : value / 100 : value);
        return [...report.rows.map(row => ({kind: 'account', cells: cells(row.cells)})), {kind: 'grand', cells: cells(report.totals)}];
    }
    function certificatePayload(employee, year) {
        if (!employee.employeeId) throw Error('Link this employee to Masters > Employees before printing Form 2316.');
        if (employee.incomplete || employee.mwe || employee.taxable === null || !employee.lines.length) throw Error('Review this employee’s payroll classification and taxable benefits before printing Form 2316.');
        const amount = value => { if (!Number.isSafeInteger(value) || value < 0) throw Error('Review incomplete payroll amounts before printing.'); return (value / 100).toFixed(2); };
        return {year, records: employee.lines.map(line => ({source_id: line.sourceId, employee_id: employee.employeeId, status: 'posted', date: line.date, pay_type: line.payType, basic: amount(line.basic), allowance: amount(line.allowance), de_minimis: amount(line.deMinimis), benefits: amount(line.benefits), taxable_benefits: amount(line.taxableBenefits), contributions: amount(line.contributions), withheld: amount(line.withheld)}))};
    }
    const api = {annualTax, build, excelRows, certificatePayload};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.AlphalistData = api;
})(typeof window === 'undefined' ? null : window);
