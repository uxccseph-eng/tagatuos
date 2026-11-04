(function (root) {
    'use strict';

    function raw(value) {
        return String(value ?? '').trim().replace(/,/g, '');
    }

    function format(value) {
        const text = raw(value);
        if (text === '') return '';
        if (!/^\d*(?:\.\d*)?$/.test(text) || !/\d/.test(text)) return String(value ?? '');
        const [whole, fraction] = text.split('.');
        return (whole || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === undefined ? '' : '.' + fraction);
    }

    function error(value, minimum = null, maximum = null) {
        const text = raw(value), amount = Number(text);
        if (text && !/^\d+(?:\.\d{0,2})?$/.test(text)) return 'Enter an amount with up to two decimal places.';
        if (text && minimum !== null && amount < Number(minimum)) return 'The minimum amount is ' + format(minimum) + '.';
        if (text && maximum !== null && amount > Number(maximum)) return 'The maximum amount is ' + format(maximum) + '.';
        return '';
    }

    function position(value, offset) {
        return raw(value.slice(0, offset)).length;
    }

    function caret(value, offset) {
        let digits = 0;
        for (let index = 0; index < value.length; index++) {
            if (digits === offset) return index;
            if (value[index] !== ',') digits++;
        }
        return value.length;
    }

    const api = {raw, format, position, caret, error};
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
    root.SheetAmounts = api;

    const selector = 'input[inputmode="decimal"], input[type="number"][step="0.01"]';
    const validationMessages = new WeakMap();

    function update(field, finalize = false) {
        if (!field.matches?.(selector)) return;
        if (field.type === 'number') {
            field.type = 'text';
            field.inputMode = 'decimal';
            field.pattern = '(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\\.[0-9]{1,2})?';
        }
        const previous = field.value;
        let next = format(previous);
        if (finalize && /^\d[\d,]*\.$/.test(next)) next += '00';
        if (previous !== next) {
            const active = document.activeElement === field;
            const leadingZero = raw(previous).startsWith('.') && next.startsWith('0.') ? 1 : 0;
            const cursor = offset => { const logical = position(previous, offset); return logical + (logical > 0 ? leadingZero : 0); };
            const start = active ? cursor(field.selectionStart ?? previous.length) : 0;
            const end = active ? cursor(field.selectionEnd ?? previous.length) : 0;
            field.value = next;
            if (active) field.setSelectionRange(caret(next, start), caret(next, end));
        }
        const previousError = validationMessages.get(field);
        const currentError = field.validity.customError ? field.validationMessage : '';
        if (currentError && currentError !== previousError) return;
        const message = field.readOnly || field.disabled ? '' : error(field.value, field.hasAttribute('min') ? field.min : null, field.hasAttribute('max') ? field.max : null);
        field.setCustomValidity(message);
        if (message) validationMessages.set(field, message);
        else validationMessages.delete(field);
    }

    function refresh() { document.querySelectorAll(selector).forEach(field => update(field)); }
    api.refresh = refresh;
    for (const event of ['input', 'change', 'focusin', 'focusout']) {
        const handle = action => { if (!action.isComposing) update(action.target, event === 'change' || event === 'focusout'); };
        document.addEventListener(event, handle, true);
        document.addEventListener(event, handle);
    }
    function validateForm(form) { form?.querySelectorAll(selector).forEach(field => update(field, true)); }
    api.validateForm = validateForm;
    document.addEventListener('click', event => {
        const button = event.target.closest?.('button, input[type="submit"], input[type="image"]');
        if (button?.form && ['submit', 'image'].includes(button.type)) validateForm(button.form);
    }, true);
    document.addEventListener('keydown', event => {
        const field = event.target;
        if (event.key === 'Enter' && field.tagName !== 'TEXTAREA') validateForm(field.form);
        if (!field.matches?.(selector) || field.selectionStart !== field.selectionEnd || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const offset = field.selectionStart;
        if (event.key === 'Backspace' && field.value[offset - 1] === ',') field.setSelectionRange(offset - 1, offset - 1);
        if (event.key === 'Delete' && field.value[offset] === ',') field.setSelectionRange(offset + 1, offset + 1);
    });
    new MutationObserver(refresh).observe(document.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['open']});
    refresh();
})(typeof window !== 'undefined' ? window : globalThis);
