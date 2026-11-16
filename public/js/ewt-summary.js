(() => {
    'use strict';
    const configElement = document.getElementById('ewt-workspace-data');
    if (!configElement) return;
    const config = JSON.parse(configElement.textContent);
    const dialog = document.getElementById('ewt-payment-dialog');
    const form = document.getElementById('ewt-payment-form');
    const confirm = document.getElementById('ewt-confirm');
    const review = document.getElementById('ewt-review');
    const previewBox = document.getElementById('ewt-preview');
    const error = document.getElementById('ewt-payment-error');
    let prepared = null;
    let busy = false;
    const money = cents => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(cents / 100);
    const invalidate = () => { prepared = null; confirm.hidden = true; previewBox.hidden = true; error.textContent = ''; };
    const updatePeriods = () => {
        const monthly = form.elements.form.value === '0619-E';
        const options = [];
        for (let i = 1; i <= (monthly ? 12 : 4); i++) {
            if (monthly && i % 3 === 0) continue;
            options.push(new Option(monthly ? new Date(2026, i - 1, 1).toLocaleString('en', {month: 'long'}) : `Q${i}`, String(i)));
        }
        form.elements.period.replaceChildren(...options);
    };
    const updatePosting = () => {
        const post = form.elements.post_now.checked;
        document.getElementById('ewt-bank-field').hidden = !post;
        document.getElementById('ewt-link-field').hidden = post;
        form.elements.bank.required = post;
        document.getElementById('ewt-payable-field').hidden = !post && !form.elements.linked_source.value;
        document.getElementById('ewt-penalty-field').hidden = (!post && !form.elements.linked_source.value) || Number(form.elements.penalty.value.replaceAll(',', '')) <= 0;
        if (post) form.elements.linked_source.value = '';
        else form.elements.bank.value = '';
    };
    const send = async (url, payload) => {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(payload)});
        let data;
        try { data = await response.json(); } catch { throw new Error('Unable to read the server response. Check your connection and try again.'); }
        if (!response.ok) { const exception = new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to record this payment.'); exception.status = response.status; throw exception; }
        return data;
    };
    const setBusy = value => {
        busy = value;
        form.querySelectorAll('input,select,button').forEach(control => { control.disabled = value; });
    };
    document.getElementById('ewt-tag-payment').addEventListener('click', () => { form.reset(); invalidate(); updatePeriods(); updatePosting(); dialog.showModal(); });
    dialog.querySelectorAll('[data-ewt-close]').forEach(button => button.addEventListener('click', () => { if (!busy) dialog.close(); }));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    form.addEventListener('input', () => { invalidate(); updatePosting(); });
    form.querySelectorAll('[data-ewt-money]').forEach(input => input.addEventListener('blur', () => { const value = input.value.replaceAll(',', ''); if (/^[0-9]+(?:\.[0-9]{1,2})?$/.test(value)) input.value = Number(value).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }));
    form.addEventListener('change', event => {
        invalidate();
        if (event.target.name === 'form') updatePeriods();
        updatePosting();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        invalidate();
        const payload = Object.fromEntries(new FormData(form));
        payload.amount = payload.amount.replaceAll(',', ''); payload.penalty = payload.penalty.replaceAll(',', '');
        payload.action = 'payment'; payload.year = config.year; payload.period = Number(payload.period);
        payload.post_now = form.elements.post_now.checked;
        payload.request_id = crypto.randomUUID();
        setBusy(true);
        try {
            const result = await send(config.previewUrl, payload);
            prepared = {...payload, preview_hash: result.preview_hash, confirmed: true};
            const messages = [`Total paid: ${money(result.amount)}`, ...result.warnings];
            for (const line of result.lines) messages.push(`${line.account} ${line.name}: Debit ${money(line.debit)}, Credit ${money(line.credit)}`);
            previewBox.replaceChildren(...messages.map(message => { const p = document.createElement('p'); p.textContent = message; return p; }));
            previewBox.hidden = false; confirm.hidden = false;
        } catch (exception) { error.textContent = exception.message; }
        finally { setBusy(false); }
    });
    confirm.addEventListener('click', async () => {
        if (!prepared || busy) return;
        setBusy(true); error.textContent = '';
        try {
            await send(config.storeUrl, prepared);
            window.location.reload();
        } catch (exception) { if (exception.status === 409) invalidate(); error.textContent = exception.message; setBusy(false); }
    });
})();
