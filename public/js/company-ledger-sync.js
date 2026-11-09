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
    let recoveryExported = false, conflictDialog = null, resolutionRunning = false, dismissedFailure = null;
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
    let localRecoveryPending = Boolean(validRecovery && !restoreRequested);
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
        clearRecovery();
        recoveryExported = true;
        restoreAvailable = false;
        render();
        showConflictDialog();
    }
    function clearRecovery() {
        // Clear only this company's recovery keys; never clear another book or login session.
        for (const storage of [sessionStorage, window.localStorage]) {
            if (!storage) continue;
            for (const key of [recoveryKey, restoreKey, ledgerKey, payrollKey, ledgerKey + '.before-database-transfer']) storage.removeItem(key);
        }
    }
    function restoreRecovery() {
        if (!canRestore) return;
        state = clone(preserved.state); payroll = clone(preserved.payroll);
        if (recoverableOperations) {
            database.state = clone(preserved.base_state); database.payroll = clone(preserved.base_payroll);
            database.record_versions = clone(preserved.base_versions); revision = preserved.revision;
        }
        const request = preserved.request;
        retry = request && request.revision === revision && typeof request.request_id === 'string' && validState(request.state) && Array.isArray(request.payroll) && (!collaborative || Array.isArray(request.operations)) ? clone(request) : null;
        failure = null; restoreAvailable = false; localRecoveryPending = false; recoveryReview = true; pending = true;
        for (const open of document.querySelectorAll?.('dialog[open]') || []) open.close();
        notify(); render();
    }
    function render() {
        actions.replaceChildren();
        document.documentElement.classList.toggle('company-save-pending', pending || Boolean(running) || Boolean(failure) || migration);
        if (failure) {
            label.textContent = failure.record_conflict ? 'Changes need review: another accountant updated this entry. Your changes are kept in this tab. Choose how to resolve the unsaved changes.' : 'Not saved to the database: ' + failure.message;
            banner.dataset.state = failure.record_conflict ? 'warning' : 'error';
            button('Export unsaved work (.json)', download);
            if (restoreAvailable) button('Restore unsaved work', restoreRecovery);
            button('Resolve save conflict', showConflictDialog);
            if (failure.status !== 409) button('Retry save', () => { failure = null; pending = true; flush().catch(() => {}); });
            if (failure.status === 409 && failure !== dismissedFailure) showConflictDialog();
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
        if (recoveryExported) return;
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
        recoveryExported = false;
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
            state = clone(later?.state || (pending && !collaborative ? state : result.state)); payroll = clone(later?.payroll || (pending && !collaborative ? payroll : result.payroll));
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
    function showConflictDialog() {
        if (!failure || conflictDialog) return;
        const dialog = document.createElement('dialog');
        conflictDialog = dialog;
        dialog.className = 'company-conflict-dialog'; dialog.setAttribute('aria-label', 'Resolve unsaved changes');
        const title = document.createElement('h2'); title.textContent = 'Resolve unsaved changes';
        const explanation = document.createElement('p');
        explanation.textContent = 'Discard Local loads the latest saved book and removes this tab’s draft. Force Overwrite keeps your changed entries and replaces their saved versions. Other entries stay unchanged. Permissions and posting checks still apply.';
        const message = document.createElement('p'); message.setAttribute('aria-live', 'polite'); message.textContent = failure.message;
        const controls = document.createElement('div');
        controls.className = 'company-conflict-actions';
        const discard = document.createElement('button'), overwrite = document.createElement('button');
        discard.className = 'company-conflict-reload'; overwrite.className = 'company-conflict-overwrite';
        discard.type = overwrite.type = 'button';
        discard.textContent = 'Discard Local & Reload Latest'; overwrite.textContent = 'Force Overwrite Database';
        overwrite.disabled = !database.canWrite || (!collaborative && restoreAvailable && !validRecovery);
        controls.append(discard, overwrite); dialog.append(title, explanation, message, controls);
        const exportButton = document.createElement('button'); exportButton.type = 'button'; exportButton.textContent = 'Export unsaved work (.json)';
        exportButton.className = 'company-conflict-export';
        exportButton.addEventListener('click', download); dialog.append(exportButton);
        discard.addEventListener('click', () => {
            if (resolutionRunning) return;
            try {
                clearRecovery(); clearTimeout(timer); pending = false; retry = null; failure = null; leaving = true;
                location.reload();
            } catch { message.textContent = 'Could not clear the local draft. Keep this tab open and try again.'; }
        });
        overwrite.addEventListener('click', async () => {
            if (resolutionRunning || !database.canWrite) return;
            resolutionRunning = true; discard.disabled = overwrite.disabled = true;
            try {
                await overwriteChanges();
                dialog.close(); notify(); render();
            } catch (error) { message.textContent = error.message; }
            finally { resolutionRunning = false; discard.disabled = false; overwrite.disabled = !database.canWrite; }
        });
        dialog.addEventListener('cancel', event => { if (resolutionRunning) event.preventDefault(); });
        dialog.addEventListener('close', () => { dismissedFailure = failure; conflictDialog = null; dialog.remove(); });
        document.body.append(dialog); dialog.showModal();
    }
    async function overwriteChanges() {
        if (!database.canWrite) throw Error('You do not have permission to save Sheet transactions.');
        if (running) await running.catch(() => {});
        const response = await fetch(database.url, {headers: {'Accept': 'application/json', 'X-Company-ID': String(database.company_id)}, cache: 'no-store'});
        if (!response.ok) throw Error('Could not load the latest saved book. Your changes remain in this tab.');
        const latest = await response.json();
        if (String(latest.company_id) !== String(database.company_id) || !Number.isInteger(latest.revision) || latest.revision < revision || !validState(latest.state) || !Array.isArray(latest.payroll) || (collaborative && !latest.record_versions)) throw Error('The server did not return a valid version of this company book.');
        const recovering = localRecoveryPending && validRecovery;
        if (recovering && collaborative && !recoverableOperations) throw Error('This older recovery has no entry history. Restore and review it before saving.');
        const localState = recovering ? preserved.state : state, localPayroll = recovering ? preserved.payroll : payroll;
        const baseState = recovering ? preserved.base_state : database.state, basePayroll = recovering ? preserved.base_payroll : database.payroll;
        const merged = {state: clone(latest.state), payroll: clone(latest.payroll)};
        if (collaborative) {
            // Replace only entries this tab changed, using the freshly fetched versions for the normal atomic save.
            for (const operation of records.changes(baseState, basePayroll, localState, localPayroll, latest.record_versions)) {
                const rows = operation.sheet === 'payroll' ? merged.payroll : merged.state[operation.sheet];
                const index = rows.findIndex(row => String(row.id) === operation.id);
                if (operation.record === null) { if (index >= 0) rows.splice(index, 1); }
                else if (index >= 0) rows[index] = clone(operation.record);
                else rows.push(clone(operation.record));
            }
        } else {
            throw Error('This book does not support safe entry-level overwrite. Discard Local and reload the latest book.');
        }
        revision = latest.revision; Object.assign(database, latest); state = merged.state; payroll = merged.payroll;
        retry = null; failure = null; recoveryReview = false; migration = false; restoreAvailable = false;
        localRecoveryPending = false;
        pending = operations().length > 0;
        if (pending) stash();
        else clearRecovery();
        await flush();
        for (const open of document.querySelectorAll?.('dialog[open]') || []) { if (open !== conflictDialog) open.close(); }
        notify(); render();
    }
    setInterval(refreshRemote,5000);
    window.addEventListener('focus',refreshRemote);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshRemote();});
    render();
})();
