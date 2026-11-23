const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function setup(){
 class Node{constructor(){this.children=[];this.dataset={};this.attributes={};this.style={removeProperty(key){delete this[key];}};}append(...children){this.children.push(...children);}get firstElementChild(){return this.children[0];}setAttribute(key,value){this.attributes[key]=value;}removeAttribute(key){delete this.attributes[key];}}
 const document={createElement:()=>new Node()},window={};const dialog=new Node();dialog.querySelector=()=>({after:region=>dialog.region=region});
 vm.runInNewContext(fs.readFileSync('public/js/sheet-import.js','utf8'),{window,document,requestAnimationFrame:callback=>callback(),URL,Headers,Response});
 return {api:window.ImportProgress,dialog};
}
test('progress stays busy until work resolves and then exposes 100 percent',async()=>{
 const {api,dialog}=setup();let finish;
 const saving=api.run(dialog,'Saving entries…',()=>new Promise(resolve=>finish=resolve));await Promise.resolve();
 assert.equal(dialog.attributes['aria-busy'],'true');assert.equal(dialog.region.dataset.state,'working');assert.equal(dialog.region.children[2].attributes['aria-valuenow'],undefined);
 finish('saved');assert.equal(await saving,'saved');assert.equal(dialog.attributes['aria-busy'],'false');assert.equal(dialog.region.children[2].attributes['aria-valuenow'],'100');
 api.success(dialog,'2 entries saved');assert.equal(dialog.region.dataset.state,'success');assert.equal(dialog.region.children[1].textContent,'Import successful — 2 entries saved');
});
test('failed imports expose the error without showing success or 100 percent',async()=>{
 const {api,dialog}=setup();await assert.rejects(api.run(dialog,'Saving…',async()=>{throw Error('Save rejected');}),/Save rejected/);
 assert.equal(dialog.region.dataset.state,'error');assert.equal(dialog.region.children[1].textContent,'Save rejected');assert.equal(dialog.region.children[2].attributes['aria-valuenow'],undefined);assert.equal(dialog.attributes['aria-busy'],'false');
});
