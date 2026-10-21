(function (root) {
    function build(ledger, type, row, range) {
        const codeFor = line => String(line.account).split(' · ')[0];
        const profile = ledger.accountProfile(row.code);
        const opening = type === 'changes-in-equity' && row.key.startsWith('opening:');
        const cumulative = ['trial-balance', 'balance-sheet'].includes(type);
        const vouchers = ledger.bookEntries().filter(entry => entry.date <= range.endDate && (opening ? entry.date < range.startDate : cumulative || entry.date >= range.startDate)).filter(entry => entry.lines.some(line => codeFor(line) === row.code) && (type !== 'cash-flow' || entry.lines.some(line => ledger.accountProfile(codeFor(line)).is_cash_bank))).map(entry => ({...entry, prior: entry.date < range.startDate, lines: entry.lines.map(line => ({...line, selected: codeFor(line) === row.code}))}));
        const selected = vouchers.flatMap(entry => entry.lines.filter(line => line.selected));
        const debit = selected.reduce((sum, line) => sum + line.debit, 0), credit = selected.reduce((sum, line) => sum + line.credit, 0);
        const creditPositive = type === 'cash-flow' || type === 'changes-in-equity' || ['Liability', 'Equity', 'Income'].includes(profile.type);
        const amount = type === 'trial-balance' ? debit - credit : (debit - credit) * (creditPositive ? -1 : 1);
        return {vouchers, debit, credit, amount: amount || 0, scope: opening ? 'Opening balance: transactions before ' + range.startDate : cumulative ? 'All posted transactions through ' + range.endDate + ', including opening balances.' : 'Posted transactions from ' + range.startDate + ' to ' + range.endDate + (type === 'cash-flow' ? ' involving cash or bank accounts.' : '.'), profile};
    }
    function transactions(ledger, type, row, range, metric = 'balance') {
        const detail = build(ledger, type, row, range), rows = [];
        const creditPositive = type === 'cash-flow' || type === 'changes-in-equity' || ['Liability', 'Equity', 'Income'].includes(detail.profile.type);
        let balance = 0;
        const append = record => {balance += record.amount; rows.push({...record, balance: balance || 0});};
        const movementMetric = type === 'trial-balance' && ['debit', 'credit'].includes(metric);
        if (movementMetric) {
            const opening = detail.vouchers.filter(entry => entry.prior).flatMap(entry => entry.lines.filter(line => line.selected)).reduce((sum, line) => sum + line.debit - line.credit, 0);
            const amount = metric === 'debit' ? Math.max(0, opening) : Math.max(0, -opening);
            if (amount) append({date: range.startDate, type: 'Opening balance', number: '', name: '', description: 'Net balance brought forward from before ' + range.startDate, account: row.code + ' · ' + row.label, split: '', amount, opening: true});
        }
        for (const entry of [...detail.vouchers].sort((a,b) => a.date.localeCompare(b.date) || a.number.localeCompare(b.number))) {
            if (movementMetric && entry.prior) continue;
            const selected = entry.lines.filter(line => line.selected);
            for (const line of selected) {
                const amount = movementMetric ? line[metric] : (line.debit - line.credit) * (type === 'trial-balance' ? 1 : creditPositive ? -1 : 1);
                if (movementMetric && !amount) continue;
                const parties = [...new Set(entry.lines.map(item => item.party).filter(Boolean))];
                append({date: entry.date, type: entry.sheet === 'journal' ? 'Journal Entry' : entry.source || 'Journal Entry', number: entry.number, name: line.party || parties.join('; '), description: line.remarks || entry.reference || '', account: line.account, split: [...new Set(entry.lines.filter(item => !item.selected).map(item => item.account))].join('; '), amount: amount || 0, prior: entry.prior});
            }
        }
        return {...detail, rows, total: balance || 0, metric: movementMetric ? metric : 'balance'};
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {build, transactions};
    if (root) root.FinancialAccountBreakdown = {build, transactions};
})(typeof window === 'undefined' ? null : window);
