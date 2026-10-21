const test = require('node:test');
const assert = require('node:assert/strict');
const {packagePages, wrapText, create} = require('../../public/js/financial-report-pdf.js');
const {metadata} = require('../../public/js/financial-report-template.js');
const meta = metadata({company_name:'Example Company',full_name:'Test User'}, 'Trial Balance', {startDate:'2026-01-01',endDate:'2026-12-31'}, 'trial-balance');
const columns = ['Code','Account','Debit','Credit','Balance'];
function mockDocument() {
    const calls = [];
    return {calls, fonts:{ready:Promise.resolve()}, createElement: () => ({width:0,height:0,getContext: () => ({scale(){},fillRect(){},beginPath(){},moveTo(){},lineTo(){},stroke(){},strokeRect(){},measureText: value => ({width: value.length * 4}),fillText: (text,x,y) => calls.push({text,x,y})}), toBlob: resolve => resolve(new Blob([new Uint8Array([0xff,0xd8,0xff,0xd9])]))})};
}
test('PDF package has correct byte offsets, landscape pages and JPEG image streams', () => {
    const image = {bytes: new Uint8Array([255,216,255,217]), width:2526,height:1786};
    const bytes = packagePages([image,image]), text = Buffer.from(bytes).toString('latin1');
    assert.ok(text.startsWith('%PDF-1.4')); assert.ok(text.includes('/Count 2')); assert.ok(text.includes('/MediaBox [0 0 841.89 595.28]'));
    const offset = Number(text.match(/startxref\n(\d+)/)[1]); assert.equal(text.slice(offset,offset+4),'xref');
    const entries = text.slice(offset).split('\n').slice(3,11);
    entries.forEach((entry,index) => {const start=Number(entry.slice(0,10)); assert.ok(text.slice(start).startsWith((index+1)+' 0 obj'));});
    assert.equal((text.match(/\/Subtype \/Image/g)||[]).length,2);
});
test('long words and Unicode names wrap without losing characters', () => {
    const context = {measureText: value => ({width:Array.from(value).length*5})};
    const lines = wrapText(context,'José Ñ UnicodeLongAccountName',40);
    assert.equal(lines.join('').replace(/ /g,''),'JoséÑUnicodeLongAccountName');
    assert.ok(lines.every(line=>context.measureText(line).width<=40));
});
test('direct PDF generation repeats headings across pages and renders totals, negative amounts and page counts', async () => {
    global.document = mockDocument();
    try {
        const rows = Array.from({length:80}, (_,index) => ({kind:'account',cells:[String(index),'José Ñ account',200,391.07,-191.07]}));
        rows.push({kind:'grand',cells:['Totals','',16000,31285.6,-15285.6]});
        const bytes = await create(meta,columns,rows), text = Buffer.from(bytes).toString('latin1');
        const pageCount=Number(text.match(/\/Count (\d+)/)[1]);
        assert.ok(pageCount>1);
        assert.equal(document.calls.filter(call=>call.text==='Trial Balance').length,pageCount);
        assert.equal(document.calls.filter(call=>call.text==='Account').length,pageCount);
        assert.equal(document.calls.filter(call=>call.text==='(191.07)').length,80);
        assert.ok(document.calls.some(call=>call.text==='Totals'));
        assert.ok(document.calls.some(call=>call.text==='Page '+pageCount+' of '+pageCount));
    } finally {delete global.document;}
});
test('invalid report data fails before rendering instead of downloading an incomplete PDF', async () => {
    await assert.rejects(create(meta,columns,[{cells:['missing columns']}]),/incomplete/);
});
