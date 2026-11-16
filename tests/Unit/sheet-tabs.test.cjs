const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/sheet-tabs.js', 'utf8');
function page(active = 2) {
    const listeners = {};
    let resized;
    const document = {activeElement: null, querySelectorAll: () => [navigation]};
    const navigation = {
        scrollLeft: 0,
        getBoundingClientRect: () => ({left: 0, right: 200}),
        querySelectorAll: () => links,
        querySelector: () => links[active],
        addEventListener: (name, callback) => { listeners[name] = callback; },
    };
    const links = [0, 1, 2].map(index => ({
        hidden: false,
        getAttribute: () => null,
        getBoundingClientRect: () => ({left: index * 130 - navigation.scrollLeft, right: index * 130 + 80 - navigation.scrollLeft}),
        closest() { return this; },
        focus(options) { this.options = options; document.activeElement = this; },
    }));
    class ResizeObserver { constructor(callback) { resized = callback; } observe(target) { assert.equal(target, navigation); } }
    vm.runInNewContext(source, {document, ResizeObserver});
    function key(index, value, modifiers = {}) {
        const event = {target: links[index], key: value, ...modifiers, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }};
        listeners.keydown(event);
        return event;
    }
    return {navigation, links, document, key, resize: () => resized(), listeners};
}
test('an offscreen active tab scrolls only its navigation container', () => {
    const p = page();
    assert.equal(p.navigation.scrollLeft, 148);
    assert.equal(p.document.activeElement, null);
});
test('Left and Right move focus without following a link or scrolling the page', () => {
    const p = page(0);
    const event = p.key(0, 'ArrowRight');
    assert.equal(p.document.activeElement, p.links[1]);
    assert.equal(p.links[1].options.preventScroll, true);
    assert.equal(event.prevented, true);
    assert.equal(event.stopped, true);
    p.key(1, 'ArrowLeft');
    assert.equal(p.document.activeElement, p.links[0]);
});
test('Home and End focus the visible endpoints and skip unavailable links', () => {
    const p = page(0);
    p.key(0, 'End');
    assert.equal(p.document.activeElement, p.links[2]);
    p.key(2, 'Home');
    assert.equal(p.document.activeElement, p.links[0]);
    p.links[1].hidden = true;
    p.key(0, 'ArrowRight');
    assert.equal(p.document.activeElement, p.links[2]);
});
test('Enter and Tab keep their native browser behavior', () => {
    const p = page(0);
    for (const value of ['Enter', 'Tab', 'ArrowDown']) {
        const event = p.key(0, value);
        assert.equal(event.prevented, undefined);
        assert.equal(event.stopped, undefined);
    }
});
test('resize preserves the focused tab rather than moving focus or jumping vertically', () => {
    const p = page(0);
    p.key(0, 'End');
    p.navigation.scrollLeft = 0;
    p.resize();
    assert.equal(p.navigation.scrollLeft, 148);
    assert.equal(p.document.activeElement, p.links[2]);
});
test('events outside a navigation link do not affect sheet-grid editing', () => {
    const p = page(0);
    let prevented = false;
    p.listeners.keydown({target: {closest: () => null}, key: 'ArrowRight', preventDefault() { prevented = true; }});
    assert.equal(prevented, false);
});

test('browser shortcuts such as Alt+Left and Ctrl+Home remain native', () => {
    const p = page(0);
    for (const [key, modifiers] of [['ArrowLeft', {altKey: true}], ['Home', {ctrlKey: true}], ['ArrowRight', {metaKey: true}], ['End', {shiftKey: true}]]) {
        const event = p.key(0, key, modifiers);
        assert.equal(event.prevented, undefined);
        assert.equal(event.stopped, undefined);
    }
});
