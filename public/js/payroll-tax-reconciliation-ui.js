(function (root) {
    'use strict';
    function mount({records, employees}) {
        const dialog = document.createElement('dialog');
        dialog.className = 'sheet-import-dialog guided-import payroll-import-dialog';
        dialog.setAttribute('aria-label', 'Payroll tax reconciliation');
        dialog.innerHTML = `<header><div><span class="sheet-import-eyebrow">PAYROLL · ACTUAL VS CALCULATED</span><h2>Tax reconciliation</h2></div><button type="button" data-close aria-label="Close tax reconciliation">×</button></header><div class="sheet-import-body"><div class="sheet-import-controls"><label>Comparison<select data-mode><option value="monthly">Monthly table comparison</option><option value="annual">Annual refund / shortfall preview</option></select></label><label data-month-label>Month<input type="month" data-month></label><label>Year<input type="number" data-year min="2023" max="2100" step="1"></label><label>Employee<input type="search" data-search placeholder="Search employee"></label><button type="button" data-export>Export CSV</button></div><p data-guide></p><p data-summary role="status"></p><div data-errors role="alert"></div><div class="sheet-import-table-wrap"><table><thead><tr><th>Period</th><th>Employee</th><th>Actual tax deducted</th><th data-benchmark-title>Monthly table benchmark</th><th data-over-title>Above benchmark</th><th>Below benchmark</th><th>Notes</th></tr></thead><tbody></tbody></table></div><p>Only posted payroll in this company is included. This report does not alter actual deductions, issue refunds or post adjustments.</p></div><footer><span data-count></span><button type="button" data-close>Close</button></footer>`;
        document.body.append(dialog);
        const q = selector => dialog.querySelector(selector);
        const date = root.WorkingPeriod?.current?.().from || new Date().toISOString().slice(0, 10);
        q('[data-year]').value = date.slice(0, 4); q('[data-month]').value = date.slice(0, 7);
        let result = {rows: [], mode: 'monthly', issues: []};
        const money = amount => amount === null ? 'Unavailable' : '₱'+(amount/100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        function render() {
            try {
                const mode = q('[data-mode]').value, year = Number(q('[data-year]').value);
                q('[data-month-label]').hidden = mode === 'annual';
                q('[data-benchmark-title]').textContent = mode === 'annual' ? 'Annual tax preview' : 'Monthly table benchmark';
                q('[data-over-title]').textContent = mode === 'annual' ? 'Potential excess withholding' : 'Above benchmark';
                q('[data-guide]').textContent = mode === 'annual'
                    ? 'Potential annual refund / shortfall from recorded payroll only. This is not a final refund authorization. Include the full year (or final-pay period), prior-employer taxable income and tax withheld, and correct benefit exemptions before settlement. Monthly over-withholding must not be treated as the final annual refund.'
                    : 'Actual tax deducted versus the BIR monthly table on recorded taxable pay after employee contributions. Contributions may be supplied or calculated. Non-taxable classifications show a regular-employee benchmark for checking the exemption basis. December, final pay and supplementary pay may require annualized calculations.';
                result = root.PayrollTaxReconciliation.build(records(), employees, {mode, year, month: mode === 'monthly' ? q('[data-month]').value : null});
                const search = q('[data-search]').value.trim().toLowerCase();
                result.rows = result.rows.filter(row => String(row.name).toLowerCase().includes(search));
                const body = q('tbody'); body.replaceChildren();
                for (const row of result.rows) {
                    const tr = document.createElement('tr');
                    for (const text of [row.period, row.name, money(row.actual), money(row.benchmark), money(row.over), money(row.short), row.notes.join(' ') || 'Monthly table comparison']) {
                        const td = document.createElement('td'); td.textContent = text; tr.append(td);
                    }
                    body.append(tr);
                }
                q('[data-count]').textContent = result.rows.length+' employee-period rows';
                const available = result.rows.filter(row => row.benchmark !== null);
                q('[data-summary]').textContent = 'Calculated rows: '+available.length+' · '+(mode === 'annual' ? 'Potential excess' : 'Above benchmark')+' '+money(available.reduce((sum, row) => sum+row.over, 0))+' · Below benchmark '+money(available.reduce((sum, row) => sum+row.short, 0));
                q('[data-errors]').textContent = result.issues.join(' ');
                q('[data-export]').disabled = !result.rows.length;
            } catch (error) { q('[data-errors]').textContent = error.message; q('[data-export]').disabled = true; }
        }
        for (const selector of ['[data-mode]', '[data-year]', '[data-search]']) q(selector).addEventListener('input', render);
        q('[data-month]').addEventListener('input', () => { if (q('[data-month]').value) q('[data-year]').value = q('[data-month]').value.slice(0, 4); render(); });
        q('[data-export]').addEventListener('click', () => {
            const url = URL.createObjectURL(new Blob([root.PayrollTaxReconciliation.csv(result)], {type: 'text/csv;charset=utf-8'}));
            const link = document.createElement('a'); link.href = url; link.download = 'payroll-tax-'+result.mode+'-'+q('[data-year]').value+'.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        dialog.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        return {open() { render(); dialog.showModal(); }, dialog};
    }
    root.PayrollTaxReconciliationUI = {mount};
})(window);
