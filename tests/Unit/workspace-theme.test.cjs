const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/sales-theme.js', 'utf8');

function boot({store = new Map(), dark = false, blocked = false} = {}) {
    const classes = new Set(), events = {}, windowEvents = {}, attributes = {};
    const caption = {}, button = {setAttribute: (key, value) => { attributes[key] = value; }, querySelector: () => caption};
    const root = {style: {}, classList: {toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name), contains: name => classes.has(name)}};
    const media = {matches: dark, addEventListener: (name, listener) => { media.listener = listener; }};
    const window = {matchMedia: () => media, addEventListener: (name, listener) => { windowEvents[name] = listener; }};
    const document = {documentElement: root, querySelectorAll: selector => selector.startsWith('#theme-icon') ? [] : [button], addEventListener: (name, listener) => { (events[name] ||= []).push(listener); }};
    const localStorage = {getItem: key => { if (blocked) throw Error('Storage disabled'); return store.get(key); }, setItem: (key, value) => { if (blocked) throw Error('Storage disabled'); store.set(key, value); }};
    const context = vm.createContext({window, document, localStorage});
    vm.runInContext(source, context);
    const click = () => events.click.forEach(listener => listener({target: {closest: () => button}}));
    return {window, windowEvents, root, classes, caption, attributes, media, click, store, events, repeat: () => vm.runInContext(source, context)};
}

test('dark is the default for every page even when the device prefers light, while explicit choices persist', () => {
    for (const dark of [true, false]) {
        assert.equal(boot({dark}).root.style.colorScheme, 'dark');
    }
    const landing = boot();
    landing.click();
    assert.equal(landing.attributes['aria-pressed'], 'false');
    assert.equal(landing.caption.textContent, 'Dark mode');
    assert.equal(landing.store.get('kontadurya-theme'), 'light');
    const dashboard = boot({store: landing.store, dark: true});
    assert.equal(dashboard.root.style.colorScheme, 'light');
    dashboard.click();
    const sheets = boot({store: dashboard.store});
    assert.equal(sheets.root.style.colorScheme, 'dark');
    assert.equal(sheets.classes.has('theme-light'), false);
});

test('loading shared theme assets twice does not attach two toggle handlers', () => {
    const page = boot(); page.repeat(); page.click();
    assert.equal(page.events.click.length, 1);
    assert.equal(page.classes.has('dark'), false);
});

test('storage events synchronize explicit choices and removing the preference restores dark mode', () => {
    const page = boot();
    page.store.set('kontadurya.workspace.theme', 'light');
    page.windowEvents.storage({key: 'kontadurya.workspace.theme'});
    assert.equal(page.classes.has('dark'), false);
    page.store.delete('kontadurya.workspace.theme');
    page.windowEvents.storage({key: 'kontadurya.workspace.theme'});
    assert.equal(page.classes.has('dark'), true);
});

test('legacy light preferences remain usable and invalid preferences fall back to dark', () => {
    assert.equal(boot({store: new Map([['kontadurya-theme', 'light']])}).root.style.colorScheme, 'light');
    assert.equal(boot({store: new Map([['kontadurya.workspace.theme', 'invalid']])}).root.style.colorScheme, 'dark');
});

test('theme controls work without browser storage or icon elements', () => {
    const page = boot({blocked: true});
    assert.doesNotThrow(page.click);
    assert.equal(page.root.style.colorScheme, 'light');
});
