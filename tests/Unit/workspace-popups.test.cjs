const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function boot(existing = []) {
    let observer;
    const document = {body: {}, querySelector: () => null, querySelectorAll: () => existing};
    vm.runInNewContext(fs.readFileSync('public/js/workspace-popups.js', 'utf8'), {
        document,
        MutationObserver: class {
            constructor(callback) { observer = callback; }
            observe(body, options) { assert.equal(body, document.body); assert.equal(options.subtree, true); assert.equal(options.attributes, undefined); }
        },
    });
    return {insert: (...addedNodes) => observer([{addedNodes}])};
}
function dialog(selectors = []) {
    const classes = new Set();
    return {
        dataset: {}, classList: {add: value => classes.add(value)}, classes,
        matches: selector => selector === 'dialog' || selectors.some(value => selector.split(', ').includes(value)),
        querySelectorAll: () => [],
        open: true, focusTarget: 'existing field',
    };
}

test('existing drawers, imports and previews receive their own layout without closing', () => {
    const entries = [dialog(['#payroll-entry']), dialog(['.sheet-import-dialog']), dialog(['.form2316-dialog']), dialog()];
    boot(entries);
    assert.deepEqual(entries.map(node => node.dataset.popupLayout), ['drawer', 'import', 'preview', 'form']);
    for (const node of entries) {
        assert.equal(node.open, true);
        assert.equal(node.focusTarget, 'existing field');
        assert.equal(node.classes.has('workspace-popup'), true);
    }
});

test('late import and conflict dialogs are styled including dialogs inside inserted wrappers', () => {
    const ui = boot();
    const imported = dialog(['.sheet-import-dialog']), conflict = dialog();
    ui.insert(imported, {querySelectorAll: () => [conflict]}, {nodeType: 3});
    assert.equal(imported.dataset.popupLayout, 'import');
    assert.equal(conflict.dataset.popupLayout, 'form');
});

test('subsequent content changes do not replace the dialog or its fields', () => {
    const entry = dialog(['.cwt-entry']);
    const ui = boot([entry]);
    const original = entry.dataset;
    ui.insert({querySelectorAll: () => []});
    assert.equal(entry.dataset, original);
    assert.equal(entry.focusTarget, 'existing field');
});
