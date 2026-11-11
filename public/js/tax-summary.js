(() => {
    'use strict';
    const money = value => value == null ? '—' : new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value / 100);
    function initialize() {
        const root = document.querySelector('.vat-summary');
        const boot = document.getElementById('vat-workspace-data');
        if (!root || !boot || root.dataset.vatReady) return;
        root.dataset.vatReady = '1';
        const data = JSON.parse(boot.textContent), dialog = root.querySelector('#vat-action-dialog'), form = root.querySelector('#vat-action-form');
        const fields = root.querySelector('#vat-action-fields'), output = root.querySelector('#vat-action-preview'), error = root.querySelector('#vat-action-error');
        const confirm = root.querySelector('#vat-confirm-button'), review = root.querySelector('#vat-preview-button');
        let action, requestId, preview, busy = false, submitted = false;
        const node = (tag, text, parent) => {const el = document.createElement(tag); if (text != null) el.textContent = text; if (parent) parent.append(el); return el;};
        const headers = () => ({'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-Company-ID': String(data.companyId)});
        async function json(url, options = {}) {
            const response = await fetch(url, {...options, headers: {...headers(), ...options.headers}, cache: 'no-store'});
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The request failed. Your entries are still in this dialog.');
            return result;
        }
        function field(name, label, options = {}) {
            const wrapper = node('label', null, fields); node('span', label, wrapper);
            const el = node(options.choices ? 'select' : options.type === 'textarea' ? 'textarea' : 'input', null, wrapper);
            el.name = name; el.setAttribute('aria-label', label);
            if (options.choices) for (const [value, text] of options.choices) {const opt = node('option', text, el); opt.value = value;}
            else {if (el.tagName === 'INPUT') el.type = options.type || 'text'; if (options.money) {el.inputMode = 'decimal'; el.pattern = '[0-9,]+([.][0-9]{1,2})?'; el.placeholder = '0.00';}}
            if (options.value != null) el.value = options.value;
            if (options.required) el.required = true;
            if (options.max) el.max = options.max;
            if (options.hint) node('small', options.hint, wrapper);
            return el;
        }
        function account(name, label, types, fallback, extra = false) {
            const choices = data.accounts.filter(a => types.includes(a.type) && (!extra || a.is_cash_bank)).map(a => [a.code, a.code + ' · ' + a.name]);
            if (fallback && !data.accounts.some(a => a.code === fallback)) choices.push([fallback, fallback + ' · ' + (fallback === '2205' ? 'VAT Payable - Net of Input Tax' : 'Penalties & Surcharges') + ' (create on posting)']);
            return field(name, label, {choices: [['', 'Select account…'], ...choices], value: fallback || '', required: true});
        }
        function invalidate() {
            preview = null; requestId = crypto.randomUUID(); output.hidden = true; confirm.hidden = true; review.hidden = false; error.textContent = '';
        }
        root.querySelectorAll('[data-vat-action]').forEach(button => button.addEventListener('click', () => {
            if (busy) return;
            action = button.dataset.vatAction; fields.replaceChildren(); output.replaceChildren(); submitted = false; invalidate();
            root.querySelector('#vat-action-title').textContent = {credit: 'Record opening input VAT', reverse: 'Reverse opening credit', payment: 'Tag VAT payment', carryover: 'Carry excess input VAT forward', close: 'Close quarterly VAT'}[action];
            root.querySelector('#vat-action-intro').textContent = action === 'credit' ? 'Opening credit for reporting year ' + data.year + '. Enter 0.00 to confirm there is no opening input VAT. No journal will be posted.' : action === 'carryover' ? 'Review the confirmed year-end excess input VAT before recording it as next year’s opening balance.' : 'Review the details below. Nothing is recorded until you confirm the preview.';
            if (['payment', 'close'].includes(action)) {
                field('quarter', 'Reporting quarter', {choices: [1,2,3,4].map(q => [q, 'Q' + q + ' ' + data.year]), value: button.dataset.quarter || '1', required: true});
                field('date', action === 'close' ? 'Close date' : 'Payment date', {type: 'date', value: data.today, max: data.today, required: true});
            }
            if (action === 'reverse') {
                field('credit_id', 'Credit to reverse', {choices: [['', 'Choose an opening credit…'], ...data.credits.filter(c => Number(c.year) === Number(data.year) && c.remaining > 0).map(c => [c.id, '#' + c.id + ' · ' + money(c.remaining) + ' remaining'])], required: true});
            }
            if (['credit', 'reverse', 'payment'].includes(action)) field('amount', action === 'reverse' ? 'Amount to reverse' : 'Amount', {money: true, required: true});
            if (action === 'payment') {
                field('penalty', 'Included penalties', {money: true, value: '0.00', hint: 'Part of the payment amount, not an extra amount or tax credit.'});
                field('method', 'Payment method', {choices: [['', 'Not specified'], ...['Cash','Check','eFPS','GCash','Tax Debit Memo','Other'].map(v => [v,v])]});
                field('reference', 'Payment reference', {hint: 'Bank / eFPS confirmation. Check the period to avoid tagging the same payment twice.'});
                const post = field('post_now', 'Post the payment journal now', {type: 'checkbox'});
                const bank = account('bank', 'Paid from', ['Asset'], null, true); bank.required = false; bank.closest('label').hidden = true;
                const linked = field('linked_source', 'Existing journal (optional)', {choices: [['', 'Tag only · no linked entry'], ...data.journals.map(j => [j.source_key, j.number + ' · ' + j.transaction_date])]});
                post.addEventListener('change', () => {bank.closest('label').hidden = !post.checked; bank.required = post.checked; linked.closest('label').hidden = post.checked; if(post.checked) linked.value = ''; else bank.value = '';});
                account('net_account', 'Net VAT payable account', ['Liability'], '2205');
                account('penalty_account', 'Penalty expense account', ['Expense'], '7290');
            }
            if (action === 'close') {
                account('output_account', 'Output VAT account', ['Liability'], '2210');
                account('input_account', 'Input VAT account', ['Asset'], '2110');
                account('net_account', 'Net VAT payable account', ['Liability'], '2205');
                const credit = account('vat_credit_account', 'VAT-withheld asset account (optional)', ['Asset']); credit.required = false;
                node('p', 'Choose a dedicated VAT-withheld asset only. Do not select an income-tax CWT account. Default accounts marked “create on posting” are created only after confirmation.', fields);
            }
            field('remarks', 'Remarks', {type: 'textarea'}).maxLength = 1000;
            dialog.showModal();
        }));
        fields.addEventListener('input', invalidate);
        fields.addEventListener('change', invalidate);
        fields.addEventListener('focusout', event => {if (event.target.inputMode === 'decimal' && event.target.value.trim()) {const raw = event.target.value.replaceAll(',', ''); if (/^\d+(?:\.\d{1,2})?$/.test(raw)) event.target.value = new Intl.NumberFormat('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(raw));}});
        root.querySelectorAll('[data-vat-dismiss]').forEach(button => button.addEventListener('click', () => {if (!busy) dialog.close();}));
        dialog.addEventListener('cancel', event => {if (busy) event.preventDefault();});
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (busy || !form.reportValidity()) return;
            busy = true; review.disabled = true; error.textContent = '';
            try {
                const input = {action, year: data.year, request_id: requestId};
                for (const [key, value] of new FormData(form)) {if (String(value).trim()) input[key] = ['amount','penalty'].includes(key) ? String(value).replaceAll(',', '') : value;}
                if (action === 'payment') input.post_now = form.elements.post_now.checked;
                const previewRequestId = requestId;
                const result = await json(data.previewUrl, {method: 'POST', body: JSON.stringify(input)});
                if (requestId !== previewRequestId) return;
                preview = {input, hash: result.preview_hash}; output.replaceChildren();
                node('h3', 'Preview · ' + money(result.amount), output);
                result.warnings.forEach(message => node('p', message, output));
                if (result.lines.length) {
                    const table = node('table', null, output), head = node('tr', null, table); ['Account','Debit','Credit'].forEach(text => node('th',text,head));
                    for (const line of result.lines) {const row = node('tr', null, table); [line.account + ' · ' + line.name, money(line.debit), money(line.credit)].forEach(text => node('td',text,row));}
                    const debits = result.lines.reduce((n,l) => n+l.debit,0), credits = result.lines.reduce((n,l) => n+l.credit,0);
                    node('p', 'Total debit ' + money(debits) + ' · Total credit ' + money(credits), output);
                }
                output.hidden = false; confirm.hidden = false; review.hidden = true;
            } catch (problem) {preview = null; error.textContent = problem.message;}
            finally {busy = false; review.disabled = false;}
        });
        confirm.addEventListener('click', async () => {
            if (!preview || busy || submitted) return;
            busy = true; confirm.disabled = true; error.textContent = '';
            fields.querySelectorAll('input,select,textarea').forEach(control => {control.disabled = true;});
            try {
                await json(data.storeUrl, {method: 'POST', body: JSON.stringify({...preview.input, preview_hash: preview.hash, confirmed: true})});
                submitted = true; confirm.hidden = true; review.hidden = true;
                node('p', 'Recorded successfully. Updating the summary…', output);
                const response = await fetch(location.href, {headers: {'X-Company-ID': String(data.companyId)}, cache: 'no-store'});
                if (!response.ok) throw new Error('Recorded successfully. Close this dialog and refresh the summary when your connection is restored. Do not record it again.');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html'), fresh = page.querySelector('.vat-summary');
                if (!fresh) throw new Error('Recorded successfully. Reopen the summary to see the latest balances.');
                const heading = root.querySelector('.vat-heading');
                if (heading) fresh.querySelector('.vat-heading')?.replaceWith(heading);
                dialog.close(); root.replaceWith(fresh); initialize();
            } catch (problem) {error.textContent = problem.message; if (!submitted) confirm.disabled = false;}
            finally {busy = false; if (root.isConnected) fields.querySelectorAll('input,select,textarea').forEach(control => {control.disabled = false;});}
        });
        const sourceDialog = root.querySelector('#vat-source-dialog'), sourceBody = root.querySelector('#vat-source-body');
        root.querySelector('[data-vat-source-dismiss]').addEventListener('click', () => sourceDialog.close());
        root.querySelectorAll('[data-vat-source]').forEach(link => link.addEventListener('click', async event => {
            event.preventDefault(); root.querySelector('#vat-source-title').textContent = link.dataset.label + ' · Q' + link.dataset.quarter;
            sourceBody.replaceChildren(); node('p','Loading posted source transactions…',sourceBody); sourceDialog.showModal();
            try {
                const url = new URL(data.sourcesUrl,location.href); url.searchParams.set('year',data.year); url.searchParams.set('quarter',link.dataset.quarter); url.searchParams.set('key',link.dataset.vatSource);
                const result = await json(url); sourceBody.replaceChildren();
                const scroll=node('div',null,sourceBody); scroll.className='vat-scroll'; const table=node('table',null,scroll),head=node('tr',null,table);
                ['Transaction','Date','Party','Gross','Contribution'].forEach(text=>node('th',text,head));
                result.rows.forEach(item=>{const row=node('tr',null,table),cell=node('td',null,row),anchor=node('a',item.number,cell);anchor.href=item.url;[item.date,item.party,money(item.gross),money(item.amount)].forEach(text=>node('td',text,row));});
                if (!result.rows.length) node('p','No matching posted sources in this period.',sourceBody);
                node('p','Total contribution: '+money(result.total),sourceBody);
            } catch(problem) {sourceBody.replaceChildren();node('p',problem.message,sourceBody);}
        }));
    }
    initialize();
})();
