document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('settings-form');
    if (!form) return;
    const tinInput = form.elements.tin, branchInput = form.elements.branch_code;
    if (tinInput) {
        tinInput.addEventListener('input', () => {
            const digits = tinInput.value.replace(/\D/g, '');
            tinInput.value = [digits.slice(0,3), digits.slice(3,6), digits.slice(6,9), digits.slice(9)].filter(Boolean).join('-');
            if (branchInput) branchInput.value = digits.slice(9);
        });
    }
    const serialize = () => JSON.stringify(Array.from(new FormData(form)).filter(([key]) => !['_token', '_method'].includes(key)));
    let initial = serialize();
    const guard = new DraftLeaveGuard({hasChanges: () => serialize() !== initial, discard: () => form.reset()});
    guard.dialog.querySelector('h2').textContent = 'Discard unsaved settings?';
    guard.dialog.querySelector('p').textContent = 'Your changes have not been saved. Stay to continue editing, or discard your changes and leave.';
    const updateAddress = () => {
        const output = document.getElementById('settings-address-preview');
        if (output) output.textContent = Array.from(form.querySelectorAll('[data-address-part]')).map(input => input.value.trim()).filter(Boolean).join(', ') || 'Enter your registered address above.';
    };
    const updateFiscal = () => {
        const period = form.elements.accounting_period, month = form.elements.fiscal_end_month;
        if (period && month) {month.disabled = period.value !== 'fiscal'; month.required = !month.disabled;}
    };
    const update = () => {
        updateFiscal();
        document.getElementById('settings-save-state').textContent = serialize() !== initial ? 'You have unsaved changes.' : 'Changes apply when you save.';
        updateAddress();
    };
    form.addEventListener('input', update); form.addEventListener('change', update);
    form.addEventListener('submit', () => {guard.leaving = true;});
    document.querySelectorAll('[data-discard-settings]').forEach(button => button.addEventListener('click', () => guard.confirm(() => {form.reset(); update();})));
    document.querySelector('[data-copy-address]')?.addEventListener('click', async () => {
        const address = Array.from(form.querySelectorAll('[data-address-part]')).map(input => input.value.trim()).filter(Boolean).join(', ');
        const status = document.getElementById('settings-copy-status');
        if (!address) {status.textContent = 'Enter an address first.'; return;}
        try {await navigator.clipboard.writeText(address); status.textContent = 'Address copied.';} catch {status.textContent = 'Select the address above to copy it.';}
    });
    document.getElementById('settings-menu')?.addEventListener('click', () => document.body.classList.toggle('settings-menu-open'));
    document.addEventListener('click', event => {if (document.body.classList.contains('settings-menu-open') && !event.target.closest('#sales-sidebar, #settings-menu')) document.body.classList.remove('settings-menu-open');});
    updateAddress(); updateFiscal(); initial = serialize();
});
