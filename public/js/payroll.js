(() => {
    'use strict';
    const api = window.PayrollRegister;
    const automation = window.PayrollAutomation;
    const preferences = JSON.parse(document.getElementById('payroll-settings').textContent);
    const element = id => document.getElementById(id);
    const body = element('payroll-rows');
    const key = `kontadurya.payroll.v1.${element('workspace-ledger-config').dataset.key}`;
    const money = value => new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value / 100);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
    const clone = value => JSON.parse(JSON.stringify(value));
    const labels = ['Basic', 'Taxable Allow', 'De Minimis', '13th & Other Benefits', 'SSS-EE', 'PHIC-EE', 'HDMF-EE', 'WTax (actual)', 'SSS-ER', 'PHIC-ER', 'HDMF-ER'];
    const selected = new Set();
    let details = null, remittanceDialog = null, payrollImportDialog = null;
    let records = [], status = 'all', descending = false, voidAction = null, storageAvailable = true;
    const announce = message => { element('payroll-status').textContent = message; };
    try {
        const stored = window.CompanyLedger ? window.CompanyLedger.payroll() : JSON.parse(sessionStorage.getItem(key) || '[]');
        if (!Array.isArray(stored) || stored.some(run => !run.id || !['draft', 'posted', 'void'].includes(run.status) || !Array.isArray(run.employees))) throw new Error('Invalid payroll data');
        records = stored.map(record=>record.status==='draft'?{...record,number:''}:record);
    } catch (exception) {
        storageAvailable = false;
        announce('Payroll storage could not be read. Reload this tab before editing; existing saved data has not been overwritten.');
    }
    function store(next) {
        if (!storageAvailable) { announce('Payroll storage is unavailable. Reload this tab before editing.'); return false; }
        const numbered = api.renumber(next, Boolean(window.CompanyLedger));
        try {
            const saved = numbered.filter(run => run.status !== 'draft' || run.draftTouched);
            if (window.CompanyLedger) window.CompanyLedger.savePayroll(saved);
            else sessionStorage.setItem(key, JSON.stringify(saved));
            records = numbered;
            details?.refresh();
            return true;
        } catch (exception) { announce('Could not save payroll in this tab. Your last saved data is unchanged.'); return false; }
    }
    function visible() {
        const query = `${element('payroll-search').value} ${element('sales-global-search').value}`.trim().toLowerCase();
        return records.filter(run => window.WorkingPeriod.matches(run.date) && (status === 'all' || run.status === status) && `${run.number} ${run.name} ${run.employees.map(employee => employee.name).join(' ')}`.toLowerCase().includes(query)).sort((a, b) => (descending ? -1 : 1) * a.date.localeCompare(b.date));
    }
    function candidates() { return window.SheetPosting.candidates(visible(), [...selected]); }
    function button(action, label, icon = '') {
        return `<button type="button" class="payroll-inline-button" data-action="${action}">${icon ? `<span class="material-symbols-outlined" aria-hidden="true">${icon}</span>` : ''}${label}</button>`;
    }
    function input(field, value, disabled, type = 'text', extra = '') {
        return `<input data-field="${field}" aria-label="${escape(({date: 'Pay Date', name: 'Run Name', periodStart: 'Period Start', periodEnd: 'Period End'})[field] || field)}" type="${type}" value="${escape(value)}" ${disabled ? 'disabled' : ''} ${extra}>`;
    }
    function totalsMarkup(run) {
        try {
            const total = api.runTotals(run);
            return `Gross <strong>${money(total.gross)}</strong> · Net <strong>${money(total.net)}</strong> · Employer cost <strong>${money(total.cost)}</strong>`;
        } catch (exception) { return escape(exception.message); }
    }
    function calculateDraft(run, source = records) {
        try { return automation.calculate({...run, automationError: ''}, source); }
        catch (exception) { return {...run, automationError: exception.message}; }
    }
    const salaryBaseLabels = {monthlyBasic: 'Monthly basic (PHIC)', monthlyCompensation: 'Monthly compensation (SSS)', monthlyFundSalary: 'Monthly fund salary (HDMF)', taxableBenefits: 'Taxable benefits this run'};
    function additionalEmployeeFields(run) {
        if (!run.automation) return [];
        return (run.frequency || preferences.payroll_frequency || 'monthly') === 'monthly'
            ? ['taxableBenefits']
            : Object.keys(salaryBaseLabels);
    }
    function render() {
        const runs = visible();
        body.innerHTML = runs.length ? runs.map(run => {
            const disabled = run.status !== 'draft';
            const additionalFields = additionalEmployeeFields(run);
            let total;
            try { total = api.runTotals(run); } catch (exception) { total = null; }
            const flag = run.status === 'void' ? run.voidReason : api.error(run);
            return `<tr data-run="${run.id}">
                <td><input type="checkbox" data-select="${run.id}" aria-label="Select ${run.number}" ${selected.has(run.id) ? 'checked' : ''}></td>
                <td><span class="payroll-badge ${run.status}">${{draft: 'Drafting', posted: 'Posted', void: 'Void'}[run.status]}</span></td>
                <td>${input('date', run.date, disabled, 'date')}</td><td class="payroll-number"><button type="button" class="payroll-open-entry" data-action="details" aria-label="Open payroll entry ${escape(run.number)}">${run.number}</button></td>
                <td>${input('name', run.name, disabled, 'text', 'class="payroll-run-name" maxlength="120" placeholder="e.g. Monthly payroll"')}</td>
                <td>${input('periodStart', run.periodStart, disabled, 'date')}</td><td>${input('periodEnd', run.periodEnd, disabled, 'date')}</td>
                ${['gross', 'net', 'cost'].map(field => `<td class="payroll-money" data-run-total="${field}">${total ? money(total[field]) : '—'}</td>`).join('')}
                <td data-flag title="${escape(flag)}">${flag ? (run.status === 'void' ? 'Voided' : 'Review') : '—'}</td>
                <td class="payroll-actions"><button type="button" class="payroll-more-button" data-action="more" aria-label="More options for ${run.number}" title="More options" aria-haspopup="menu" aria-expanded="false"><span class="material-symbols-outlined" aria-hidden="true">more_horiz</span></button></td>
            </tr><tr class="payroll-detail" data-run="${run.id}"><td colspan="12"><section class="payroll-employees" aria-label="Employees for ${run.number}">
                <h2>Employee breakdown · ${run.number}</h2><p class="payroll-calculation-error" data-calculation-error role="alert">${escape(run.automationError || '')}</p><div class="payroll-employee-scroll"><table aria-label="Employees for ${run.number}"><thead><tr><th>Employee</th><th>Pay Type</th>${labels.map(label => `<th>${label}</th>`).join('')}${additionalFields.map(field => `<th>${salaryBaseLabels[field]}</th>`).join('')}${run.clientPayrollImport ? '<th>Periodic reference tax</th><th>Actual minus reference</th><th>Review note</th>' : ''}<th>Net Pay</th><th>Employee actions</th></tr></thead>
                <tbody>${run.employees.map((employee, index) => employeeRow(employee, index, disabled, run.automation, additionalFields, run.clientPayrollImport)).join('') || `<tr><td colspan="${15 + additionalFields.length + (run.clientPayrollImport ? 3 : 0)}" class="payroll-empty">No employees yet. Add an employee to begin.</td></tr>`}</tbody></table></div>
                ${disabled ? '' : button('employee', 'Add employee', 'person_add')}<div class="payroll-totals" data-totals>${totalsMarkup(run)}</div>
            </section></td></tr>`;
        }).join('') : '<tr><td colspan="12" class="payroll-empty">No payroll runs in this working period. Select Add run to begin.</td></tr>';
        for(const run of runs.filter(item=>item.status==='draft')){
            const row=[...body.children].find(node=>node.dataset.run===run.id&&!node.classList.contains('payroll-detail'));
            window.SheetPosting.draftActions(row.querySelector('.payroll-actions'),run,{
                save:()=>{const next=records.map(item=>item.id===run.id?{...item,draftTouched:true,draftSaved:true}:item);if(store(next)){render();announce(run.number+(window.CompanyLedger?' draft queued for company database saving.':' draft saved in this browser tab.'));}},
                post:()=>post([run.id]),delete:()=>deleteRuns([run]),more:event=>entryOptions(run,event.currentTarget)
            });
        }
        updateToolbar();
    }
    function employeeRow(employee, index, disabled, automatic, additionalFields, imported = false) {
        let net = '—';
        try { net = money(api.totals(employee).net); } catch (exception) { /* Invalid input remains visible for correction. */ }
        return `<tr data-employee="${index}"><td><input class="employee-name" data-employee-field="name" aria-label="Employee name" list="payroll-employee-options" value="${escape(employee.name)}" maxlength="150" placeholder="Search employee name" ${disabled ? 'disabled' : ''}></td>
            <td><select data-employee-field="payType" aria-label="Pay Type" ${disabled ? 'disabled' : ''}>${['Taxable', 'Non-taxable', 'Minimum Wage Earner'].map(type => `<option ${employee.payType === type ? 'selected' : ''}>${type}</option>`).join('')}</select></td>
            ${api.amountFields.map((field, position) => `<td><input class="employee-amount" data-employee-field="${field}" aria-label="${labels[position]}" type="text" inputmode="decimal" value="${escape(employee[field] ?? '')}" ${disabled ? 'disabled' : ''} ${automatic && [...automation.fields, 'withholding'].includes(field) ? 'readonly aria-readonly="true"' : ''} placeholder="0.00"></td>`).join('')}
            ${additionalFields.map(field => `<td><input class="employee-amount" data-employee-field="${field}" aria-label="${salaryBaseLabels[field]}" inputmode="decimal" value="${escape(employee[field] ?? '')}" ${disabled ? 'disabled' : ''} placeholder="${field === 'taxableBenefits' ? 'Review taxable portion' : 'Monthly salary base'}"></td>`).join('')}
            ${imported ? `<td class="payroll-money">${employee.withholdingReview ? money(employee.withholdingReview.reference) : '—'}</td><td class="payroll-money">${employee.withholdingReview ? money(employee.withholdingReview.difference) : '—'}</td><td title="${escape(employee.withholdingReview?.note || '')}">${escape(employee.withholdingReview?.note || 'No difference / regular payroll')}</td>` : ''}
            <td class="payroll-money" data-employee-net>${net}</td><td>${disabled ? '—' : button('remove-employee', 'Remove', 'person_remove')}</td></tr>`;
    }
    function updateToolbar() {
        const periodRuns = records.filter(run => window.WorkingPeriod.matches(run.date));
        document.querySelectorAll('[data-count]').forEach(node => { node.textContent = `(${periodRuns.filter(run => node.dataset.count === 'all' || run.status === node.dataset.count).length})`; });
        element('payroll-tab-count').textContent = periodRuns.length;
        element('payroll-post-label').textContent = `Post (${candidates().length})`;
        const current = visible();
        element('payroll-select-all').checked = current.length > 0 && current.every(run => selected.has(run.id));
        element('payroll-select-all').indeterminate = current.some(run => selected.has(run.id)) && !element('payroll-select-all').checked;
    }
    function add(template) {
        const pending = records.find(run => run.status === 'draft');
        if (pending) {
            window.WorkingPeriod.revealDate(pending.date); status = 'all'; element('payroll-search').value = ''; element('sales-global-search').value = '';
            document.querySelectorAll('[data-payroll-filter]').forEach(node => node.setAttribute('aria-pressed', node.dataset.payrollFilter === 'all'));
            render(); body.querySelector(`[data-run="${pending.id}"]`)?.scrollIntoView({block: 'nearest'});
            announce(`Continue ${pending.number}. Only one draft is allowed per page.`); return;
        }
        const range = window.WorkingPeriod.current();
        let run = api.createRun(records, {date: window.WorkingPeriod.entryDate(), ...range});
        run = {...run, frequency: preferences.payroll_frequency || 'monthly', automation: Boolean(preferences.payroll_automation)};
        run.contributionShare = {monthly: '100', semi_monthly: '50', weekly: '25'}[run.frequency];
        if (template) run = {...run, name: template.name, employees: clone(template.employees), frequency: template.frequency || run.frequency, automation: Boolean(template.automation), contributionShare: template.contributionShare || run.contributionShare, draftTouched: true};
        run = calculateDraft(run);
        if (!store([...records, run])) return;
        status = 'all'; element('payroll-search').value = ''; element('sales-global-search').value = '';
        document.querySelectorAll('[data-payroll-filter]').forEach(node => node.setAttribute('aria-pressed', node.dataset.payrollFilter === 'all'));
        render(); announce(template ? 'Run duplicated as a draft.' : 'New run ready. Details save as a draft when entered.');
    }
    function post(ids) {
        try {
            let next = clone(records);
            for (const id of ids) {
                const index = next.findIndex(item => item.id === id && item.status === 'draft');
                if (index < 0) continue;
                next[index] = calculateDraft(next[index], next);
                next = api.post(next, [id]);
            }
            if (store(next)) { selected.clear(); render(); announce('Payroll posted. Employee amounts are now locked.'); }
        } catch (exception) { announce(exception.message); }
    }
    function openRemittance(run) {
        try { remittanceDialog.open(run.id); }
        catch (exception) { showInfo('Unable to remit payroll', exception.message); }
    }
    function showInfo(title, description) {
        voidAction = null; element('payroll-dialog-title').textContent = title; element('payroll-dialog-description').textContent = description;
        element('payroll-dialog-error').textContent = ''; element('payroll-confirm').hidden = true; element('payroll-reason-label').hidden = true;
        if (!element('payroll-info').open) element('payroll-info').showModal();
    }
    function voidRuns(runs) {
        if (!runs.length) { announce('Select posted payroll runs to void.'); return; }
        try { for (const run of runs) window.PayrollRemittance.assertUnremitted(run.id, window.KontaduryaLedger); }
        catch (exception) { showInfo('Void the remittance first', exception.message); return; }
        showInfo('Void payroll transaction?', `${runs.map(run => run.number).join(', ')} will be marked void. Their employee breakdown will remain available.`);
        element('payroll-reason').value = ''; element('payroll-reason-label').hidden = false; element('payroll-confirm').hidden = false;
        element('payroll-confirm').textContent = 'Confirm void';
        voidAction = () => {
            const reason = element('payroll-reason').value.trim();
            if (!reason) { element('payroll-dialog-error').textContent = 'Enter a reason for voiding.'; return; }
            if (!store(records.map(run => runs.some(item => item.id === run.id) && run.status === 'posted' ? {...run, status: 'void', voidReason: reason, voidedAt: new Date().toISOString()} : run))) return;
            selected.clear(); render(); element('payroll-info').close(); announce('Selected payroll runs voided.');
        };
    }
    function deleteRuns(runs) {
        const ids = runs.filter(run => run.status !== 'posted').map(run => run.id);
        if (!ids.length) { announce('Select matching drafts or void runs to delete. Posted runs must be voided first.'); return; }
        window.DraftLeaveGuard.confirmDelete(() => {
            if (!store(records.filter(run => !ids.includes(run.id)))) return false;
            ids.forEach(id => selected.delete(id)); render(); announce('Entries deleted; transaction numbers updated.');
        }, runs.map(run => run.number).join(', '), {title: 'Delete payroll entries?', description: 'Delete these selected entries? This cannot be undone.'});
    }
    body.addEventListener('input', event => {
        const field = event.target;
        if (!field.dataset.field && !field.dataset.employeeField) return;
        const row = field.closest('[data-run]');
        const next = clone(records), run = next.find(item => item.id === row.dataset.run);
        if (!run || run.status !== 'draft') return;
        if (field.dataset.employeeField) {
            const index = Number(field.closest('[data-employee]').dataset.employee);
            run.employees[index][field.dataset.employeeField] = field.value;
        } else {
            run[field.dataset.field] = field.dataset.field === 'automation' ? field.value === '1' : field.value;
            if (field.dataset.field === 'frequency') run.contributionShare = {monthly: '100', semi_monthly: '50', weekly: '25'}[field.value];
        }
        Object.assign(run, calculateDraft(run, next));
        run.draftTouched = true;
        if (!store(next)) { render(); return; }
        if (['frequency', 'automation', 'contributionShare'].includes(field.dataset.field)) { render(); return; }
        const saved = records.find(item => item.id === run.id);
        const master = [...body.children].find(node => node.dataset.run === run.id && !node.classList.contains('payroll-detail'));
        const detail = master.nextElementSibling;
        detail.querySelector('[data-calculation-error]').textContent = saved.automationError || '';
        if (saved.automation) detail.querySelectorAll('[data-employee]').forEach(employeeRow => {
            const employee = saved.employees[Number(employeeRow.dataset.employee)];
            for (const name of [...automation.fields, 'withholding']) employeeRow.querySelector(`[data-employee-field="${name}"]`).value = employee[name] || '';
        });
        try {
            const total = api.runTotals(saved);
            master.querySelectorAll('[data-run-total]').forEach(node => { node.textContent = money(total[node.dataset.runTotal]); });
        } catch (exception) { master.querySelectorAll('[data-run-total]').forEach(node => { node.textContent = '—'; }); }
        detail.querySelector('[data-totals]').innerHTML = totalsMarkup(saved);
        detail.querySelectorAll('[data-employee]').forEach(node => {
            try { node.querySelector('[data-employee-net]').textContent = money(api.totals(saved.employees[Number(node.dataset.employee)]).net); }
            catch (exception) { node.querySelector('[data-employee-net]').textContent = '—'; }
        });
        const error = api.error(saved); master.querySelector('[data-flag]').textContent = error ? 'Review' : '—'; master.querySelector('[data-flag]').title = error;
        updateToolbar(); announce('Draft saved in this browser tab.');
    });
    body.addEventListener('change', event => {
        if (!event.target.dataset.select) return;
        const id = event.target.dataset.select;
        if (event.target.checked) selected.add(id); else selected.delete(id);
        updateToolbar();
    });
    body.addEventListener('click', event => {
        const action = event.target.closest('[data-action]');
        if (!action) {
            const row = event.target.closest('[data-run]'), run = records.find(item => item.id === row?.dataset.run);
            if (run && run.status !== 'draft' && event.target.closest('td') && !event.target.closest('input[type=checkbox], button, a, select, label')) details.open(run.id);
            return;
        }
        const run = records.find(item => item.id === action.closest('[data-run]').dataset.run); if (!run) return;
        switch (action.dataset.action) {
            case 'details': details.open(run.id); break;
            case 'more':
                event.stopPropagation();
                entryOptions(run,action);
                break;
            case 'post': post([run.id]); break;
            case 'void': voidRuns([run]); break;
            case 'duplicate': add(run); break;
            case 'delete': deleteRuns([run]); break;
            case 'employee':
            case 'remove-employee': {
                if (run.status !== 'draft') return;
                const next = clone(records), edited = next.find(item => item.id === run.id);
                if (action.dataset.action === 'employee') edited.employees.push({name: '', payType: 'Taxable', ...Object.fromEntries(api.amountFields.map(field => [field, '']))});
                else edited.employees.splice(Number(action.closest('[data-employee]').dataset.employee), 1);
                Object.assign(edited, calculateDraft(edited, next));
                edited.draftTouched = true;
                if (store(next)) render(); break;
            }
        }
    });
    function entryOptions(run,anchor){
        window.SheetEntryActions.open(anchor, run, {
            post: () => post([run.id]),
            edit: () => details.open(run.id),
            editLabel: run.status === 'draft' ? 'Edit entry' : 'View entry',
            duplicate: () => add(run),
            delete: () => deleteRuns([run]),
            deleteVoid: () => deleteRuns([run]),
            void: () => voidRuns([run]),
            view: () => details.open(run.id),
        });
    }
    document.querySelectorAll('[data-payroll-filter]').forEach(node => node.addEventListener('click', () => {
        status = node.dataset.payrollFilter;
        document.querySelectorAll('[data-payroll-filter]').forEach(button => button.setAttribute('aria-pressed', button === node)); render();
    }));
    document.querySelectorAll('[data-payroll-action]').forEach(node => node.addEventListener('click', () => {
        const chosen = visible().filter(run => selected.has(run.id));
        switch (node.dataset.payrollAction) {
            case 'add': add(); break;
            case 'post': post(candidates().map(run => run.id)); break;
            case 'sort': descending = !descending; render(); break;
            case 'bulk-void': voidRuns(chosen.filter(run => run.status === 'posted')); break;
            case 'bulk-delete-draft': deleteRuns(chosen.filter(run => run.status === 'draft')); break;
            case 'bulk-delete-void': deleteRuns(chosen.filter(run => run.status === 'void')); break;
            case 'export': exportCsv(); break;
        }
        node.closest('details')?.removeAttribute('open');
    }));
    function download(content, filename) {
        const url = URL.createObjectURL(new Blob(['\ufeff', content], {type: 'text/csv;charset=utf-8'}));
        const link = document.createElement('a'); link.href = url; link.download = filename; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function exportCsv() {
        const csvCell = value => `"${String(value ?? '').replace(/^\s*[=+@\-\t\r]/, character => `'${character}`).replace(/"/g, '""')}"`;
        const rows = [['Txn No', 'Status', 'Pay Date', 'Run Name', 'Period Start', 'Period End', 'Employee', 'Pay Type', ...labels, 'Net Pay', 'Frequency', 'Calculation', 'Contribution Share', 'Monthly Basic', 'Monthly SSS Compensation', 'Monthly HDMF Fund Salary', 'Taxable Benefits', 'Rate Version', 'Periodic Reference Tax', 'Actual Minus Reference', 'Review Note']];
        try {
            for (const run of visible()) for (const employee of run.employees) rows.push([run.number, run.status, run.date, run.name, run.periodStart, run.periodEnd, employee.name, employee.payType, ...api.amountFields.map(field => (api.cents(employee[field]) / 100).toFixed(2)), (api.totals(employee).net / 100).toFixed(2), run.frequency || 'monthly', run.automation ? 'Automatic' : 'Manual', run.contributionShare ?? '', employee.monthlyBasic ?? '', employee.monthlyCompensation ?? '', employee.monthlyFundSalary ?? '', employee.taxableBenefits ?? '', run.rateVersion || '', employee.withholdingReview ? money(employee.withholdingReview.reference) : '', employee.withholdingReview ? money(employee.withholdingReview.difference) : '', employee.withholdingReview?.note || '']);
            download(rows.map(row => row.map(csvCell).join(',')).join('\r\n'), 'payroll.csv'); announce('Visible payroll runs exported.');
        } catch (exception) { announce('Correct invalid amounts before exporting.'); }
    }
    element('payroll-select-all').addEventListener('change', event => {
        visible().forEach(run => { if (event.target.checked) selected.add(run.id); else selected.delete(run.id); }); render();
    });
    for (const id of ['payroll-search', 'sales-global-search']) element(id).addEventListener('input', render);
    element('payroll-close').addEventListener('click', () => element('payroll-info').close());
    element('payroll-confirm').addEventListener('click', () => voidAction?.());
    document.querySelectorAll('[data-payroll-info]').forEach(node => node.addEventListener('click', event => {
        event.preventDefault();
        if (node.dataset.payrollInfo === 'Import') openImport();
        else showInfo(node.dataset.payrollInfo, node.dataset.payrollInfo === 'Shortcuts' ? 'Alt + N: add or continue a run. Alt + P: post the selected draft or the single visible draft. Escape: close a dialog.' : 'This section is not part of the Payroll register.');
    }));
    document.querySelectorAll('[data-sales-info]').forEach(node => node.addEventListener('click', event => { event.preventDefault(); showInfo(node.textContent.trim(), 'This section is not part of the Payroll register.'); }));
    document.addEventListener('keydown', event => {
        if (!event.altKey || event.ctrlKey || event.metaKey || element('payroll-info').open || element('payroll-entry').open || element('payroll-remittance').open) return;
        if (event.key.toLowerCase() === 'n') { event.preventDefault(); add(); }
        if (event.key.toLowerCase() === 'p') { event.preventDefault(); post(candidates().map(run => run.id)); }
    });
    document.addEventListener('working-period:change', render);
    const mobileMenu = element('sales-menu-toggle'), backdrop = element('sales-backdrop');
    function closeMenu() { document.body.classList.remove('sales-menu-open'); mobileMenu?.setAttribute('aria-expanded', 'false'); if (backdrop) backdrop.hidden = true; }
    mobileMenu?.addEventListener('click', () => {
        const open = document.body.classList.toggle('sales-menu-open'); mobileMenu.setAttribute('aria-expanded', String(open)); if (backdrop) backdrop.hidden = !open;
    });
    backdrop?.addEventListener('click', closeMenu);
    window.matchMedia('(min-width: 1024px)').addEventListener('change', closeMenu);
    new window.DraftLeaveGuard({hasChanges: () => records.some(run => run.status === 'draft' && run.draftTouched), discard: () => {
        if (!store(records.filter(run => run.status !== 'draft'))) throw new Error('Could not discard payroll draft.');
    }});
    function openImport() {
        if(window.PayrollImportUI&&window.PayrollImport){
            payrollImportDialog ||= window.PayrollImportUI.mount({records:()=>records,employees:JSON.parse(element('payroll-import-employees').textContent),config:JSON.parse(element('payroll-import-config').textContent),postRun:async run=>{
                if(!window.CompanyLedger)throw Error('Open a company database book before importing payroll.');
                const next=api.renumber([...records,run],true);
                if(!store(api.post(next,[run.id])))throw Error('The imported payroll could not be staged.');
                await window.CompanyLedger.flush();
                if(window.CompanyLedger.pending())throw Error('Payroll is awaiting database confirmation.');
                window.WorkingPeriod.revealDate(run.date);render();announce('Imported payroll posted using actual client deductions.');
            }});
            payrollImportDialog.open();return;
        }
        showInfo('Import payroll unavailable', 'Reload this page to load the client payroll comparison before importing.');
    }
    element('payroll-info').addEventListener('close', () => { voidAction = null; element('payroll-confirm').textContent = 'Confirm void'; });
    remittanceDialog = window.PayrollRemittanceDialog.mount({
        ledger: window.KontaduryaLedger, catalog: window.WorkspaceAccounts || [], runs: () => records,
        user: element('payroll-entry').dataset.user, defaultBank: preferences.default_bank_code || '',
        onSaved: entry => { details.refresh(); announce(`${entry.number} ${entry.status === 'posted' ? 'posted' : 'saved as a Journal draft'}. View it under Remitted by or in the Journal sheet.`); },
    });
    details = window.PayrollDetails.mount({
        getRun: id => records.find(run => run.id === id),
        remittances: id => window.PayrollRemittance.linked(id, window.KontaduryaLedger),
        canRemit: id => {
            const run = records.find(item => item.id === id);
            return Boolean(run && window.PayrollRemittance.remaining(run, window.KontaduryaLedger).some(item => item.amount > 0));
        },
        update: (id, changes) => {
            const run = records.find(item => item.id === id);
            if (!run) return false;
            const allowed = run.status === 'draft' ? ['date', 'name', 'periodStart', 'periodEnd', 'attachments', 'links'] : ['attachments', 'links'];
            if (Object.keys(changes).some(field => !allowed.includes(field))) return false;
            const edited = {...run, ...changes};
            if (run.status === 'draft') { edited.draftTouched = true; Object.assign(edited, calculateDraft(edited)); }
            if (!store(records.map(item => item.id === id ? edited : item))) return false;
            render();
            announce(run.status === 'draft' ? 'Draft saved. Closing the window keeps this entry.' : 'Payroll documents updated. Posted amounts remain locked.');
            return true;
        },
        action: (action, id) => {
            const run = records.find(item => item.id === id);
            if (!run) return;
            if (action === 'post') post([id]);
            if (action === 'remit') openRemittance(run);
            if (action === 'void') voidRuns([run]);
            if (action === 'delete') deleteRuns([run]);
            if (action === 'duplicate') { details.close(); add(run); }
            if (action === 'edit' && run.status !== 'void') {
                if (window.CompanyLedger && run.status === 'posted') { showInfo('Correct a posted run', 'Posted payroll records are preserved. Void this run and duplicate it to make a correction.'); return; }
                try { window.PayrollRemittance.assertUnremitted(run.id, window.KontaduryaLedger); }
                catch (exception) { showInfo('Void the remittance first', exception.message); return; }
                const edit = () => {
                    try {
                        const next = api.reopenForEditing(records, id);
                        if (!store(next)) return;
                        render(); details.close();
                        const row = body.querySelector(`[data-run="${run.id}"].payroll-detail`);
                        row?.scrollIntoView({block: 'nearest'});
                        row?.querySelector('[data-employee-field="name"]')?.focus();
                        announce(`${run.number} is ready to edit. Changes save as a draft; post it when finished.`);
                        if (element('payroll-info').open) element('payroll-info').close();
                    } catch (exception) { element('payroll-dialog-error').textContent = exception.message; }
                };
                if (run.status === 'draft') edit();
                else {
                    showInfo('Edit posted payroll?', `${run.number} will return to draft with the same transaction number. Its previous posted details are retained in edit history. Edit the employee amounts, then post the run again when finished.`);
                    element('payroll-confirm').hidden = false;
                    element('payroll-confirm').textContent = 'Edit entry';
                    voidAction = edit;
                }
            }
        },
    });
    document.querySelector('#sales-header button')?.addEventListener('click', () => add());
    render();
    document.querySelector('.sheet-tabs [aria-current="page"]')?.scrollIntoView({block: 'nearest', inline: 'nearest'});
})();
