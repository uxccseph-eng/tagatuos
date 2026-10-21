(function (root) {
    'use strict';
    const payroll = typeof module !== 'undefined' && module.exports ? require('./payroll-register.js') : root.PayrollRegister;
    function journal(run) {
        const sum = field => run.employees.reduce((total, employee) => total + payroll.cents(employee[field]), 0);
        const lines = [
            ['Salaries & Wages', sum('basic') + sum('taxableAllowance'), 0],
            ['13th Month & Other Benefits', sum('benefits'), 0],
            ['De Minimis & Non-Taxable Benefits', sum('deMinimis'), 0],
            ['SSS Contributions Expense', sum('sssEr'), 0],
            ['PhilHealth Contributions Expense', sum('phicEr'), 0],
            ['Pag-IBIG Contributions Expense', sum('hdmfEr'), 0],
            ['SSS Payable', 0, sum('sssEe') + sum('sssEr')],
            ['PhilHealth Payable', 0, sum('phicEe') + sum('phicEr')],
            ['Pag-IBIG Payable', 0, sum('hdmfEe') + sum('hdmfEr')],
            ['Withholding Tax Payable', 0, sum('withholding')],
            ['Salaries Payable', 0, payroll.runTotals(run).net],
        ].filter(([, debit, credit]) => debit || credit);
        return {lines, debit: lines.reduce((total, line) => total + line[1], 0), credit: lines.reduce((total, line) => total + line[2], 0)};
    }
    function safeLink(value) {
        const text = String(value || '').trim();
        let url;
        try { url = new URL(text); } catch { throw new Error('Enter a complete https:// link.'); }
        if (url.protocol !== 'https:' || url.username || url.password) throw new Error('Use an https:// link without embedded credentials.');
        return url.href;
    }
    const attachmentTypes = ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'];
    function validateAttachment(file, attachments) {
        if (!attachmentTypes.includes(file.type)) throw new Error('Attach a PDF, PNG, JPG or text document.');
        if (file.size > 1024 * 1024) throw new Error('Each document must be 1 MB or smaller.');
        if (attachments.length >= 5 || attachments.reduce((total, item) => total + item.size, 0) + file.size > 2 * 1024 * 1024) throw new Error('Attach up to five documents, totalling no more than 2 MB per run.');
        if (attachments.some(item => item.name === file.name && item.size === file.size)) throw new Error('This document is already attached.');
    }
    function mount({getRun, update, action, remittances = () => [], canRemit = () => true}) {
        const dialog = document.getElementById('payroll-entry');
        const find = selector => dialog.querySelector(selector);
        const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
        const money = value => new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value / 100);
        let currentId = null;
        const error = message => { find('[data-entry-error]').textContent = message; };
        function refresh() {
            if (!dialog.open || !currentId) return;
            const run = getRun(currentId);
            if (!run) { dialog.close(); return; }
            const draft = run.status === 'draft';
            find('#payroll-entry-title').textContent = run.number;
            find('[data-entry-status]').className = `payroll-badge ${run.status}`;
            find('[data-entry-status]').textContent = {draft: 'Drafting', posted: 'Posted', void: 'Void'}[run.status];
            find('[data-entry-mode]').textContent = run.automation ? 'Automatic' : 'Manual';
            find('[data-entry-void]').textContent = run.status === 'void' ? `Void reason: ${run.voidReason || 'Not recorded'}` : '';
            for (const field of ['date', 'name', 'periodStart', 'periodEnd']) {
                const input = find(`[data-entry-field="${field}"]`);
                if (input !== document.activeElement) input.value = run[field] || '';
                input.disabled = !draft;
            }
            find('[data-entry-frequency]').textContent = {monthly: 'Monthly', semi_monthly: 'Semi-monthly', weekly: 'Weekly'}[run.frequency || 'monthly'];
            find('[data-entry-post]').hidden = !draft;
            find('[data-entry-delete]').hidden = run.status === 'posted';
            find('[data-entry-edit]').hidden = run.status === 'void';
            find('[data-entry-void-button]').hidden = run.status !== 'posted';
            const journals = remittances(run.id);
            find('[data-entry-remittance]').hidden = run.status !== 'posted' && journals.length === 0;
            find('[data-entry-remit]').hidden = run.status !== 'posted';
            const remitted = journals.some(entry => ['posted', 'draft'].includes(entry.status));
            find('[data-entry-remit]').disabled = run.status !== 'posted' || !canRemit(run.id);
            for (const selector of ['[data-entry-edit]', '[data-entry-void-button]']) {
                find(selector).disabled = remitted;
                find(selector).title = remitted ? 'Finish/delete the remittance draft, or void its posted journal first.' : '';
            }
            find('[data-remitted-heading]').hidden = journals.length === 0;
            find('[data-entry-remittances]').innerHTML = journals.map(entry => {
                const url = new URL(dialog.dataset.journalUrl);
                url.searchParams.set('entry', String(entry.id));
                url.searchParams.set('period', entry.date.slice(0, 7));
                url.searchParams.set('from', entry.date.slice(0, 7) + '-01');
                url.searchParams.set('to', new Date(Date.UTC(Number(entry.date.slice(0, 4)), Number(entry.date.slice(5, 7)), 0)).toISOString().slice(0, 10));
                return `<li><a href="${escape(url.href)}">${escape(entry.number)} ↗</a><span>${entry.status === 'posted' ? 'Posted' : entry.status === 'void' ? 'Voided' : entry.status === 'draft' ? 'Draft' : escape(entry.status)}</span></li>`;
            }).join('');
            try {
                const totals = payroll.runTotals(run), breakdown = journal(run);
                for (const field of ['gross', 'net', 'cost']) find(`[data-entry-total="${field}"]`).textContent = `₱${money(totals[field])}`;
                find('[data-entry-journal]').innerHTML = breakdown.lines.length ? breakdown.lines.map(([account, debit, credit]) => `<tr><td>${escape(account)}</td><td>${debit ? money(debit) : '—'}</td><td>${credit ? money(credit) : '—'}</td></tr>`).join('') : '<tr><td colspan="3">Add employee earnings to see the breakdown.</td></tr>';
                find('[data-entry-balance]').textContent = `Dr ${money(breakdown.debit)} / Cr ${money(breakdown.credit)} · ${breakdown.debit === breakdown.credit ? 'Balanced' : 'Review amounts'}`;
                find('[data-entry-employees]').innerHTML = run.employees.map(employee => `<tr><td>${escape(employee.name || 'Unnamed employee')}</td><td>${escape(employee.payType)}</td><td>${money(payroll.totals(employee).gross)}</td><td>${money(payroll.totals(employee).net)}</td></tr>`).join('') || '<tr><td colspan="4">No employees added.</td></tr>';
                error(run.automationError || (draft ? payroll.error(run) : ''));
            } catch (exception) {
                for (const field of ['gross', 'net', 'cost']) find(`[data-entry-total="${field}"]`).textContent = '—';
                find('[data-entry-journal]').innerHTML = '<tr><td colspan="3">Correct invalid amounts in the sheet to view the breakdown.</td></tr>';
                find('[data-entry-employees]').innerHTML = '<tr><td colspan="4">Correct invalid amounts in the sheet.</td></tr>';
                find('[data-entry-balance]').textContent = 'Review amounts';
                error(exception.message);
            }
            find('[data-entry-files]').innerHTML = (run.attachments || []).map((file, index) => `<li><span>${escape(file.name)}</span><button type="button" data-file-download="${index}" aria-label="Download ${escape(file.name)}"><span class="material-symbols-outlined" aria-hidden="true">download</span></button><button type="button" data-file-remove="${index}" aria-label="Remove ${escape(file.name)}"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></li>`).join('') || '<li>No files yet.</li>';
            find('[data-entry-links]').innerHTML = (run.links || []).map((link, index) => {
                try { const url = safeLink(link); return `<li><a href="${escape(url)}" target="_blank" rel="noopener noreferrer">${escape(url)}</a><button type="button" data-link-remove="${index}" aria-label="Remove link"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></li>`; }
                catch { return '<li>Invalid saved link</li>'; }
            }).join('') || '<li>No links added.</li>';
        }
        function save(changes) {
            if (!currentId || !getRun(currentId)) return false;
            if (!update(currentId, changes)) { error('Unable to save. Your last saved entry is unchanged.'); return false; }
            refresh();
            return true;
        }
        dialog.addEventListener('input', event => {
            const field = event.target.dataset.entryField;
            if (field && getRun(currentId)?.status === 'draft') save({[field]: event.target.value});
        });
        dialog.addEventListener('click', event => {
            const close = event.target.closest('[data-entry-close]');
            if (close) { dialog.close(); return; }
            const control = event.target.closest('[data-entry-action]');
            if (control) { action(control.dataset.entryAction, currentId); refresh(); return; }
            const run = getRun(currentId);
            if (!run) return;
            const download = event.target.closest('[data-file-download]');
            if (download) {
                const file = run.attachments?.[Number(download.dataset.fileDownload)];
                if (!file || !attachmentTypes.includes(file.type) || !String(file.data).startsWith(`data:${file.type};base64,`)) { error('This saved attachment cannot be downloaded safely.'); return; }
                const link = document.createElement('a'); link.href = file.data; link.download = file.name; document.body.append(link); link.click(); link.remove(); return;
            }
            const removeFile = event.target.closest('[data-file-remove]');
            if (removeFile) window.DraftLeaveGuard.confirmDelete(() => save({attachments: (run.attachments || []).filter((file, index) => index !== Number(removeFile.dataset.fileRemove))}), 'this attachment', {title: 'Remove attachment?', description: 'Remove this document from the payroll run?'});
            const removeLink = event.target.closest('[data-link-remove]');
            if (removeLink) save({links: (run.links || []).filter((link, index) => index !== Number(removeLink.dataset.linkRemove))});
        });
        find('[data-entry-link-form]').addEventListener('submit', event => {
            event.preventDefault();
            try {
                const url = safeLink(find('[data-entry-link]').value), links = getRun(currentId)?.links || [];
                if (links.includes(url)) throw new Error('This link is already added.');
                if (links.length >= 10) throw new Error('Add up to 10 links per run.');
                if (save({links: [...links, url]})) find('[data-entry-link]').value = '';
            } catch (exception) { error(exception.message); }
        });
        find('[data-entry-file]').addEventListener('change', async event => {
            const file = event.target.files[0], runId = currentId;
            if (!file || !runId) return;
            try {
                validateAttachment(file, getRun(runId)?.attachments || []);
                const data = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(new Error('Unable to read document.')); reader.readAsDataURL(file); });
                const run = getRun(runId);
                if (!run || currentId !== runId || !dialog.open) return;
                validateAttachment(file, run.attachments || []);
                save({attachments: [...(run.attachments || []), {name: file.name, type: file.type, size: file.size, data}]});
            } catch (exception) { error(exception.message); }
            finally { event.target.value = ''; }
        });
        dialog.addEventListener('cancel', event => { event.preventDefault(); });
        dialog.addEventListener('close', () => { currentId = null; find('[data-entry-link]').value = ''; });
        return {open(id) { if (!getRun(id)) return; currentId = id; if (!dialog.open) dialog.showModal(); refresh(); find('[data-entry-close]').focus(); }, refresh, close() { dialog.close(); }};
    }
    const api = {journal, safeLink, validateAttachment, mount};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.PayrollDetails = api;
})(globalThis);
