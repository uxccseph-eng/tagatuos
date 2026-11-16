const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function boot({sheet = true, initialized = true} = {}) {
    class Element {
        constructor() { this.children = []; this.attributes = {}; this.dataset = {}; this.textContent = ''; this.listeners = {}; }
        append(...nodes) { this.children.push(...nodes); }
        setAttribute(name, value) { this.attributes[name] = value; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        click() { this.listeners.click(); }
    }
    const main = new Element(), toolbar = new Element(), body = new Element();
    main.append(toolbar);
    main.querySelector = selector => sheet && selector === '.sheet-toolbar' ? toolbar : null;
    toolbar.after = node => main.children.splice(1, 0, node);
    const database = {initialized, revision: 3, save_info: {revision: 3, company_name: 'Client book', saved_at: '2026-11-17T01:30:00+00:00', saved_by: 'Other accountant'}};
    const window = {};
    vm.runInNewContext(fs.readFileSync('public/js/company-save-status.js', 'utf8'), {window, document: {body, createElement: () => new Element(), querySelector: () => main}});
    const view = window.CompanySaveStatus.create(database);
    const update = values => view.update({state: 'saved', message: 'Database saved', revision: 3, initialized, saving: false, ...values});
    const toggle = view.banner.children[2], panel = view.banner.children[3];
    return {view, database, main, toolbar, body, toggle, panel, update};
}

test('status mounts immediately below the sheet toolbar without a body overlay', () => {
    const ui = boot(); ui.update();
    assert.equal(ui.main.children[1], ui.view.banner);
    assert.equal(ui.body.children.length, 0);
    assert.equal(ui.view.label.textContent, 'Saved to company database');
    assert.equal(ui.view.label.attributes['aria-live'], 'polite');
    assert.equal(ui.view.actions.hidden, true);
});

test('unsaved, saving, error and recovery states never claim a confirmed save', () => {
    const ui = boot({initialized: false}); ui.update();
    assert.equal(ui.view.banner.dataset.state, 'ready');
    assert.match(ui.view.label.textContent, /No sheet transactions saved yet/);
    ui.update({state: 'pending', saving: true});
    assert.equal(ui.view.label.textContent, 'Saving changes…');
    ui.update({state: 'error', message: 'Connection unavailable. Retry saving.'});
    assert.equal(ui.view.label.textContent, 'Connection unavailable. Retry saving.');
    ui.update({state: 'pending', saving: true, recoveryUnavailable: true, message: 'Review conflicting entries'});
    assert.equal(ui.view.label.textContent, 'Review conflicting entries');
});

test('details show authoritative metadata and preserve disclosure nodes on updates', () => {
    const ui = boot(); ui.update(); ui.toggle.click();
    assert.equal(ui.toggle.attributes['aria-expanded'], 'true');
    assert.equal(ui.panel.hidden, false);
    const list = ui.panel.children[0], technical = ui.panel.children[2];
    assert.equal(list.children[3].textContent, 'Client book');
    assert.equal(list.children[9].textContent, 'Other accountant');
    assert.match(list.children[7].textContent, /Nov 17, 2026/);
    technical.open = true;
    ui.update({revision: 4});
    assert.equal(ui.panel.children[2], technical);
    assert.equal(technical.open, true);
    assert.equal(list.children[9].textContent, 'Not available for this saved version');
    assert.equal(list.children[7].textContent, 'Not available for this saved version');
    ui.toggle.click();
    assert.equal(ui.panel.hidden, true);
});

test('other pages identify sheet records rather than implying tax returns are saved', () => {
    const ui = boot({sheet: false}); ui.update();
    assert.equal(ui.main.children.at(-1), ui.view.banner);
    assert.equal(ui.view.label.textContent, 'Sheet records saved to company database');
});
