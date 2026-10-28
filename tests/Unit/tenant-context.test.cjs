const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/tenant-context.js', 'utf8');
function boot(companyId = '12', href = 'https://example.test/sheets/purchases', links = []) {
    const calls = [], listeners = {}, historyCalls = [];
    const form = {action:'https://example.test/settings/company',children:[],querySelector(){return this.children[0];},append(field){this.children.push(field);}};
    const document = {querySelector:()=>({content:companyId}),querySelectorAll:selector=>selector==='form'?[form]:links,createElement:()=>({value:''}),addEventListener:(event,callback)=>{listeners[event]=callback;}};
    const window = {fetch:(...args)=>{calls.push(args);return Promise.resolve({ok:true});}};
    vm.runInNewContext(source,{document,window,location:new URL(href),history:{state:null,replaceState:(...args)=>historyCalls.push(args)},URL,Headers,Request});
    return {calls,listeners,historyCalls,form,fetch:window.fetch};
}
test('tab URL, forms and fetches carry their own company',async()=>{
    const p=boot();
    assert.equal(new URL(p.historyCalls[0][2]).searchParams.get('company_id'),'12');
    assert.equal(new URL(p.form.action).searchParams.get('company_id'),'12');
    assert.equal(p.form.children[0].value,'12');
    await p.fetch('/sheets/database',{method:'POST',body:'{}'});
    assert.equal(new URL(p.calls[0][0]).searchParams.get('company_id'),'12');
    assert.equal(p.calls[0][1].headers.get('X-Company-ID'),'12');
    assert.equal(p.calls[0][1].body,'{}');
});
test('conflicting identifiers are preserved for the server to reject',async()=>{
    const p=boot();await p.fetch('/sheets/database?company_id=27',{headers:{'X-Company-ID':'27'}});
    assert.equal(new URL(p.calls[0][0]).searchParams.get('company_id'),'27');
    assert.equal(p.calls[0][1].headers.get('X-Company-ID'),'27');
});
test('central and external requests do not receive company identifiers',async()=>{
    const p=boot();await p.fetch('/account/password',{method:'PUT'});await p.fetch('https://other.test/sheets');
    assert.equal(p.calls[0][0],'/account/password');assert.equal(p.calls[0][1].headers,undefined);
    assert.equal(p.calls[1][0],'https://other.test/sheets');assert.equal(p.calls[1][1].headers,undefined);
});
test('request objects retain method and custom headers',async()=>{
    const p=boot();await p.fetch(new Request('https://example.test/masters/chart-of-accounts',{headers:{Accept:'application/json'}}));
    assert.equal(p.calls[0][0].method,'GET');assert.equal(p.calls[0][1].headers.get('Accept'),'application/json');
    assert.equal(new URL(p.calls[0][0].url).searchParams.get('company_id'),'12');
});
test('different tabs keep independent identifiers',async()=>{
    const first=boot('12'),second=boot('27');await second.fetch('/sheets/database');await first.fetch('/sheets/database');
    assert.equal(first.calls[0][1].headers.get('X-Company-ID'),'12');assert.equal(second.calls[0][1].headers.get('X-Company-ID'),'27');
});
