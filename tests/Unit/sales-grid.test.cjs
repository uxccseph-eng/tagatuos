const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../public/js/sales.js'), 'utf8');
const update = source.slice(source.indexOf('function updateSheet() {'), source.indexOf('function updatePeriod() {'));

function fixture() {
    const emptyRow = {hidden: false}, draft = {}, children = [], outputs = new Map();
    let moves = 0, saves = 0;
    const tbody = {
        get firstElementChild() { return children[0] || null; },
        insertBefore(row, target) {
            moves++;
            const old = children.indexOf(row);
            if (old >= 0) children.splice(old, 1);
            children.splice(target === null ? children.length : children.indexOf(target), 0, row);
        }
    };
    const row = () => ({hidden: false, checkbox: {checked: false}, querySelector() { return this.checkbox; }, get nextElementSibling() { return children[children.indexOf(this) + 1] || null; }});
    const first = {id: 1, date: '2026-10-01', customer: 'Agent', number: 'SI-000001', gross: 10000, memo: '', status: 'posted', row: row()};
    const second = {...first, id: 2, date: '2026-10-02', number: 'SI-000002', status: 'draft', row: row()};
    children.push(first.row, second.row, emptyRow, draft);
    const entries = new Map([[1, first], [2, second]]);
    const context = vm.createContext({entries, tbody, emptyRow, filterInput: {value: ''}, statusFilter: {value: 'all'}, grouped: false, sortAscending: true,
        window: {WorkingPeriod: {matches: () => true}}, currency: String, amounts: entry => ({net: entry.gross, vat: 0, cwt: 0}),
        document: {querySelector(selector) { if (!outputs.has(selector)) outputs.set(selector, {replaceChildren() {}}); return outputs.get(selector); }, createTextNode: String},
        selectedEntries: () => [...entries.values()].filter(entry => entry.row.checkbox.checked), refreshPostCount() {}, selectAll: {}, persistSales() { saves++; }
    });
    vm.runInContext(update, context);
    return {context, children, first, second, emptyRow, draft, moves: () => moves, saves: () => saves};
}

test('typing amounts saves and refreshes totals without moving existing Sales rows', () => {
    const grid = fixture();
    grid.second.row.checkbox.checked = true;
    for (const gross of [1, 12, 123, 12345]) {
        grid.second.gross = gross;
        grid.context.updateSheet();
    }
    assert.equal(grid.moves(), 0);
    assert.equal(grid.saves(), 4);
    assert.equal(grid.second.row.checkbox.checked, true);
    assert.deepEqual(grid.children, [grid.first.row, grid.second.row, grid.emptyRow, grid.draft]);
});

test('Sales sorting still changes row order once and subsequent updates keep rows in place', () => {
    const grid = fixture();
    grid.context.sortAscending = false;
    grid.context.updateSheet();
    assert.deepEqual(grid.children, [grid.second.row, grid.first.row, grid.emptyRow, grid.draft]);
    assert.equal(grid.moves(), 1);
    grid.second.gross = 12345;
    grid.context.updateSheet();
    assert.equal(grid.moves(), 1);
});


test('editing a posted imported sale retains its import identity and original transaction number', () => {
    const fields=['Date','Customer','Gross amount','Tax type','Revenue account','Income withholding','VAT withholding','Terms','Invoice reference','Classification','Deposit account','Description','Particulars','Settlement amount'];
    const values=['2026-10-02','Customer','200.00','Non-VAT','4020','None','None','On Account','Corrected','Regular','1010','Updated description','',''];
    const entryFields=Object.fromEntries(fields.map((name,index)=>[name,{value:values[index],reportValidity:()=>true,setCustomValidity(){}}]));
    const original={id:4,number:'SI-000004',status:'posted',importBatchKey:'a'.repeat(64),origin:'sheet-import',history:[{label:'Imported'}],gross:10000};
    let saved;
    const context=vm.createContext({entryIsEditable:true,editingEntry:original,entryFields,entryAttachments:[],entryFromInline:false,
        amountToCents:value=>Number(value)*100,nextNumber:5,workingPeriod:'2026-10',
        window:{SheetMasters:{withholdingError:()=>''},WorkingPeriod:{revealDate(){},current:()=>({from:'2026-10-01'})}},
        renderEntry:entry=>{saved=entry;},updatePeriod(){},entryDrawer:{close(){}},announce(){},filterInput:{},globalSearch:{},statusFilter:{},nextSaleNumber:()=>''});
    const save=source.slice(source.indexOf('function saveEntryDrawer('),source.indexOf('function suggestSalesFields('));
    vm.runInContext(save+';saveEntryDrawer(false);',context);
    assert.equal(saved.importBatchKey,original.importBatchKey);
    assert.equal(saved.origin,original.origin);
    assert.equal(saved.number,'SI-000004');
    assert.equal(saved.status,'posted');
    assert.equal(saved.gross,20000);
    assert.equal(saved.history[0].label,'Imported');
});


test('posted sales clear template descriptions for null, missing and empty memos', () => {
    const start = source.indexOf('function renderEntry(');
    const render = source.slice(start, source.indexOf('\n[...tbody', start));
    const node = () => ({textContent: '', dataset: {}, setAttribute() {}, cloneNode() { return node(); }});
    for (const memo of [null, undefined, '', 'Accountant entered details']) {
        const cells = Array.from({length: 16}, () => ({
            textContent: 'Sample description that must not survive',
            firstElementChild: node(),
            replaceChildren(child) { this.firstElementChild = child; },
            querySelector() { return node(); },
        }));
        const row = {cells, dataset: {}, classList: {toggle() {}}, querySelectorAll: () => []};
        const context = vm.createContext({
            window: {SheetMasters: {taxLabel: () => 'VAT'}},
            document: {createElement: node}, rowTemplate: {cells},
            entries: new Map(), amounts: () => ({net: 100, vat: 12}),
            currency: value => String(value), addBreakdown() {},
        });
        vm.runInContext(render, context);
        const entry = {id: 1, number: 'SI-000001', status: 'posted', gross: 112, memo};
        context.renderEntry(entry, row);
        assert.equal(cells[14].textContent, memo ?? '');
        assert.equal(entry.memo, memo, 'displaying an entry must not rewrite its saved description');
    }
});
