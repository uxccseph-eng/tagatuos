(function (root) {
    'use strict';
    const payroll = typeof module !== 'undefined' && module.exports ? require('./payroll-register.js') : root.PayrollRegister;
    function liabilities(run) {
        const error = payroll.error(run);
        if (error) throw new Error(error);
        const sum = fields => run.employees.reduce((total, employee) => total + fields.reduce((amount, field) => amount + payroll.cents(employee[field]), 0), 0);
        return [
            {key: 'salary', label: 'Salaries Payable', amount: payroll.runTotals(run).net},
            {key: 'sss', label: 'SSS Payable', amount: sum(['sssEe', 'sssEr'])},
            {key: 'phic', label: 'PhilHealth Payable', amount: sum(['phicEe', 'phicEr'])},
            {key: 'hdmf', label: 'Pag-IBIG Payable', amount: sum(['hdmfEe', 'hdmfEr'])},
            {key: 'withholding', label: 'Withholding Tax Payable', amount: sum(['withholding'])},
        ].filter(line => line.amount > 0);
    }
    function allocations(entry) {
        return entry.payrollAllocations || [{runId: entry.payrollRunId, amounts: Object.fromEntries(['salary', 'sss', 'phic', 'hdmf', 'withholding'].map(key => [key, null]))}];
    }
    function linked(runId, ledger) {
        return ledger.journalEntries().filter(entry => entry.origin === 'payroll-remittance' && allocations(entry).some(item => item.runId === runId));
    }
    function assertUnremitted(runId, ledger) {
        const active = linked(runId, ledger).find(entry => ['posted', 'draft'].includes(entry.status));
        if (active) throw new Error(`This payroll is already remitted or reserved by ${active.number}. Void the posted Journal entry or finish/delete its draft before editing or voiding this payroll.`);
    }
    function remaining(run, ledger) {
        const amounts = liabilities(run);
        for (const entry of linked(run.id, ledger).filter(item => item.status === 'posted')) {
            for (const allocation of allocations(entry).filter(item => item.runId === run.id)) {
                for (const item of amounts) {
                    if (Object.hasOwn(allocation.amounts, item.key)) item.amount = allocation.amounts[item.key] === null ? 0 : Math.max(0, item.amount - allocation.amounts[item.key]);
                }
            }
        }
        return amounts;
    }
    function buildBatch(runs, choices, ledger, catalog, status = 'posted', existingId = null) {
        if (!['draft', 'posted'].includes(status)) throw new Error('Choose draft or posted for the remittance.');
        if (!runs.length || new Set(runs.map(run => run.id)).size !== runs.length) throw new Error('Select at least one distinct posted payroll run.');
        if (runs.some(run => run.status !== 'posted')) throw new Error('Post the payroll run before remitting liabilities.');
        if (!payroll.validDate(choices.date) || runs.some(run => choices.date < run.date)) throw new Error('Choose a valid remittance date on or after every selected payroll date.');
        const existing = existingId === null ? null : ledger.journalEntries().find(entry => entry.id === existingId);
        if (existingId !== null && (!existing || existing.status !== 'draft' || existing.origin !== 'payroll-remittance')) throw new Error('This remittance draft is no longer available. Open it again.');
        if (status === 'draft' && ledger.journalEntries().some(entry => entry.status === 'draft' && entry.id !== existingId)) throw new Error('Finish or delete the existing Journal draft before saving another draft.');
        for (const run of runs) {
            const pending = linked(run.id, ledger).find(entry => entry.status === 'draft' && entry.id !== existingId);
            if (pending) throw new Error(`Continue remittance draft ${pending.number} before generating another for ${run.number}.`);
        }
        const keys = choices.liabilities || ['salary', 'sss', 'phic', 'hdmf', 'withholding'];
        if (!keys.length || keys.some(key => !['salary', 'sss', 'phic', 'hdmf', 'withholding'].includes(key))) throw new Error('Select at least one liability to remit.');
        const payrollAllocations = runs.map(run => ({runId: run.id, runNumber: run.number, amounts: Object.fromEntries(remaining(run, ledger).filter(item => keys.includes(item.key) && item.amount > 0).map(item => [item.key, item.amount]))})).filter(item => Object.keys(item.amounts).length);
        if (!payrollAllocations.length) throw new Error('The selected liabilities are already remitted or have no outstanding amount.');
        const accounts = catalog.filter(account => !account.archived);
        const bank = accounts.find(account => account.code === choices.bank && account.is_cash_bank && account.type === 'Asset');
        if (!bank && (status === 'posted' || choices.bank)) throw new Error('Select an active cash / bank account from Masters.');
        const labels = {salary: 'Salaries Payable', sss: 'SSS Payable', phic: 'PhilHealth Payable', hdmf: 'Pag-IBIG Payable', withholding: 'WHT Payable - Compensation'};
        const lines = [];
        for (const key of Object.keys(labels)) {
            const amount = payrollAllocations.reduce((sum, item) => sum + (item.amounts[key] || 0), 0);
            if (!amount) continue;
            const selected = choices.accounts?.[key] || '';
            const account = accounts.find(account => account.code === selected && account.type === 'Liability');
            if (!account && (status === 'posted' || selected)) throw new Error(`Select an active liability account for ${labels[key]}.`);
            lines.push({account: account ? `${account.code} · ${account.name}` : '', party: '', debit: amount, credit: 0, remarks: labels[key], payrollLiability: key});
        }
        const total = lines.reduce((sum, item) => sum + item.debit, 0);
        lines.push({account: bank ? `${bank.code} · ${bank.name}` : '', party: '', debit: 0, credit: total, remarks: 'Payroll remittance', payrollLiability: 'bank'});
        const timestamp = new Date().toISOString(), numbers = payrollAllocations.map(item => item.runNumber).join(', ');
        const entry = {...(existing || ledger.nextJournalEntry()), date: choices.date, status, type: 'General', reference: (choices.reference || '').trim(), particulars: `Remittance of payroll liabilities · ${numbers}`, notes: 'Records the remittance in the books; no bank payment is sent.', lines, attachments: existing?.attachments || [], origin: 'payroll-remittance', payrollRunId: payrollAllocations[0].runId, payrollNumber: payrollAllocations[0].runNumber, payrollAllocations, remittanceAccounts: {...choices.accounts}, history: [...(existing?.history || []), {label: `${status === 'posted' ? 'Posted' : 'Saved draft'} payroll remittance for ${numbers}`, user: choices.user || 'Workspace user', time: timestamp}]};
        if (status === 'posted') entry.postedAt = timestamp;
        else delete entry.postedAt;
        return entry;
    }
    function saveBatch(runs, choices, ledger, catalog, status = 'posted', existingId = null) {
        const entry = buildBatch(runs, choices, ledger, catalog, status, existingId);
        ledger.saveJournalEntries([...ledger.journalEntries().filter(item => item.id !== existingId), entry]);
        return entry;
    }
    function build(run, choices, ledger, catalog) { return buildBatch([run], choices, ledger, catalog); }
    function post(run, choices, ledger, catalog) { return saveBatch([run], choices, ledger, catalog); }
    const api = {liabilities, linked, assertUnremitted, remaining, allocations, build, post, buildBatch, saveBatch};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.PayrollRemittance = api;
})(globalThis);
