const test=require('node:test'),assert=require('node:assert/strict');
const {horizontalNavigation}=require('../../public/js/sheet-posting.js');
function grid(properties){
 const row={querySelectorAll:()=>fields},fields=properties.map(props=>({tagName:'INPUT',value:'100.00',selectionStart:6,selectionEnd:6,disabled:false,readOnly:false,hidden:false,type:'text',...props,
 matches(selector){if(selector==='input,select,textarea')return true;return ['checkbox','radio','file','hidden','button','submit'].includes(this.type);},
 closest(selector){return selector==='tr,[data-sheet-keyboard-row]'?this.row||row:this.hidden?{}:null;},getClientRects(){return this.hidden?[]:[{}];},focus(){this.focused=true;},select(){this.selected=true;},scrollIntoView(){this.scrolled=true;}}));
 return fields;
}
function press(target,key='ArrowRight',modifiers={}){const event={target,key,preventDefault(){this.prevented=true;},stopPropagation(){this.stopped=true;},...modifiers};const moved=horizontalNavigation(event);return {event,moved};}
for(const sheet of ['Sales','Collections','Purchases','Payments','CWT','WTP','Journal','Payroll'])test(sheet+' supports horizontal navigation through editable row fields',()=>{
 const fields=grid([{},{}]);assert.equal(press(fields[0]).moved,true);assert.equal(fields[1].focused,true);assert.equal(fields[1].selected,true);assert.equal(fields[1].scrolled,true);
 fields[1].selectionStart=fields[1].selectionEnd=0;assert.equal(press(fields[1],'ArrowLeft').moved,true);assert.equal(fields[0].focused,true);
});
test('arrows preserve text editing, partial selections and keyboard selection shortcuts',()=>{
 for(const values of [{selectionStart:2,selectionEnd:2},{selectionStart:0,selectionEnd:3}]){const fields=grid([values,{}]);assert.equal(press(fields[0]).moved,false);}
 for(const modifiers of [{ctrlKey:true},{metaKey:true},{shiftKey:true},{isComposing:true},{defaultPrevented:true}]){const fields=grid([{},{}]);assert.equal(press(fields[0],'ArrowRight',modifiers).moved,false);}
});
test('selecting the whole value allows repeated cell navigation and fast replacement',()=>{
 const fields=grid([{selectionStart:0,selectionEnd:6},{}]);assert.equal(press(fields[0]).moved,true);assert.equal(fields[1].selected,true);
});
test('hidden, locked, checkbox and disabled fields are skipped',()=>{
 const fields=grid([{}, {hidden:true},{readOnly:true},{type:'checkbox'},{disabled:true},{}]);press(fields[0]);assert.equal(fields[5].focused,true);for(const field of fields.slice(1,5))assert.ok(!field.focused);
});
test('closed selectors navigate horizontally while date segments retain native arrows',()=>{
 const fields=grid([{tagName:'SELECT',selectionStart:null,selectionEnd:null},{type:'date',selectionStart:null,selectionEnd:null},{}]);assert.equal(press(fields[0]).moved,true);assert.equal(fields[1].focused,true);assert.equal(press(fields[1]).moved,false);assert.equal(press(fields[1],'ArrowRight',{altKey:true}).moved,true);assert.equal(fields[2].focused,true);
});
test('nested payroll employee rows never jump into the surrounding payroll run',()=>{
 const fields=grid([{}, {row:{}},{}]);press(fields[0]);assert.equal(fields[2].focused,true);assert.ok(!fields[1].focused);
});
test('row edges do not wrap or accidentally invoke browser Back with Alt+Left',()=>{
 const fields=grid([{selectionStart:0,selectionEnd:0}]);const result=press(fields[0],'ArrowLeft',{altKey:true});assert.equal(result.moved,false);assert.equal(result.event.prevented,true);
});
test('search boxes and form fields outside table rows retain normal arrows',()=>{
 const field=grid([{}])[0];field.closest=()=>null;assert.equal(press(field).moved,false);
});

test('import mapping groups support arrows between source-column selectors',()=>{
 const fields=grid([{tagName:'SELECT',selectionStart:null,selectionEnd:null},{tagName:'SELECT',selectionStart:null,selectionEnd:null}]);
 assert.equal(press(fields[0]).moved,true);assert.equal(fields[1].focused,true);assert.equal(press(fields[1],'ArrowLeft').moved,true);
});
test('pasted spreadsheet textarea outside a review row keeps native text navigation',()=>{
 const field=grid([{tagName:'TEXTAREA'}])[0];field.closest=()=>null;const result=press(field);assert.equal(result.moved,false);assert.ok(!result.event.prevented);
});
