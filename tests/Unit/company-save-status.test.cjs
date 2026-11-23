const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function boot({sheet = true, initialized = true, reduced = false, animationSupport = true, hidden = false} = {}) {
    const animations = [];
    const timers = new Map(); let timerId = 0;
    let now = 100000;
    class Clock extends Date { static now() { return now; } }
    class Element {
        constructor() {
            this.children = []; this.attributes = {}; this.dataset = {}; this.textContent = ''; this.listeners = {};
            if (animationSupport) this.animate = (frames, options) => {
                const animation = {target: this, frames, options, cancelled: false, cancel() { this.cancelled = true; }};
                animations.push(animation); return animation;
            };
        }
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
    const window = {matchMedia: () => ({matches: reduced})};
    vm.runInNewContext(fs.readFileSync('public/js/company-save-status.js', 'utf8'), {window, Date: Clock, setTimeout: (fn, delay) => { const id = ++timerId; timers.set(id, {fn, at: now + delay}); return id; }, clearTimeout: id => timers.delete(id), document: {hidden, body, createElement: () => new Element(), querySelector: () => main}});
    const view = window.CompanySaveStatus.create(database);
    const update = values => view.update({state: 'saved', message: 'Database saved', revision: 3, initialized, saving: false, ...values});
    const toggle = view.banner.children[2], panel = view.banner.children[3];
    return {view, database, main, toolbar, body, toggle, panel, update, animations, advance: milliseconds => { now += milliseconds; for (const [id, timer] of timers) { if (timer.at <= now) { timers.delete(id); timer.fn(); } } }};
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


test('confirmation plays only after a local save receives a newer database revision', () => {
    const ui = boot(); ui.update();
    assert.equal(ui.animations.length, 0);
    ui.update({state: 'pending', saving: true});
    assert.equal(ui.animations.length, 0);
    ui.update({revision: 4});
    assert.equal(ui.animations.length, 2);
    assert.equal(ui.animations[0].target.className, 'company-save-indicator');
    assert.equal(ui.animations[1].target.className, 'company-save-confirmation');
    assert.equal(ui.view.banner.dataset.confirmed, 'true');
    assert.equal(ui.animations[1].options.duration, 250);
    ui.update({revision: 4});
    ui.update({revision: 5});
    assert.equal(ui.animations.length, 2);
});

test('failed saves and unchanged revisions never animate a success', () => {
    const ui = boot(); ui.update();
    ui.update({state: 'pending', saving: true});
    ui.update({state: 'error', message: 'Save failed'});
    ui.update({revision: 4});
    assert.equal(ui.animations.length, 0);
    ui.update({state: 'pending', saving: true, revision: 4});
    ui.update({revision: 4});
    assert.equal(ui.animations.length, 0);
});

test('new pending work cancels confirmation and rapid autosaves do not replay it', () => {
    const ui = boot(); ui.update();
    ui.update({state: 'pending', saving: true}); ui.update({revision: 4});
    ui.update({state: 'pending', saving: true, revision: 4});
    assert.ok(ui.animations.every(animation => animation.cancelled));
    ui.update({revision: 5});
    assert.equal(ui.animations.length, 2);
    ui.advance(30001);
    ui.update({state: 'pending', saving: true, revision: 5}); ui.update({revision: 6});
    assert.equal(ui.animations.length, 4);
});

test('reduced motion keeps fade feedback without transforms', () => {
    const ui = boot({reduced: true}); ui.update();
    ui.update({state: 'pending', saving: true}); ui.update({revision: 4});
    assert.equal(ui.animations.length, 2);
    assert.ok(ui.animations.every(animation => animation.frames.every(frame => !('transform' in frame))));
});

test('hidden tabs and browsers without animation support still show the saved state', () => {
    for (const options of [{hidden: true}, {animationSupport: false}]) {
        const ui = boot(options); ui.update();
        ui.update({state: 'pending', saving: true}); ui.update({revision: 4});
        assert.equal(ui.animations.length, 0);
        assert.equal(ui.view.label.textContent, 'Saved to company database');
    }
});


test('the loader runs only for active saves and stops for confirmation or failure', () => {
    const ui = boot(); ui.update();
    assert.equal(ui.view.banner.dataset.saving, 'false');
    ui.update({state: 'pending', saving: true});
    assert.equal(ui.view.banner.dataset.saving, 'true');
    assert.equal(ui.view.label.textContent, 'Saving changes…');
    ui.update({revision: 4});
    assert.equal(ui.view.banner.dataset.saving, 'false');
    ui.update({state: 'pending', saving: true});
    ui.update({state: 'error', message: 'Connection unavailable'});
    assert.equal(ui.view.banner.dataset.saving, 'false');
});

test('recovery and conflict notices never animate as an active save', () => {
    const ui = boot();
    ui.update({state: 'pending', saving: true, recoveryUnavailable: true, message: 'Review your saved draft'});
    assert.equal(ui.view.banner.dataset.saving, 'false');
    assert.equal(ui.view.label.textContent, 'Review your saved draft');
    ui.update({state: 'warning', saving: false, message: 'Review conflicting entries'});
    assert.equal(ui.view.banner.dataset.saving, 'false');
});


test('confirmed feedback stays visible briefly and then clears without changing saved status', () => {
    const ui = boot(); ui.update();
    ui.update({state: 'pending', saving: true}); ui.update({revision: 4});
    ui.advance(1000);
    assert.equal(ui.view.banner.dataset.confirmed, 'true');
    ui.advance(401);
    assert.equal(ui.view.banner.dataset.confirmed, 'false');
    assert.equal(ui.view.banner.dataset.state, 'saved');
    ui.advance(100);
    ui.update({state: 'pending', saving: true, revision: 4}); ui.update({revision: 5});
    assert.equal(ui.animations.length, 4);
});

test('CSS spinner targets a real indicator instead of pseudo-element animation support', () => {
    const css = fs.readFileSync('public/css/company-save-status.css', 'utf8');
    assert.match(css, /\[data-saving=true\] \.company-save-indicator/);
    assert.doesNotMatch(css, /company-save-message::before/);
});
