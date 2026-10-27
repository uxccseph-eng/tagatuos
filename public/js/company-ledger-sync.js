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
    let state = clone(database.state), payroll = clone(database.payroll), revision = database.revision;
    let pending = false, running = null, timer = null, failure = null, migration = false, retry = null;
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
    if (recovery && !migration) failure = {message: 'This tab contains unsaved work from an earlier database save. Download it before reloading or continuing.', status: 409};
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
            if (failure.status !== 409) button('Retry save', () => { failure = null; pending = true; flush().catch(() => {}); });
            button('Download and reload', () => { download(); try { sessionStorage.removeItem(recoveryKey); } catch {} location.reload(); });
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
    }
    function stash() {
        sessionStorage.setItem(recoveryKey, JSON.stringify({company_id: database.company_id, revision, state, payroll}));
    }
    function stage(section, next) {
        if (!database.canWrite) throw Error('You do not have permission to save Sheet transactions.');
        if (failure?.status === 409) throw Error(failure.message);
        const previousState = state, previousPayroll = payroll;
        if (section === 'state') state = clone(next); else payroll = clone(next);
        retry = null; failure = null;
        try { stash(); }
        catch { state = previousState; payroll = previousPayroll; throw Error('This tab could not preserve the pending save. Your last confirmed database records are unchanged.'); }
        if (!migration) { pending = true; clearTimeout(timer); timer = setTimeout(() => flush().catch(() => {}), 300); }
        render();
    }
    async function flush() {
        clearTimeout(timer);
        if (running) { await running; return pending ? flush() : undefined; }
        if (failure) throw Error(failure.message);
        if (migration || !pending) return;
        const payload = retry || {revision, request_id: crypto.randomUUID(), state: clone(state), payroll: clone(payroll)};
        retry = payload; pending = false;
        running = (async () => {
            const response = await fetch(database.url, {method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': database.csrf, 'X-Company-ID': String(database.company_id)}, body: JSON.stringify(payload)});
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'The server could not save this company book.';
                failure = {message, status: response.status};
                throw Error(message);
            }
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
            throw error;
        }).finally(() => { running = null; render(); });
        render();
        await running;
        if (pending) await flush();
    }
    window.CompanyLedger = {state: () => clone(state), payroll: () => clone(payroll), journals: () => clone(database.journals), saveState: next => stage('state', next), savePayroll: next => stage('payroll', next), flush, pending: () => pending || Boolean(running) || Boolean(failure) || migration};
    window.addEventListener('beforeunload', event => { if (pending || running || failure || migration) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('click', async event => {
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.target === '_blank' || link.getAttribute('href').startsWith('#') || event.ctrlKey || event.metaKey) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol) || (!pending && !running)) return;
        event.preventDefault();
        try { await flush(); location.assign(url.href); } catch {}
    }, true);
    setInterval(async () => {
        if (pending || running || failure || migration || document.hidden) return;
        try {
            const response = await fetch(database.url + '?revision_only=1', {headers: {'Accept': 'application/json', 'X-Company-ID': String(database.company_id)}});
            if (!response.ok) return;
            const result = await response.json();
            if (result.revision !== revision) { failure = {message: 'Another accountant updated this company. Reload the latest records before editing.', status: 409}; render(); }
        } catch {}
    }, 15000);
    render();
})();
