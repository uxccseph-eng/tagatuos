const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/role-access-feedback.js', 'utf8');

function page(response, initial = null, referrer = '') {
    const fields = new Map(), listeners = {}, buttons = [{addEventListener() {}}, {addEventListener() {}}];
    const dialog = {
        open: false, setAttribute() {},
        querySelectorAll: () => buttons,
        querySelector: selector => { if (!fields.has(selector)) fields.set(selector, {}); return fields.get(selector); },
        addEventListener: (event, listener) => { listeners[event] = listener; },
        showModal() { this.open = true; },
        close() { this.open = false; listeners.close(); },
    };
    const redirects = [], appended = [];
    const document = {
        referrer,
        getElementById: () => initial ? {textContent: JSON.stringify(initial)} : null,
        createElement: () => dialog,
        body: {appendChild: node => appended.push(node)},
        querySelector: () => ({dataset: {roleHome: 'https://app.test/account'}}),
    };
    const window = {fetch: async () => response};
    const location = {href: 'https://app.test/settings', origin: 'https://app.test', replace: url => redirects.push(url)};
    vm.runInNewContext(source, {window, document, location, URL, Request});
    return {window, dialog, fields, redirects, appended};
}

test('role denials show a popup and preserve the original API response for the caller', async () => {
    const response = {status: 403, headers: new Headers({'content-type': 'application/json'}), clone: () => ({json: async () => ({permission_denied: true, role: 'Staff', message: 'Owner access required.'})})};
    const p = page(response);
    assert.equal(await p.window.fetch('/settings/company'), response);
    assert.equal(p.dialog.open, true);
    assert.equal(p.fields.get('#role-access-role').textContent, 'Your role: Staff');
    assert.equal(p.fields.get('#role-access-message').textContent, 'Owner access required.');
    p.dialog.close();
    assert.deepEqual(p.redirects, []);
});

test('untrusted denial text is assigned as text and repeated denials reuse one popup', () => {
    const p = page(null);
    p.window.RoleAccessFeedback.show({role: 'Accountant', message: '<img src=x onerror=alert(1)>'});
    p.window.RoleAccessFeedback.show({role: 'Staff', message: '<script>bad()</script>'});
    assert.equal(p.appended.length, 1);
    assert.equal(p.fields.get('#role-access-message').textContent, '<script>bad()</script>');
    assert.equal(p.dialog.innerHTML.includes('<script>bad()'), false);
});

test('external referrers never become return destinations after a direct-page denial', () => {
    const p = page(null, {role: 'Accountant', message: 'Owner access required.'}, 'https://untrusted.test/');
    assert.equal(p.dialog.open, true);
    p.dialog.close();
    assert.deepEqual(p.redirects, ['https://app.test/account']);
});

test('external service denials do not trigger account access feedback', async () => {
    const response = {status: 403, headers: new Headers({'content-type': 'application/json'}), clone() { throw Error('Must not inspect external content'); }};
    const p = page(response);
    assert.equal(await p.window.fetch('https://external.test/file'), response);
    assert.equal(p.dialog.open, false);
});
