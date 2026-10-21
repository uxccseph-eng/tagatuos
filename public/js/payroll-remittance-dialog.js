'use strict';
window.PayrollRemittanceDialog = {
    mount({ledger, catalog, runs, user, defaultBank, onSaved}) {
        const dialog = document.getElementById('payroll-remittance'), form = dialog.querySelector('form');
        const find = selector => dialog.querySelector(selector), api = window.PayrollRemittance;
        const money = amount => new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(amount / 100);
        const labels = {salary: 'Salaries Payable', withholding: 'WHT Payable - Compensation', sss: 'SSS Payable', phic: 'PhilHealth Payable', hdmf: 'Pag-IBIG Payable'};
        const patterns = {salary: /salar(?:y|ies).*payable|payable.*salar/i, sss: /sss.*payable|payable.*sss/i, phic: /(?:philhealth|phic).*payable|payable.*(?:philhealth|phic)/i, hdmf: /(?:pag.?ibig|hdmf).*payable|payable.*(?:pag.?ibig|hdmf)/i, withholding: /(?:compensation|payroll).*withholding.*payable|withholding.*(?:compensation|payroll).*payable|withholding tax payable/i};
        let selectedRuns = new Set(), selectedLiabilities = new Set(), accounts = {}, pendingId = null, totals = {};
        const selected = () => runs().filter(run => run.status === 'posted' && selectedRuns.has(String(run.id)));
        function selectAccount(choices, value, label) {
            const select = document.createElement('select'); select.setAttribute('aria-label', label);
            select.append(new Option('Select an account from Masters', ''));
            for (const account of choices) select.append(new Option(`${account.code} · ${account.name}`, account.code));
            if (choices.some(account => account.code === value)) select.value = value;
            return select;
        }
        function row(label, value, checked, amount) {
            const wrapper = document.createElement('label'); wrapper.className = 'remittance-check';
            const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.value = value; checkbox.checked = checked;
            const text = document.createElement('span'); text.textContent = label; wrapper.append(checkbox, text);
            if (amount !== undefined) { const total = document.createElement('strong'); total.textContent = money(amount); wrapper.append(total); }
            return {wrapper, checkbox};
        }
        function renderAmounts() {
            totals = Object.fromEntries(Object.keys(labels).map(key => [key, 0]));
            for (const run of selected()) for (const liability of api.remaining(run, ledger)) totals[liability.key] += liability.amount;
            const list = find('[data-remittance-liabilities]'); list.replaceChildren();
            const mappings = find('[data-remittance-accounts]'); mappings.replaceChildren();
            const active = catalog.filter(account => !account.archived && account.type === 'Liability');
            for (const [key, label] of Object.entries(labels)) {
                const item = row(label, key, selectedLiabilities.has(key) && totals[key] > 0, totals[key]); item.checkbox.disabled = totals[key] <= 0;
                item.checkbox.addEventListener('change', () => { if (item.checkbox.checked) selectedLiabilities.add(key); else selectedLiabilities.delete(key); renderAmounts(); });
                list.append(item.wrapper);
                if (totals[key] > 0 && selectedLiabilities.has(key)) {
                    const wrapper = document.createElement('label'); wrapper.append(document.createTextNode(label));
                    const select = selectAccount(active, accounts[key], `${label} account`); select.dataset.liabilityAccount = key;
                    accounts[key] = select.value;
                    select.addEventListener('change', () => { accounts[key] = select.value; }); wrapper.append(select); mappings.append(wrapper);
                }
            }
            find('[data-remittance-total]').textContent = `₱${money(Object.entries(totals).reduce((sum, [key, amount]) => sum + (selectedLiabilities.has(key) ? amount : 0), 0))}`;
            find('[data-remittance-date]').min = selected().map(run => run.date).sort().at(-1) || '';
            find('[data-remittance-error]').textContent = '';
        }
        function submit(status) {
            try {
                const bank = find('[data-remittance-bank]'); bank.required = status === 'posted';
                for (const select of find('[data-remittance-accounts]').querySelectorAll('select')) select.required = status === 'posted';
                if (status === 'posted' && [...find('[data-remittance-accounts]').querySelectorAll('select')].some(select => !select.value)) find('[data-remittance-mapping]').open = true;
                if (!form.reportValidity()) return;
                const entry = api.saveBatch(selected(), {date: find('[data-remittance-date]').value, reference: find('[data-remittance-reference]').value, bank: bank.value, accounts, liabilities: [...selectedLiabilities].filter(key => totals[key] > 0), user}, ledger, catalog, status, pendingId);
                dialog.close(); onSaved(entry);
            } catch (exception) { find('[data-remittance-error]').textContent = exception.message; }
        }
        form.addEventListener('submit', event => { event.preventDefault(); submit('posted'); });
        find('[data-remittance-draft]').addEventListener('click', () => submit('draft'));
        dialog.querySelectorAll('[data-remittance-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('cancel', event => event.preventDefault());
        return {open(runId) {
            const source = runs().find(run => run.id === runId);
            if (!source || source.status !== 'posted') throw new Error('Select a posted payroll run.');
            const pending = api.linked(runId, ledger).find(entry => entry.status === 'draft'); pendingId = pending?.id ?? null;
            selectedRuns = new Set(pending ? api.allocations(pending).map(item => String(item.runId)) : [String(runId)]);
            selectedLiabilities = new Set(pending ? api.allocations(pending).flatMap(item => Object.keys(item.amounts)) : Object.keys(labels));
            const recent = pending || ledger.journalEntries().filter(entry => entry.origin === 'payroll-remittance').at(-1);
            accounts = {...recent?.remittanceAccounts};
            for (const key of Object.keys(labels)) {
                const matches = catalog.filter(account => !account.archived && account.type === 'Liability' && patterns[key].test(account.name));
                if (!accounts[key] && matches.length === 1) accounts[key] = matches[0].code;
            }
            find('#payroll-remittance-title').textContent = `Remit ${source.number} liabilities`;
            const today = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
            find('[data-remittance-date]').value = pending?.date || (today > source.date ? today : source.date);
            find('[data-remittance-reference]').value = pending?.reference || '';
            find('[data-remittance-mapping]').open = false;
            const bank = find('[data-remittance-bank]'), replacement = selectAccount(catalog.filter(account => !account.archived && account.is_cash_bank && account.type === 'Asset'), recent?.lines.at(-1)?.account.split(' · ')[0] || defaultBank, 'Cash/Bank account');
            const selectedBank = replacement.value; bank.replaceChildren(...replacement.childNodes); bank.value = selectedBank || (catalog.some(account => account.code === defaultBank && account.is_cash_bank && !account.archived) ? defaultBank : '');
            const list = find('[data-remittance-runs]'); list.replaceChildren();
            for (const run of runs().filter(item => item.status === 'posted')) {
                const available = api.remaining(run, ledger).some(item => item.amount > 0);
                const item = row(run.number, String(run.id), selectedRuns.has(String(run.id)));
                item.checkbox.disabled = !available;
                item.checkbox.addEventListener('change', () => { if (item.checkbox.checked) selectedRuns.add(String(run.id)); else selectedRuns.delete(String(run.id)); renderAmounts(); }); list.append(item.wrapper);
            }
            renderAmounts();
            if (!dialog.open) dialog.showModal();
            find('[data-remittance-date]').focus();
        }};
    },
};
