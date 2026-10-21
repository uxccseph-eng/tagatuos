const fiscalField = document.querySelector('[data-fiscal-field]');
const fiscalMonth = document.getElementById('fiscal-end-month');
function updateFiscalField() {
    const isFiscal = document.querySelector('[name="accounting_period"]:checked')?.value === 'fiscal';
    fiscalField.hidden = !isFiscal;
    fiscalMonth.required = isFiscal;
    fiscalMonth.disabled = !isFiscal;
}
document.querySelectorAll('[name="accounting_period"]').forEach((input) => input.addEventListener('change', updateFiscalField));
updateFiscalField();
document.getElementById('setup-errors')?.focus();
document.querySelector('[data-company-form]')?.addEventListener('submit', () => {
    const button = document.getElementById('btn-create');
    button.disabled = true;
    button.querySelector('span').textContent = 'Saving setup…';
});
window.addEventListener('pageshow', () => {
    const button = document.getElementById('btn-create');
    button.disabled = false;
    button.querySelector('span').textContent = 'Save company setup';
});

const tinInput = document.getElementById('tin-field'), branchInput = document.getElementById('branch-code');
    if (tinInput) {
        tinInput.addEventListener('input', () => {
            const digits = tinInput.value.replace(/\D/g, '');
            tinInput.value = [digits.slice(0,3), digits.slice(3,6), digits.slice(6,9), digits.slice(9)].filter(Boolean).join('-');
            if (branchInput) branchInput.value = digits.slice(9);
        });
    }
