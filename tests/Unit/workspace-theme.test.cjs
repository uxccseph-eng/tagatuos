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

test('all page types respect system preference and persist the same explicit theme across navigation', () => {
    const landing = boot();
    assert.equal(landing.root.style.colorScheme, 'light');
    landing.click();
    assert.equal(landing.attributes['aria-pressed'], 'true');
    assert.equal(landing.caption.textContent, 'Light mode');
    assert.equal(landing.store.get('kontadurya-theme'), 'dark');
    const dashboard = boot({store: landing.store});
    assert.equal(dashboard.root.style.colorScheme, 'dark');
    dashboard.click();
    const sheets = boot({store: dashboard.store, dark: true});
    assert.equal(sheets.root.style.colorScheme, 'light');
    assert.equal(sheets.classes.has('theme-light'), true);
});

test('loading shared theme assets twice does not attach two toggle handlers', () => {
    const page = boot(); page.repeat(); page.click();
    assert.equal(page.events.click.length, 1);
    assert.equal(page.classes.has('dark'), true);
});

test('system changes apply only without an explicit preference and storage events synchronize tabs', () => {
    const page = boot(); page.media.matches = true; page.media.listener();
    assert.equal(page.classes.has('dark'), true);
    page.store.set('kontadurya.workspace.theme', 'light'); page.media.listener();
    assert.equal(page.classes.has('dark'), true);
    page.windowEvents.storage({key: 'kontadurya.workspace.theme'});
    assert.equal(page.classes.has('dark'), false);
});

test('theme controls work without browser storage or icon elements', () => {
    const page = boot({blocked: true});
    assert.doesNotThrow(page.click);
    assert.equal(page.root.style.colorScheme, 'dark');
});
