(function (root) {
    'use strict';

    function raw(value) {
        return String(value ?? '').replace(/,/g, '');
    }

    function format(value) {
        const text = raw(value);
        if (!/^\d*(?:\.\d*)?$/.test(text) || !/\d/.test(text)) return String(value ?? '');
        const [whole, fraction] = text.split('.');
        return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === undefined ? '' : '.' + fraction);
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

    const api = {raw, format, position, caret};
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
    root.SheetAmounts = api;

    const selector = 'input[inputmode="decimal"], input[type="number"][step="0.01"]';

    function update(field) {
        if (!field.matches?.(selector)) return;
        if (field.type === 'number') {
            field.type = 'text';
            field.inputMode = 'decimal';
            field.pattern = '(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\\.[0-9]{1,2})?';
        }
        const previous = field.value, next = format(previous);
        if (previous === next) return;
        const active = document.activeElement === field;
        const start = active ? position(previous, field.selectionStart ?? previous.length) : 0;
        const end = active ? position(previous, field.selectionEnd ?? previous.length) : 0;
        field.value = next;
        if (active) field.setSelectionRange(caret(next, start), caret(next, end));
    }

    function refresh() { document.querySelectorAll(selector).forEach(update); }
    api.refresh = refresh;
    for (const event of ['input', 'change', 'focusin', 'focusout']) {
        document.addEventListener(event, action => { if (!action.isComposing) update(action.target); });
    }
    document.addEventListener('keydown', event => {
        const field = event.target;
        if (!field.matches?.(selector) || field.selectionStart !== field.selectionEnd || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const offset = field.selectionStart;
        if (event.key === 'Backspace' && field.value[offset - 1] === ',') field.setSelectionRange(offset - 1, offset - 1);
        if (event.key === 'Delete' && field.value[offset] === ',') field.setSelectionRange(offset + 1, offset + 1);
    });
    document.addEventListener('input', event => {
        const field = event.target;
        if (!field.matches?.(selector) || field.readOnly || field.disabled) return;
        const text = raw(field.value), amount = Number(text);
        let error = '';
        if (text && !/^\d+(?:\.\d{0,2})?$/.test(text)) error = 'Enter an amount with up to two decimal places.';
        else if (text && field.hasAttribute('min') && amount < Number(field.min)) error = 'The minimum amount is ' + format(field.min) + '.';
        else if (text && field.hasAttribute('max') && amount > Number(field.max)) error = 'The maximum amount is ' + format(field.max) + '.';
        field.setCustomValidity(error);
    }, true);
    new MutationObserver(refresh).observe(document.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ['open']});
    refresh();
})(typeof window !== 'undefined' ? window : globalThis);
