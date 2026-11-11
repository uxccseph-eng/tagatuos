const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/js/company-draft-store.js','utf8');
const clone=value=>JSON.parse(JSON.stringify(value));
function environment({offline=false,blocked=false,rows=new Map(),server=new Map()}={}){
 const calls=[];let fail=offline,deny=blocked;
 const storage={
  list:async scope=>[...rows.values()].filter(row=>row.scope===scope).map(clone),
  get:async key=>rows.has(key)?clone(rows.get(key)):undefined,
  put:async row=>{if(deny)throw Error('Quota');rows.set(row.key,clone(row));},
  remove:async key=>rows.delete(key)
 };
 const fetch=async(url,options)=>{
  calls.push({url,method:options.method,body:options.body?JSON.parse(options.body):null});
  if(fail)throw Error('Offline');
  const id=url.split('/').at(-1),scope=options.headers['X-Company-ID'];
  const response=(data,status=200)=>({ok:status<400,status,json:async()=>data});
  if(options.method==='GET'&&url==='/drafts')return response({drafts:[...server.values()].filter(row=>row.company===scope).map(({payload,...row})=>row)});
  const existing=server.get(id),body=options.body?JSON.parse(options.body):null;
  if(options.method==='GET')return existing?response(existing):response({},404);
  if(options.method==='DELETE'){
   if(existing&&existing.version!==body.version)return response({},409);
   server.delete(id);return response({deleted:true});
  }
  if((existing?.version||0)!==body.version)return response({},409);
  const saved={id,version:body.version+1,payload:body.payload,updated_at:new Date().toISOString(),company:scope};
  server.set(id,saved);return response(saved);
 };
 const window={};
 vm.runInNewContext(source,{window,fetch,AbortController,crypto:require('node:crypto').webcrypto,setTimeout:()=>1,clearTimeout(){}});
 const make=(companyId=1,userId=1)=>window.CompanyDraftStore.create({companyId,userId,url:'/drafts',csrf:'token',storage});
 return {make,rows,server,calls,setOffline:value=>fail=value,setBlocked:value=>deny=value};
}
const payload=value=>({format:2,company_id:1,revision:0,changes:[{sheet:'sales',id:'a',record:{id:'a',gross:value},expected_version:0}],before:[],request:null,error:null});

test('a server-confirmed private draft survives a new browser session without device storage',async()=>{
 const e=environment({blocked:true}),first=e.make();
 first.stage(payload(100));await first.flush();
 assert.equal(first.status().serverSaved,true);assert.equal(first.status().deviceSaved,false);
 const next=e.make(),available=await next.list();
 assert.equal(available.length,1);
 assert.equal((await next.read(available[0])).changes[0].record.gross,100);
});

test('offline drafts survive a new page and synchronize after reconnection',async()=>{
 const e=environment({offline:true}),first=e.make();first.stage(payload(250));await first.flush();
 assert.equal(first.status().deviceSaved,true);assert.equal(first.status().serverSaved,false);
 const next=e.make(),available=await next.list();assert.equal(available.length,1);
 const recovered=await next.read(available[0]);assert.equal(recovered.changes[0].record.gross,250);
 e.setOffline(false);next.stage(recovered);await next.flush();
 assert.equal(next.status().serverSaved,true);
 await next.confirm(next.checkpoint());
 assert.equal((await e.make().list()).length,0);
});

test('device copies are scoped to the accountant and company',async()=>{
 const e=environment({offline:true}),a=e.make(1,1);a.stage(payload(10));await a.flush();
 assert.equal((await e.make(1,2).list()).length,0);
 assert.equal((await e.make(2,1).list()).length,0);
 assert.equal((await e.make(1,1).list()).length,1);
});

test('concurrent tabs keep separate drafts and conditional discard preserves a newer server version',async()=>{
 const e=environment(),a=e.make(),b=e.make();a.stage(payload(10));b.stage(payload(20));await Promise.all([a.flush(),b.flush()]);
 const next=e.make(),available=await next.list();assert.equal(available.length,2);
 e.server.get(available[0].id).version++;
 await assert.rejects(next.discard(available[0]));
 assert.equal(e.server.size,2);
 await next.discard(available[1]);assert.equal(e.server.size,1);
});

test('a save acknowledgement cannot clear edits staged after its checkpoint',async()=>{
 const e=environment(),a=e.make();a.stage(payload(10));await a.flush();const checkpoint=a.checkpoint();
 a.stage(payload(20));await a.flush();
 assert.equal(await a.confirm(checkpoint),false);
 assert.equal(e.server.size,1);
 assert.equal([...e.server.values()][0].payload.changes[0].record.gross,20);
 await a.confirm(a.checkpoint());assert.equal(e.server.size,0);assert.equal(e.rows.size,0);
});

test('acknowledged work is not offered again when server cleanup temporarily fails',async()=>{
 const e=environment(),a=e.make();a.stage(payload(30));await a.flush();e.setOffline(true);
 await a.confirm(a.checkpoint());
 assert.equal((await e.make().list()).length,0);
});

test('explicit discard retains recovery when the server cannot confirm deletion',async()=>{
 const e=environment(),a=e.make();a.stage(payload(30));await a.flush();e.setOffline(true);
 await assert.rejects(a.confirm(a.checkpoint(),true));
 assert.equal((await e.make().list()).length,1);
 e.setOffline(false);assert.equal(e.server.size,1);
});

test('unavailable server and device storage are reported honestly without blocking the book-save path',async()=>{
 const e=environment({offline:true,blocked:true}),a=e.make();a.stage(payload(1));await a.flush();
 assert.equal(a.status().deviceSaved,false);assert.equal(a.status().serverSaved,false);
});
