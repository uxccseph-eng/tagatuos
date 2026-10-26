const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/workspace-access.js', 'utf8');
function page() {
    const forms = [], calls = [], listeners = {};
    const makeForm = (action, method = 'post') => {
        const form = new Form();
        Object.assign(form, {action, method, field: null, querySelector: () => form.field, appendChild: field => { form.field = field; }});
        forms.push(form);
        return form;
    };
    class Form {}
    const internal = makeForm('http://localhost/masters/parties');
    const external = makeForm('https://example.test/form');
    const window = {fetch: async (...args) => { calls.push(args); return 'ok'; }};
    const document = {
        getElementById: () => ({textContent: JSON.stringify({companyId: 17, role: 'owner', tabs: ['sheets']})}),
        querySelectorAll: selector => selector === 'form[action]' ? forms : [],
        createElement: () => ({}),
        addEventListener: (name, callback) => { listeners[name] = callback; },
    };
    vm.runInNewContext(source, {window, document, location: {href: 'http://localhost/sheets/sales', origin: 'http://localhost'}, URL, Headers, Request, HTMLFormElement: Form});
    return {window, calls, internal, external, makeForm, listeners};
}
test('workspace fetches carry the rendered company while preserving CSRF and Request headers', async () => {
    const p = page();
    await p.window.fetch('/masters/parties', {method: 'POST', headers: {'X-CSRF-TOKEN': 'csrf', 'Content-Type': 'application/json', 'X-Company-ID': '999'}, body: '{}'});
    assert.equal(p.calls[0][1].headers.get('X-Company-ID'), '17');
    assert.equal(p.calls[0][1].headers.get('X-CSRF-TOKEN'), 'csrf');
    assert.equal(p.calls[0][1].body, '{}');
    await p.window.fetch(new Request('http://localhost/reports/generate-qap-dat', {headers: {'Accept': 'application/json'}}));
    assert.equal(p.calls[1][1].headers.get('X-Company-ID'), '17');
    assert.equal(p.calls[1][1].headers.get('Accept'), 'application/json');
});
test('company context is not transmitted to external origins or account administration', async () => {
    const p = page();
    await p.window.fetch('https://example.test/reports/file');
    await p.window.fetch('/account/companies');
    assert.equal(p.calls[0][1].headers, undefined);
    assert.equal(p.calls[1][1].headers, undefined);
    assert.equal(p.external.field, null);
});
test('existing and dynamically submitted workspace forms carry their original company', () => {
    const p = page();
    assert.equal(p.internal.field.name, '_company_id');
    assert.equal(p.internal.field.value, '17');
    const dynamic = p.makeForm('http://localhost/company/setup');
    p.listeners.submit({target: dynamic});
    assert.equal(dynamic.field.value, '17');
});
