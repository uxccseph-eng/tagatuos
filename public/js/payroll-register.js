(function (root) {
    'use strict';
    const earnings = ['basic', 'taxableAllowance', 'deMinimis', 'benefits'];
    const deductions = ['sssEe', 'phicEe', 'hdmfEe', 'withholding'];
    const contributions = ['sssEr', 'phicEr', 'hdmfEr'];
    const amountFields = [...earnings, ...deductions, ...contributions];
    function cents(value) {
        const text = String(value ?? '').trim();
        if (text === '') return 0;
        if (!/^\d{1,9}(\.\d{0,2})?$/.test(text)) throw new Error('Use a non-negative amount with up to two decimal places.');
        const [whole, fraction = ''] = text.split('.');
        return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    }
    function totals(employee) {
        const sum = fields => fields.reduce((total, field) => total + cents(employee[field]), 0);
        const gross = sum(earnings);
        return {gross, net: gross - sum(deductions), cost: gross + sum(contributions)};
    }
    function runTotals(run) {
        return run.employees.reduce((total, employee) => {
            const amounts = totals(employee);
            for (const field of ['gross', 'net', 'cost']) total[field] += amounts[field];
            return total;
        }, {gross: 0, net: 0, cost: 0});
    }
    function validDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(value || '') && !Number.isNaN(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value;
    }
    function error(run) {
        if (run.automation && run.automationError) return run.automationError;
        if (![run.date, run.periodStart, run.periodEnd].every(validDate)) return 'Enter a valid pay date, period start and period end.';
        if (run.periodStart > run.periodEnd) return 'Period end must be on or after period start.';
        if (!run.employees.length) return 'Add at least one employee.';
        const names = new Set();
        for (const employee of run.employees) {
            const name = employee.name.trim().replace(/\s+/g, ' ').toLowerCase();
            if (!name) return 'Enter each employee’s name.';
            if (names.has(name)) return 'An employee appears more than once in this run.';
            names.add(name);
            try {
                const amounts = totals(employee);
                if (amounts.gross <= 0) return 'Each employee must have positive gross pay.';
                if (amounts.net < 0) return 'Deductions cannot exceed gross pay.';
            } catch (exception) { return exception.message; }
        }
        return '';
    }
    function createRun(records, dates) {
        const existing = records.find(record => record.status === 'draft');
        if (existing) return existing;
        return {id: root.crypto.randomUUID(), number: '', status: 'draft', draftTouched: false, date: dates.date, periodStart: dates.from, periodEnd: dates.to, name: '', employees: []};
    }
    function renumber(records) {
        let next = records.filter(record=>record.status!=='draft').reduce((highest,record)=>Math.max(highest,Number(String(record.number||'').match(/^PYRJ-(\d+)$/)?.[1]||0)),0);
        return records.map(record=>({...record,number:record.status==='draft'?'':record.number||(record.status==='posted'?`PYRJ-${String(++next).padStart(6,'0')}`:'')}));
    }
    function reopenForEditing(records, id) {
        const run = records.find(record => record.id === id);
        if (!run || run.status === 'void') throw new Error('Only draft or posted payroll entries can be edited.');
        if (run.status === 'draft') return records;
        if (records.some(record => record.status === 'draft' && record.id !== id)) throw new Error('Finish or delete the existing draft before editing this posted run.');
        const {editHistory, ...snapshot} = run;
        const edited = {...run, status: 'draft', draftTouched: true, draftSaved: true, editHistory: [...(editHistory || []), {reopenedAt: new Date().toISOString(), previous: JSON.parse(JSON.stringify(snapshot))}]};
        delete edited.postedAt;
        return records.map(record => record.id === id ? edited : record);
    }
    function post(records, ids) {
        const runs = records.filter(record => ids.includes(record.id) && record.status === 'draft');
        if (!runs.length) throw new Error('Select a draft payroll run to post.');
        for (const run of runs) {
            const message = error(run);
            if (message) throw new Error(`${run.number}: ${message}`);
        }
        let next=records.filter(record=>record.status!=='draft').reduce((highest,record)=>Math.max(highest,Number(String(record.number||'').match(/^PYRJ-(\d+)$/)?.[1]||0)),0);
        return records.map(record => runs.includes(record) ? {...record, number:`PYRJ-${String(++next).padStart(6,'0')}`, status: 'posted', postedAt: new Date().toISOString()} : record);
    }
    const importHeaders = ['pay_date', 'run_name', 'period_start', 'period_end', 'employee', 'pay_type', ...amountFields];
    function importRun(rows, records) {
        if (rows.length < 2 || rows.length > 501) throw new Error('Include between 1 and 500 employee rows.');
        if (JSON.stringify(rows[0]) !== JSON.stringify(importHeaders)) throw new Error('Use the downloaded template headers without changes.');
        const first = rows[1];
        const run = {...createRun([], {date: first[0], from: first[2], to: first[3]}), name: first[1], draftTouched: true};
        run.employees = rows.slice(1).map((row, index) => {
            if (row.length !== importHeaders.length || row.slice(0, 4).some((value, position) => value !== first[position])) throw new Error(`Row ${index + 2}: all rows must belong to the same payroll run.`);
            if (!['Taxable', 'Non-taxable', 'Minimum Wage Earner'].includes(row[5])) throw new Error(`Row ${index + 2}: choose a valid pay type.`);
            return {name: row[4], payType: row[5], ...Object.fromEntries(amountFields.map((field, position) => [field, row[position + 6]]))};
        });
        const message = error(run);
        if (message) throw new Error(message);
        if (records.some(item => item.date === run.date && item.periodStart === run.periodStart && item.periodEnd === run.periodEnd && item.status !== 'void')) throw new Error('A payroll run already exists for this pay date and period. Review it before importing.');
        if (records.some(item => item.status === 'draft')) throw new Error('Finish or delete the existing draft before importing.');
        return run;
    }
    const api = {amountFields, cents, totals, runTotals, validDate, error, createRun, renumber, reopenForEditing, post, importHeaders, importRun};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.PayrollRegister = api;
})(globalThis);
