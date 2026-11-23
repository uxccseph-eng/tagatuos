const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/workspace-rail.js', 'utf8');
const key = 'kontadurya.navigation.collapsed';

function boot({store = new Map(), desktop = true, id = 'sales-sidebar', blocked = false} = {}) {
    const classes = new Set(), events = {}, windowEvents = {}, attributes = {};
    const detail = {open: false}, section = {hidden: true};
    const group = {nextElementSibling: section, expanded: 'false', getAttribute: () => group.expanded, setAttribute: (name, value) => { group.expanded = value; }};
    const originalParent = {name: 'header'};
    const brand = {classList: {add() {}}, append(control) { control.parentNode = this; }};
    const icon = {textContent: 'menu'};
    const sidebar = {id, inert: false, querySelector: () => brand, querySelectorAll: selector => selector === 'details' ? [detail] : selector === '[data-section-toggle]' ? [group] : []};
    const toggle = {parentNode: originalParent, before() {}, querySelector: () => icon, classList: {add() {}, remove() {}}, contains: target => target === toggle, setAttribute: (name, value) => { attributes[name] = value; }, removeAttribute: name => { delete attributes[name]; }};
    const body = {classList: {add: name => classes.add(name), remove: name => classes.delete(name), toggle: (name, value) => value ? classes.add(name) : classes.delete(name)}};
    const media = {matches: desktop, addEventListener: (name, listener) => { media.listener = listener; }};
    const document = {body, createComment: () => ({after(control) { control.parentNode = originalParent; }}), querySelector: selector => selector.startsWith('#sales-sidebar') ? sidebar : toggle, addEventListener: (name, listener) => { (events[name] ||= []).push(listener); }};
    const window = {matchMedia: () => media, addEventListener: (name, listener) => { windowEvents[name] = listener; }};
    const localStorage = {getItem: name => { if (blocked) throw Error('Blocked'); return store.get(name); }, setItem: (name, value) => { if (blocked) throw Error('Blocked'); store.set(name, value); }};
    const context = vm.createContext({window, document, localStorage, queueMicrotask: callback => callback()});
    vm.runInContext(source, context);
    return {classes, attributes, sidebar, detail, section, brand, icon, toggle, originalParent, media, store, windowEvents, events, repeat: () => vm.runInContext(source, context), click() {
        const event = {target: toggle, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }};
        events.click.forEach(listener => listener(event)); return event;
    }};
}

test('collapse choice carries between Dashboard, company sheets and firm pages', () => {
    const home = boot({id: 'sidebarNav'});
    assert.equal(home.attributes['aria-expanded'], 'true');
    assert.equal(home.click().stopped, true);
    assert.equal(home.classes.has('sidebar-closed'), false);
    for (const id of ['sales-sidebar', 'workspace-app-sidebar']) {
        const next = boot({id, store: home.store});
        assert.equal(next.attributes['aria-expanded'], 'false');
        assert.equal(next.sidebar.inert, false);
        assert.equal(next.classes.has('workspace-rail-collapsed'), true);
    }
    const firm = boot({id: 'workspace-app-sidebar', store: home.store});
    firm.click();
    assert.equal(boot({store: firm.store}).attributes['aria-expanded'], 'true');
});

test('desktop handler leaves existing mobile menu handlers in control', () => {
    const page = boot({desktop: false});
    const click = page.click();
    assert.equal(click.stopped, false);
    assert.equal(click.prevented, false);
    assert.equal(page.store.has(key), false);
});

test('moving an icon rail to mobile restores the full drawer while preserving the desktop preference', () => {
    const page = boot({store: new Map([[key, 'true']])});
    assert.equal(page.sidebar.inert, false);
    page.media.matches = false; page.media.listener();
    assert.equal(page.sidebar.inert, false);
    assert.equal(page.classes.has('workspace-rail-collapsed'), false);
    page.media.matches = true; page.media.listener();
    assert.equal(page.sidebar.inert, false);
    assert.equal(page.attributes['aria-expanded'], 'false');
});

test('blocked storage still permits collapse and repeated assets do not double toggle', () => {
    const page = boot({blocked: true});
    page.repeat();
    assert.equal(page.events.click.length, 1);
    page.click();
    assert.equal(page.attributes['aria-expanded'], 'false');
    page.click();
    assert.equal(page.attributes['aria-expanded'], 'true');
});

test('another tab can change the shared navigation preference', () => {
    const page = boot();
    page.windowEvents.storage({key, newValue: 'true'});
    assert.equal(page.attributes['aria-expanded'], 'false');
    page.windowEvents.storage({key, newValue: null});
    assert.equal(page.attributes['aria-expanded'], 'true');
});


test('icon mode exposes folded destinations and expanding restores their previous grouping', () => {
    const page = boot();
    assert.equal(page.detail.open, false);
    assert.equal(page.section.hidden, true);
    page.click(); page.repeat();
    assert.equal(page.detail.open, true);
    assert.equal(page.section.hidden, false);
    page.click();
    assert.equal(page.detail.open, false);
    assert.equal(page.section.hidden, true);
});


test('the same toggle moves into the desktop sidebar and returns to the mobile header', () => {
    const page = boot();
    assert.equal(page.toggle.parentNode, page.brand);
    assert.equal(page.icon.textContent, 'chevron_left');
    page.click();
    assert.equal(page.icon.textContent, 'chevron_right');
    page.media.matches = false; page.media.listener();
    assert.equal(page.toggle.parentNode, page.originalParent);
    assert.equal(page.icon.textContent, 'menu');
    page.media.matches = true; page.media.listener();
    assert.equal(page.toggle.parentNode, page.brand);
    assert.equal(page.icon.textContent, 'chevron_right');
});
