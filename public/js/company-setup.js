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
            const digits = tinInput.value.replace(/\D/g, '').slice(0, 9);
            tinInput.value = [digits.slice(0,3), digits.slice(3,6), digits.slice(6,9)].filter(Boolean).join('-');
        });
    }

if (branchInput) {
    branchInput.addEventListener('input', () => {
        branchInput.value = branchInput.value.replace(/\D/g, '').slice(0, 5);
    });
}

const rdoSearch = document.getElementById('rdo-search'), rdoSelect = document.getElementById('rdo-select');
const rdoTrigger = document.getElementById('rdo-trigger'), rdoPicker = document.getElementById('rdo-picker');
if (rdoSearch && rdoSelect && rdoTrigger && rdoPicker) {
    const offices = Array.from(rdoSelect.options).map(option => ({value: option.value, label: option.textContent.trim()}));
    const list = document.getElementById('rdo-options');
    let matches = [], active = -1;
    function highlight(index) {
        active = index;
        const options = Array.from(list.children);
        options.forEach((option, position) => option.classList.toggle('rdo-active', position === active));
        if (options[active]) {
            rdoSearch.setAttribute('aria-activedescendant', options[active].id);
            options[active].scrollIntoView({block: 'nearest'});
        } else rdoSearch.removeAttribute('aria-activedescendant');
    }
    function closePicker(returnFocus = false) {
        rdoPicker.hidden = true;
        rdoTrigger.setAttribute('aria-expanded', 'false');
        rdoSearch.setAttribute('aria-expanded', 'false');
        if (returnFocus) rdoTrigger.focus();
    }
    function choose(office) {
        rdoSelect.value = office.value;
        document.getElementById('rdo-selection').textContent = office.label;
        rdoSelect.dispatchEvent(new Event('change', {bubbles: true}));
        closePicker(true);
    }
    function renderOffices() {
        const query = rdoSearch.value.trim().toLocaleLowerCase();
        matches = offices.filter(office => !query || office.value && office.label.toLocaleLowerCase().includes(query));
        list.replaceChildren();
        matches.forEach((office, index) => {
            const option = document.createElement('div');
            option.id = 'rdo-option-' + index;
            option.role = 'option';
            option.setAttribute('aria-selected', String(office.value === rdoSelect.value));
            option.textContent = office.label;
            option.addEventListener('pointerdown', event => event.preventDefault());
            option.addEventListener('click', () => choose(office));
            list.append(option);
        });
        document.getElementById('rdo-result-count').textContent = matches.filter(office => office.value).length + ' matching offices · ' + (offices.length - 1) + ' total';
        highlight(matches.length ? Math.max(0, matches.findIndex(office => office.value === rdoSelect.value)) : -1);
    }
    function openPicker() {
        rdoPicker.hidden = false;
        rdoTrigger.setAttribute('aria-expanded', 'true');
        rdoSearch.setAttribute('aria-expanded', 'true');
        rdoSearch.value = '';
        renderOffices();
        rdoSearch.focus();
    }
    rdoSelect.hidden = true;
    rdoTrigger.hidden = false;
    document.getElementById('rdo-selection').textContent = rdoSelect.selectedOptions[0]?.textContent.trim() || offices[0].label;
    document.getElementById('rdo-field-label').htmlFor = 'rdo-trigger';
    rdoTrigger.addEventListener('click', () => rdoPicker.hidden ? openPicker() : closePicker());
    rdoTrigger.addEventListener('keydown', event => {
        if (['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); openPicker(); }
    });
    rdoSearch.addEventListener('input', renderOffices);
    rdoSearch.addEventListener('keydown', event => {
        if (['ArrowDown', 'ArrowUp'].includes(event.key) && matches.length) {
            event.preventDefault(); highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
        } else if (event.key === 'Enter') {
            event.preventDefault(); if (matches[active]) choose(matches[active]);
        }
    });
    rdoPicker.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closePicker(true); }
    });
    document.addEventListener('pointerdown', event => {
        if (!rdoPicker.hidden && !rdoPicker.contains(event.target) && !rdoTrigger.contains(event.target)) closePicker();
    });
    rdoPicker.parentElement.addEventListener('focusout', event => {
        if (!rdoPicker.parentElement.contains(event.relatedTarget)) closePicker();
    });
}

function updateSecRegistrationFields() {
    const isIndividual = document.querySelector('[name="entity_type"]:checked')?.value === 'individual';
    const fields = document.querySelector('[data-sec-corporate-fields]');
    const notice = document.querySelector('[data-sec-individual-note]');
    if (fields) { fields.disabled = isIndividual; }
    if (notice) { notice.hidden = !isIndividual; }
}
document.querySelectorAll('[name="entity_type"]').forEach(input => input.addEventListener('change', updateSecRegistrationFields));
updateSecRegistrationFields();
