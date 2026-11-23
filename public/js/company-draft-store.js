(() => {
    'use strict';
    const clone = value => JSON.parse(JSON.stringify(value));
    let connection;
    function openDevice() {
        if (connection) return connection;
        connection = new Promise((resolve, reject) => {
            if (!window.indexedDB) { reject(Error('Device backup unavailable')); return; }
            const request = window.indexedDB.open('kontadurya-private-drafts-v1', 1);
            const timer = setTimeout(() => reject(Error('Device backup unavailable')), 2000);
            request.onupgradeneeded = () => {
                const store = request.result.createObjectStore('drafts', {keyPath:'key'});
                store.createIndex('scope', 'scope');
            };
            request.onsuccess = () => { clearTimeout(timer); request.result.onversionchange = () => request.result.close(); resolve(request.result); };
            request.onerror = request.onblocked = () => { clearTimeout(timer); reject(Error('Device backup unavailable')); };
        });
        return connection;
    }
    async function deviceTransaction(mode, action) {
        const db = await openDevice();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('drafts', mode), request = action(transaction.objectStore('drafts'));
            transaction.oncomplete = () => resolve(request.result);
            transaction.onerror = transaction.onabort = () => reject(Error('Device backup unavailable'));
        });
    }
    const device = {
        list: scope => deviceTransaction('readonly', store => store.index('scope').getAll(scope)),
        get: key => deviceTransaction('readonly', store => store.get(key)),
        put: row => deviceTransaction('readwrite', store => store.put(row)),
        remove: key => deviceTransaction('readwrite', store => store.delete(key))
    };
    function create({companyId, userId, url, csrf, storage = device, changed = () => {}}) {
        const scope = `${userId}:${companyId}`;
        let id = crypto.randomUUID(), version = 0, generation = 0, queued = null, running = null, timer;
        let deviceSaved = false, serverSaved = false;
        const resumed = new Map();
        const keyFor = value => `${scope}:${value}`;
        const status = () => ({deviceSaved, serverSaved});
        async function request(path, method = 'GET', body) {
            const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 5000);
            try {
                const response = await fetch(url + path, {method, headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Company-ID':String(companyId)}, ...(body ? {body:JSON.stringify(body)} : {}), cache:'no-store', signal:controller.signal});
                const result = await response.json().catch(() => ({}));
                if (!response.ok) { const error = Error(result.message || 'Private draft could not be saved'); error.status = response.status; throw error; }
                return result;
            } finally { clearTimeout(timeout); }
        }
        function stage(payload) {
            queued = {payload:clone(payload), generation:++generation};
            deviceSaved = serverSaved = false;
            clearTimeout(timer); timer = setTimeout(() => flush().catch(() => {}), 200);
        }
        async function flush() {
            clearTimeout(timer);
            if (running) { const waitingGeneration = queued?.generation; await running; return queued && queued.generation !== waitingGeneration ? flush() : status(); }
            if (!queued) return status();
            const work = queued, draftId = id, draftVersion = version;
            const row = {key:keyFor(draftId),scope,id:draftId,version:draftVersion,updated_at:new Date().toISOString(),payload:work.payload,dirty:true};
            running = (async () => {
                let onDevice = false, onServer = false;
                try {
                    const existing = (await storage.list(scope)).filter(item => !item.resolved);
                    if (existing.some(item => item.id === draftId) || existing.length < 2) { await storage.put(row); onDevice = true; }
                } catch {}
                try {
                    const saved = await request('/'+draftId, 'PUT', {version:draftVersion,payload:work.payload});
                    if (saved.id !== draftId || !Number.isSafeInteger(saved.version) || saved.version <= draftVersion) throw Error('Private draft was not confirmed');
                    version = saved.version; onServer = true;
                    try { if(onDevice)await storage.put({...row,version,dirty:false,updated_at:saved.updated_at}); } catch {}
                } catch (error) {
                    if (error.status === 409) { id = crypto.randomUUID(); version = 0; }
                }
                if (generation === work.generation) {
                    deviceSaved = onDevice; serverSaved = onServer;
                    if (onServer) queued = null;
                    changed(status());
                }
            })().finally(() => { running = null; });
            await running;
            if (queued && queued.generation !== work.generation) return flush();
            return status();
        }
        async function list() {
            const all = new Map();
            try { for (const row of await storage.list(scope)) { if (!row.resolved) all.set(row.id, {...row,device:true}); } } catch {}
            try {
                const remote = (await request('')).drafts || [], present = new Set(remote.map(row=>row.id));
                for (const row of all.values()) {
                    if (!row.dirty && !present.has(row.id)) { all.delete(row.id); await storage.remove(keyFor(row.id)).catch(()=>{}); }
                }
                for (const row of remote) {
                    if (!all.get(row.id)?.dirty) all.set(row.id, {...row,server:true});
                }
            } catch {}
            return [...all.values()].filter(row=>row.id!==id).sort((a,b)=>Date.parse(b.updated_at)-Date.parse(a.updated_at));
        }
        async function read(item) {
            let row;
            try { row = await storage.get(keyFor(item.id)); } catch {}
            if (!row?.dirty) {
                try { row = await request('/'+item.id); } catch (error) { if (!row) throw error; }
            }
            if (!row?.payload || String(row.payload.company_id) !== String(companyId)) throw Error('This private draft is unavailable.');
            resumed.set(item.id, row.version || 0);
            return clone(row.payload);
        }
        async function discard(item) {
            try { await request('/'+item.id, 'DELETE', {version:item.version || 0}); }
            catch (error) { if (error.status !== 404) throw error; }
            await storage.remove(keyFor(item.id)).catch(() => {});
            resumed.delete(item.id);
        }
        const checkpoint = () => ({id,version,generation});
        async function confirm(saved, requireDeletion = false) {
            if (saved.generation !== generation) return false;
            clearTimeout(timer);
            const targets = new Map([[saved.id,saved.version],...resumed]);
            for (const [draftId, draftVersion] of targets) {
                if (saved.generation !== generation) return false;
                if (!requireDeletion) { try { const local = await storage.get(keyFor(draftId)); if (local && saved.generation === generation) await storage.put({...local,resolved:true}); } catch {} }
                try { await request('/'+draftId, 'DELETE', {version:draftVersion}); }
                catch (error) { if (requireDeletion) throw error; continue; }
                if (saved.generation === generation) await storage.remove(keyFor(draftId)).catch(() => {});
            }
            if (saved.generation !== generation) return false;
            queued = null; resumed.clear(); id = crypto.randomUUID(); version = 0;
            deviceSaved = serverSaved = false;
            return true;
        }
        const references = saved => saved.generation === generation ? [...new Map([[saved.id,saved.version],...resumed])].map(([id,version])=>({id,version})) : [];
        return {stage,flush,list,read,discard,status,checkpoint,confirm,references};
    }
    window.CompanyDraftStore = {create};
})();
