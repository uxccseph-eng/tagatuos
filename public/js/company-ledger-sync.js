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
    const same = (first, second) => JSON.stringify(first) === JSON.stringify(second);
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
    const canRestore = validRecovery && preserved.revision === revision && database.canWrite;
    if (recovery) {
        migration = false; state = clone(database.state); payroll = clone(database.payroll);
        if (validRecovery && same(preserved.state, state) && same(preserved.payroll, payroll)) {
            try { sessionStorage.removeItem(recoveryKey); } catch {}
        } else if (restoreRequested && canRestore) {
            state = clone(preserved.state); payroll = clone(preserved.payroll); pending = true; recoveryReview = true;
            const request = preserved.request;
            if (request && request.revision === revision && typeof request.request_id === 'string' && validState(request.state) && Array.isArray(request.payroll)) retry = clone(request);
            failure = null;
        } else {
            restoreAvailable = Boolean(canRestore);
            const reason = validRecovery && preserved.revision !== revision ? 'The database has a different version. Download the unsaved work and compare it with the saved book; it cannot safely overwrite newer records.' : 'This tab contains unsaved work from an earlier database save. Restore it to review and save, or download a backup.';
            failure = {message: reason + (preserved?.error?.message ? ' Last save error: ' + String(preserved.error.message) : ''), status: 409};
        }
    }
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
        sessionStorage.setItem(recoveryKey, JSON.stringify({company_id: database.company_id, revision, state, payroll, request: retry, error: failure}));
    }
    function stage(section, next) {
        if (!database.canWrite) throw Error('You do not have permission to save Sheet transactions.');
        if (failure?.status === 409) throw Error(failure.message);
        const previousState = state, previousPayroll = payroll, previousFailure = failure, previousRetry = retry;
        if (section === 'state') state = clone(next); else payroll = clone(next);
        if (!running && failure?.status) retry = null;
        failure = null;
        try { stash(); }
        catch { state = previousState; payroll = previousPayroll; failure = previousFailure; retry = previousRetry; throw Error('This tab could not preserve the pending save. Your last confirmed database records are unchanged.'); }
        if (!migration && !recoveryReview) { pending = true; clearTimeout(timer); timer = setTimeout(() => flush().catch(() => {}), 300); }
        render();
    }
    async function flush() {
        clearTimeout(timer);
        if (running) { await running; return pending || running ? flush() : undefined; }
        if (failure) throw Error(failure.message);
        if (recoveryReview) throw Error('Review the restored entries, then click Save recovered work.');
        if (migration || !pending) return;
        const payload = retry || {revision, request_id: crypto.randomUUID(), state: clone(state), payroll: clone(payroll)};
        retry = payload; pending = !same(payload.state, state) || !same(payload.payroll, payroll);
        try { stash(); } catch { pending = true; failure = {message: 'Could not preserve this save request. Download the unsaved work before continuing.', status: 0}; render(); throw Error(failure.message); }
        running = (async () => {
            const response = await fetch(database.url, {method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': database.csrf, 'X-Company-ID': String(database.company_id)}, body: JSON.stringify(payload)});
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'The server could not save this company book.';
                failure = {message, status: response.status};
                throw Error(message);
            }
            if (String(result.company_id) !== String(database.company_id) || !Number.isInteger(result.revision) || result.revision <= payload.revision || !validState(result.state) || !Array.isArray(result.payroll)) throw Error('The server did not confirm the saved company records. Keep this tab open and retry the save.');
            revision = result.revision;
            Object.assign(database, result);
            if (!pending) {
                state = clone(result.state); payroll = clone(result.payroll);
                sessionStorage.removeItem(recoveryKey);
                try { sessionStorage.setItem(ledgerKey, JSON.stringify(state)); sessionStorage.setItem(payrollKey, JSON.stringify(payroll)); } catch {}
            } else { stash(); }
            retry = null;
            window.dispatchEvent(new CustomEvent('company-ledger:saved', {detail: result}));
        })().catch(error => {
            if (!failure) failure = {message: error.message || 'Network unavailable. Unsaved work remains in this tab.', status: 0};
            try { stash(); } catch {}
            throw error;
        }).finally(() => { running = null; render(); });
        render();
        await running;
        if (pending) await flush();
    }
    window.CompanyLedger = {state: () => clone(state), payroll: () => clone(payroll), journals: () => clone(database.journals), saveState: next => stage('state', next), savePayroll: next => stage('payroll', next), flush, postingLabel, decorateBadge, pending: () => pending || Boolean(running) || Boolean(failure) || migration || recoveryReview};
    window.addEventListener('beforeunload', event => { if (!leaving && (pending || running || failure || migration || recoveryReview)) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('click', async event => {
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.target === '_blank' || link.getAttribute('href').startsWith('#') || event.ctrlKey || event.metaKey) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol) || (!pending && !running)) return;
        event.preventDefault();
        try { await flush(); location.assign(url.href); } catch {}
    }, true);
    setInterval(async () => {
        if (pending || running || failure || migration || recoveryReview || document.hidden) return;
        try {
            const response = await fetch(database.url + '?revision_only=1', {headers: {'Accept': 'application/json', 'X-Company-ID': String(database.company_id)}});
            if (!response.ok) return;
            const result = await response.json();
            if (result.revision !== revision) { failure = {message: 'Another accountant updated this company. Reload the latest records before editing.', status: 409}; render(); }
        } catch {}
    }, 15000);
    render();
})();
