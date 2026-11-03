(() => {
    'use strict';
    const database = window.CompanyLedgerDatabase;
    if (!database) return;
    const clone = value => JSON.parse(JSON.stringify(value));
    const config = document.getElementById('workspace-ledger-config');
    if (!config) return;
    const ledgerKey = 'kontadurya.ledger.v1.' + config.dataset.key;
    const payrollKey = 'kontadurya.payroll.v1.' + config.dataset.key;
    const recoveryKey = ledgerKey + '.pending-database-save';
    const restoreKey = recoveryKey + '.restore';
    let state = clone(database.state), payroll = clone(database.payroll), revision = database.revision;
    let pending = false, running = null, timer = null, failure = null, migration = false, retry = null, recoveryReview = false, leaving = false, restoreAvailable = false;
    const sheets = ['sales','purchases','collections','payments','cwtCertificates','wtpCertificates','journalEntries'];
    const validState = value => value && typeof value === 'object' && sheets.every(key => Array.isArray(value[key]));
    const records = window.LedgerRecordChanges;
    const collaborative = Boolean(records && database.record_versions);
    const same = records?.same || ((first, second) => JSON.stringify(first) === JSON.stringify(second));
    const operations = () => records.changes(database.state, database.payroll, state, payroll, database.record_versions);
    const editing = () => document.querySelector('dialog[open]') || document.activeElement?.matches('input,textarea,select,[contenteditable=true]');
    function notify() {
        if (!editing()) { editorState = clone(state); editorPayroll = clone(payroll); }
        window.dispatchEvent(new CustomEvent('company-ledger:saved', {detail: database}));
        if (!editing()) { window.SheetBulk?.refresh(); window.dispatchEvent(new CustomEvent('company-ledger:refreshed')); }
    }
    const banner = document.createElement('aside');
    banner.className = 'company-database-status';
    banner.setAttribute('aria-live', 'polite');
    const label = document.createElement('span');
    const actions = document.createElement('span');
    banner.append(label, actions);
    document.body.append(banner);
    const button = (text, callback) => { const el = document.createElement('button'); el.type = 'button'; el.textContent = text; el.addEventListener('click', callback); actions.append(el); };
    const hasRecords = value => value && Object.values(value).some(rows => Array.isArray(rows) && rows.length);
    let legacy = null, legacyPayroll = [];
    try { legacy = JSON.parse(sessionStorage.getItem(ledgerKey) || 'null'); legacyPayroll = JSON.parse(sessionStorage.getItem(payrollKey) || '[]'); }
    catch { failure = {message: 'Browser records could not be read. Existing data has been preserved. Download or recover the browser data before editing.', status: 409}; }
    if (database.canWrite && !database.initialized && (hasRecords(legacy) || legacyPayroll.length)) {
        state = {...clone(database.state), ...(legacy || {})}; payroll = legacyPayroll;
        migration = true;
        try { sessionStorage.setItem(ledgerKey + '.before-database-transfer', JSON.stringify({state, payroll})); } catch {}
    }
    const recovery = (() => { try { return sessionStorage.getItem(recoveryKey); } catch { return null; } })();
    let preserved = null, restoreRequested = false;
    try { preserved = recovery ? JSON.parse(recovery) : null; restoreRequested = sessionStorage.getItem(restoreKey) === '1'; sessionStorage.removeItem(restoreKey); } catch {}
    const validRecovery = preserved && String(preserved.company_id) === String(database.company_id) && Number.isInteger(preserved.revision) && validState(preserved.state) && Array.isArray(preserved.payroll);
    const recoverableOperations = collaborative && validState(preserved?.base_state) && Array.isArray(preserved?.base_payroll) && preserved?.base_versions;
    const canRestore = validRecovery && (preserved.revision === revision || recoverableOperations) && database.canWrite;
    if (recovery) {
        migration = false; state = clone(database.state); payroll = clone(database.payroll);
        if (validRecovery && same(preserved.state, state) && same(preserved.payroll, payroll)) {
            try { sessionStorage.removeItem(recoveryKey); } catch {}
        } else if (restoreRequested && canRestore) {
            state = clone(preserved.state); payroll = clone(preserved.payroll); pending = true; recoveryReview = true;
            if (recoverableOperations) { database.state = clone(preserved.base_state); database.payroll = clone(preserved.base_payroll); database.record_versions = clone(preserved.base_versions); revision = preserved.revision; }
            const request = preserved.request;
            if (request && (!collaborative || Array.isArray(request.operations)) && request.revision === revision && typeof request.request_id === 'string' && validState(request.state) && Array.isArray(request.payroll)) retry = clone(request);
            failure = null;
        } else {
            restoreAvailable = Boolean(canRestore);
            const reason = recoverableOperations ? 'Other accountants may have saved since this tab’s pending work. Restore it to review and save only your changed entries; conflicts will be checked.' : validRecovery && preserved.revision !== revision ? 'The database has a different version. Download the unsaved work and compare it with the saved book; it cannot safely overwrite newer records.' : 'This tab contains unsaved work from an earlier database save. Restore it to review and save, or download a backup.';
            failure = {message: reason + (preserved?.error?.message ? ' Last save error: ' + String(preserved.error.message) : ''), status: 409};
        }
    }
    let editorState = clone(state), editorPayroll = clone(payroll);
    function postingLabel(sheet, record) {
        const confirmed = (sheet === 'payroll' ? database.payroll : database.state[sheet]) || [];
        const current = (sheet === 'payroll' ? payroll : state[sheet])?.find(item => String(item.id) === String(record.id));
        if (current && confirmed.some(saved => String(saved.id) === String(record.id) && saved.status === 'posted' && same(saved, current))) return 'Posted';
        return failure || recoveryReview || migration ? 'Not saved' : 'Saving…';
    }
    function decorateBadge(node, sheet, record) {
        if (!node) return;
        if (record.status !== 'posted') { delete node.dataset.companyPostedSheet; delete node.dataset.companyPostedId; node.classList?.toggle('company-posting-unconfirmed', false); return; }
        node.dataset.companyPostedSheet = sheet; node.dataset.companyPostedId = String(record.id); node.dataset.companyPostedLabel = node.textContent.replace(/^(?:Saving\u2026|Not saved)/, 'Posted');
        updateBadge(node);
    }
    function updateBadge(node) {
        const text = postingLabel(node.dataset.companyPostedSheet, {id: node.dataset.companyPostedId});
        node.textContent = (node.dataset.companyPostedLabel || 'Posted').replace(/^Posted/, text);
        node.classList?.toggle('company-posting-unconfirmed', text !== 'Posted');
    }
    function download() {
        let preserved;
        try { preserved = sessionStorage.getItem(recoveryKey); } catch {}
        const blob = new Blob([preserved || JSON.stringify({company_id: database.company_id, revision, state, payroll}, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = `company-${database.company_id}-unsaved-work.json`; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function render() {
        actions.replaceChildren();
        document.documentElement.classList.toggle('company-save-pending', pending || Boolean(running) || Boolean(failure) || migration);
        if (failure) {
            label.textContent = 'Not saved to the database: ' + failure.message;
            banner.dataset.state = 'error';
            button('Download unsaved work', download);
            if (restoreAvailable) button('Restore unsaved work', () => { try { sessionStorage.setItem(restoreKey, '1'); leaving = true; location.reload(); } catch { leaving = false; label.textContent = 'Could not prepare recovery. Download the unsaved work before continuing.'; } });
            if (failure.record_conflict) button('Review conflicting entries', () => reviewConflicts().catch(error => { label.textContent = error.message; }));
            if (failure.status !== 409) button('Retry save', () => { failure = null; pending = true; flush().catch(() => {}); });
            button('Download and reload', () => { download(); leaving = true; location.reload(); });
        } else if (recoveryReview) {
            label.textContent = 'Unsaved work restored in this tab. Review the entries, then save them to the company database.';
            banner.dataset.state = 'pending';
            button('Download unsaved work', download);
            button('Save recovered work', () => { recoveryReview = false; pending = true; flush().catch(() => {}); });
        } else if (migration) {
            const count = Object.values(state).filter(Array.isArray).reduce((sum, rows) => sum + rows.length, 0) + payroll.length;
            label.textContent = `${count} browser records await transfer to this company database. Review them before transferring. Existing browser data is preserved.`;
            banner.dataset.state = 'pending';
            button('Download browser backup', download);
            if (database.canWrite) button('Transfer reviewed records', () => { migration = false; pending = true; flush().catch(() => {}); });
        } else if (pending || running) {
            label.textContent = 'Saving to the company database… Entries are awaiting server confirmation.';
            banner.dataset.state = 'pending';
        } else {
            label.textContent = 'Company database · Saved · Version ' + revision;
            banner.dataset.state = 'saved';
        }
        for (const node of document.querySelectorAll?.('[data-company-posted-sheet]') || []) updateBadge(node);
    }
    function stash() {
        sessionStorage.setItem(recoveryKey, JSON.stringify({company_id: database.company_id, revision, state, payroll, base_state: database.state, base_payroll: database.payroll, base_versions: database.record_versions, request: retry, error: failure}));
    }
    function stage(section, next) {
        next = clone(next);
        if (!database.canWrite) throw Error('You do not have permission to save Sheet transactions.');
        if (failure?.status === 409) throw Error(failure.message);
        if (same(next, section === 'state' ? state : payroll)) return;
        const previousState = state, previousPayroll = payroll, previousFailure = failure, previousRetry = retry, previousEditorState = editorState, previousEditorPayroll = editorPayroll;
        if (collaborative) {
            const rebased = records.rebase(editorState, editorPayroll, section === 'state' ? next : editorState, section === 'payroll' ? next : editorPayroll, state, payroll);
            state = rebased.state; payroll = rebased.payroll;
            if (section === 'state') editorState = clone(next); else editorPayroll = clone(next);
        } else if (section === 'state') state = clone(next); else payroll = clone(next);
        if (!running && failure?.status) retry = null;
        failure = null;
        try { stash(); }
        catch { state = previousState; payroll = previousPayroll; editorState = previousEditorState; editorPayroll = previousEditorPayroll; failure = previousFailure; retry = previousRetry; throw Error('This tab could not preserve the pending save. Your last confirmed database records are unchanged.'); }
        if (!migration && !recoveryReview) { pending = true; clearTimeout(timer); timer = setTimeout(() => flush().catch(() => {}), 300); }
        render();
    }
    async function flush() {
        clearTimeout(timer);
        if (running) { await running; return pending || running ? flush() : undefined; }
        if (failure) throw Error(failure.message);
        if (recoveryReview) throw Error('Review the restored entries, then click Save recovered work.');
        if (migration || !pending) return;
        const payload = retry || {revision, request_id: crypto.randomUUID(), state: clone(state), payroll: clone(payroll), ...(collaborative ? {operations: operations()} : {})};
        if (collaborative && payload.operations?.length === 0) { pending = false; retry = null; state = clone(database.state); payroll = clone(database.payroll); try {sessionStorage.removeItem(recoveryKey);} catch {} render(); return; }
        retry = payload; pending = !same(payload.state, state) || !same(payload.payroll, payroll);
        try { stash(); } catch { pending = true; failure = {message: 'Could not preserve this save request. Download the unsaved work before continuing.', status: 0}; render(); throw Error(failure.message); }
        running = (async () => {
            const response = await fetch(database.url, {method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': database.csrf, 'X-Company-ID': String(database.company_id)}, body: JSON.stringify(collaborative ? {revision:payload.revision,request_id:payload.request_id,operations:payload.operations} : payload)});
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'The server could not save this company book.';
                failure = {message, status: response.status, record_conflict: Boolean(result.record_conflict), conflicts: result.conflicts || []};
                throw Error(message);
            }
            if (String(result.company_id) !== String(database.company_id) || !Number.isInteger(result.revision) || result.revision < payload.revision || !validState(result.state) || !Array.isArray(result.payroll)) throw Error('The server did not confirm the saved company records. Keep this tab open and retry the save.');
            const later = pending && collaborative ? records.rebase(payload.state, payload.payroll, state, payroll, result.state, result.payroll) : null;
            const lateEdits = pending && collaborative && result.replayed && result.revision > result.applied_revision ? records.changes(payload.state,payload.payroll,state,payroll,database.record_versions) : [];
            const expectedAfterRetry = lateEdits.map(op=>({...op, expected_version:records.version(result.applied_versions,op.sheet,op.id,op.expected_version)}));
            revision = result.revision;
            Object.assign(database, result);
            for(const op of expectedAfterRetry) { database.record_versions[op.sheet] ||= {}; Object.defineProperty(database.record_versions[op.sheet],op.id,{value:op.expected_version,writable:true,enumerable:true,configurable:true}); }
            state = clone(later?.state || result.state); payroll = clone(later?.payroll || result.payroll);
            retry = null;
            pending = collaborative ? operations().length > 0 : pending;
            if (!pending) sessionStorage.removeItem(recoveryKey); else stash();
            try { sessionStorage.setItem(ledgerKey, JSON.stringify(state)); sessionStorage.setItem(payrollKey, JSON.stringify(payroll)); } catch {}
            notify();
        })().catch(error => {
            if (!failure) failure = {message: error.message || 'Network unavailable. Unsaved work remains in this tab.', status: 0};
            try { stash(); } catch {}
            throw error;
        }).finally(() => { running = null; render(); });
        render();
        await running;
        if (pending) await flush();
    }
    window.CompanyLedger = {newId: () => { const value = crypto.getRandomValues(new Uint32Array(2)); return (value[0] & 0xffff) * 4294967296 + value[1] || 1; }, state: () => clone(state), payroll: () => clone(payroll), journals: () => clone(database.journals), saveState: next => stage('state', next), savePayroll: next => stage('payroll', next), flush, postingLabel, decorateBadge, pending: () => pending || Boolean(running) || Boolean(failure) || migration || recoveryReview};
    window.addEventListener('beforeunload', event => { if (!leaving && (pending || running || failure || migration || recoveryReview)) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('click', async event => {
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.target === '_blank' || link.getAttribute('href').startsWith('#') || event.ctrlKey || event.metaKey) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol) || (!pending && !running)) return;
        event.preventDefault();
        try { await flush(); location.assign(url.href); } catch {}
    }, true);
    let checking = false;
    async function refreshRemote() {
        if (checking || pending || running || failure || migration || recoveryReview || document.hidden || editing()) return;
        checking = true;
        try {
            const url = new URL(database.url, location.href); url.searchParams.set('revision_only', '1');
            const headers = {'Accept':'application/json','X-Company-ID':String(database.company_id)};
            const response = await fetch(url, {headers, cache:'no-store'});
            if (!response.ok) return;
            const changed = await response.json();
            if (changed.revision === revision || pending || running || failure || editing()) return;
            if (!collaborative) { failure = {message:'Another accountant updated this company. Reload the latest records before editing.',status:409}; render(); return; }
            url.searchParams.delete('revision_only');
            const snapshot = await fetch(url, {headers,cache:'no-store'});
            if (!snapshot.ok) return;
            const result = await snapshot.json();
            if (pending || running || failure || editing() || String(result.company_id) !== String(database.company_id) || !validState(result.state) || !Array.isArray(result.payroll)) return;
            revision=result.revision; Object.assign(database,result); state=clone(result.state); payroll=clone(result.payroll);
            try {sessionStorage.setItem(ledgerKey,JSON.stringify(state));sessionStorage.setItem(payrollKey,JSON.stringify(payroll));} catch {}
            notify(); render();
        } catch {} finally {checking=false;}
    }
    async function reviewConflicts() {
        const response = await fetch(database.url,{headers:{'Accept':'application/json','X-Company-ID':String(database.company_id)},cache:'no-store'});
        if (!response.ok) throw Error('Could not load the latest entries. Your unsaved work is preserved.');
        const latest=await response.json();
        if (String(latest.company_id)!==String(database.company_id) || !validState(latest.state) || !Array.isArray(latest.payroll)) throw Error('The server did not return this company book.');
        const edits=operations();
        const conflicts=edits.filter(op => op.expected_version !== records.version(latest.record_versions,op.sheet,op.id,0));
        const dialog=document.createElement('dialog');dialog.className='company-conflict-dialog';
        const title=document.createElement('h2');title.textContent='Review changes to the same entry';dialog.append(title);
        const explanation=document.createElement('p');explanation.textContent='Choose which changes to keep. Applying choices closes entry dialogs and refreshes the sheet. All saves are checked again by the server.';dialog.append(explanation);
        const choices=[];
        const getRows=(snapshot,sheet)=>sheet==='payroll'?snapshot.payroll:snapshot.state[sheet];
        for (const operation of conflicts) {
            const saved=getRows(latest,operation.sheet).find(row=>String(row.id)===operation.id);
            const section=document.createElement('section'),heading=document.createElement('h3');heading.textContent=operation.sheet+' · '+(saved?.number || operation.record?.number || operation.id);section.append(heading);
            const writer=failure.conflicts?.find(item=>item.sheet===operation.sheet&&String(item.id)===operation.id)?.last_edited_by;
            if(writer){const attribution=document.createElement('p');attribution.textContent='Last saved by '+writer;section.append(attribution);}
            const summary=document.createElement('div');summary.className='company-conflict-values';
            for(const [label,value] of [['Your entry',operation.record],['Latest saved entry',saved]]) {
                const block=document.createElement('div'),caption=document.createElement('strong'),text=document.createElement('pre');caption.textContent=label;
                const money=value=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(value || 0)/100);
                const describe=row=>{
                    if(!row)return 'Entry deleted';
                    const pairs=[['Transaction',row.number || 'Draft'],['Date',row.date],['Status',row.status],['Party',row.customer || row.supplier],['Reference',row.reference || row.invoice || row.bill],['Amount',row.gross!==undefined?money(row.gross):row.amount!==undefined?money(row.amount):null],['Account',row.account],['Cash / bank',row.bank],['Terms',row.terms],['Description',row.memo || row.description || row.particulars || row.notes],['Attachments',row.attachments?.map(file=>file.name).join(', ')]];
                    if(row.lines?.length)pairs.push(['Journal lines',row.lines.map(line=>line.account+': Debit '+money(line.debit)+' / Credit '+money(line.credit)).join('\n')]);
                    if(row.employees)pairs.push(['Payroll',row.periodStart+' to '+row.periodEnd+'; '+row.employees.length+' employees']);
                    return pairs.filter(([,value])=>value!==undefined&&value!==null&&value!=='').map(([label,value])=>label+': '+value).join('\n').slice(0,4000);
                };
                text.textContent=describe(value);block.append(caption,text);summary.append(block);
            }
            section.append(summary);
            const select=document.createElement('select');select.setAttribute('aria-label','Resolution for '+operation.id);
            select.add(new Option('Use the latest saved entry','latest'));if(saved)select.add(new Option('Keep my changes and retry validation','mine'));section.append(select);choices.push({operation,select});dialog.append(section);
        }
        if (!conflicts.length) {const message=document.createElement('p');message.textContent='These entries can be retried against the latest book.';dialog.append(message);}
        const controls=document.createElement('div'),cancel=document.createElement('button'),apply=document.createElement('button');
        cancel.type=apply.type='button';cancel.textContent='Keep reviewing';apply.textContent='Apply choices and retry';controls.append(cancel,apply);dialog.append(controls);
        cancel.addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>dialog.remove());
        apply.addEventListener('click',()=>{
            const merged=records.rebase(database.state,database.payroll,state,payroll,latest.state,latest.payroll);
            for(const {operation,select} of choices) {
                if(select.value==='mine') continue;
                const saved=getRows(latest,operation.sheet).find(row=>String(row.id)===operation.id);
                let rows=getRows(merged,operation.sheet).filter(row=>String(row.id)!==operation.id);
                if(saved) rows.push(clone(saved));
                if(operation.sheet==='payroll') merged.payroll=rows; else merged.state[operation.sheet]=rows;
            }
            state=merged.state;payroll=merged.payroll;revision=latest.revision;Object.assign(database,latest);retry=null;failure=null;pending=operations().length>0;
            try { if(pending) stash(); else sessionStorage.removeItem(recoveryKey); } catch {failure={status:0,message:'Could not preserve the reviewed changes. Download your unsaved work.'};}
            for(const open of document.querySelectorAll('dialog[open]')) open.close();
            notify();render();if(pending&&!failure)flush().catch(()=>{});
        });
        document.body.append(dialog);dialog.showModal();
    }
    setInterval(refreshRemote,5000);
    window.addEventListener('focus',refreshRemote);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshRemote();});
    render();
})();
