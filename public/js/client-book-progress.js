(() => {
    const root = document.querySelector('[data-client-books]');
    if (!root) return;
    const cards = new Map(Array.from(root.querySelectorAll('[data-client-book]')).map(card => [String(card.dataset.clientBook), card]));
    const active = status => ['pending', 'provisioning', 'deleting'].includes(status);
    let timer = null, inFlight = false, stopped = false, retryDelay = 4000;

    function render(card, data) {
        const previous = card.dataset.setupStatus;
        const status = data.setup_status;
        const busy = active(status), failed = ['failed', 'delete_failed'].includes(status);
        const ready = data.database_ready === true;
        card.dataset.setupStatus = status;
        card.dataset.databaseReady = String(ready);
        const badge = card.querySelector('[data-client-status]');
        if (badge) badge.textContent = {deleting: 'Deleting client', delete_failed: 'Deletion needs retry'}[status] || status.charAt(0).toUpperCase() + status.slice(1) + ' database';
        const progress = card.querySelector('[data-client-progress]');
        if (progress) {
            const completed = ready && active(previous);
            progress.hidden = !(busy || failed || completed);
            progress.classList.toggle('is-failed', failed);
            progress.classList.toggle('is-complete', completed);
            const bar = progress.querySelector('[data-progress-bar]');
            bar.hidden = !(busy || completed);
            bar.setAttribute('aria-label', status === 'deleting' ? 'Client deletion in progress' : completed ? 'Client setup complete' : 'Client setup in progress');
            if (completed) bar.setAttribute('aria-valuenow', '100');
            else bar.removeAttribute('aria-valuenow');
            const caption = data.setup_message || {pending: 'Waiting for client setup to start…', provisioning: 'Preparing the client database and private folder…', deleting: 'Deleting the client database and private folder…', failed: 'Client setup failed. Retry setup to continue.', delete_failed: 'Client deletion needs attention. Retry deletion to continue.', ready: 'Client database ready. You can open the book.'}[status] || '';
            const message = progress.querySelector('[data-progress-message]');
            if (message.textContent !== caption) message.textContent = caption;
            progress.querySelector('[data-progress-connection]').hidden = true;
        }
        const open = card.querySelector('[data-client-open]');
        if (open) open.hidden = !ready;
        const retry = card.querySelector('[data-client-retry]');
        if (retry) retry.hidden = status !== 'failed' || !(data.can_retry_setup ?? data.role === 'owner');
        const team = card.querySelector('[data-client-team]');
        if (team) team.hidden = ['deleting', 'delete_failed'].includes(status) || data.role !== 'owner';
        const remove = card.querySelector('[data-client-delete]');
        if (remove) {
            remove.hidden = status === 'deleting' || data.role !== 'owner';
            remove.textContent = status === 'delete_failed' ? 'Retry deletion' : 'Delete client';
        }
    }

    const pending = () => Array.from(cards.values()).filter(card => active(card.dataset.setupStatus));
    function schedule() {
        clearTimeout(timer);
        if (!stopped && !inFlight && !document.hidden && pending().length) timer = setTimeout(poll, retryDelay);
    }
    async function poll() {
        const tracked = pending();
        if (stopped || inFlight || document.hidden || !tracked.length) return;
        inFlight = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const url = new URL(root.dataset.statusUrl, window.location.href);
            tracked.forEach(card => url.searchParams.append('ids[]', card.dataset.clientBook));
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if ([401, 403].includes(response.status) || response.redirected) {
                stopped = true;
                throw Error('Your session changed. Reload this page to continue.');
            }
            if (!response.ok) throw Error('Connection interrupted. Checking again automatically…');
            const payload = await response.json();
            if (!Array.isArray(payload.data) || payload.data.some(item => typeof item.setup_status !== 'string' || !Number.isInteger(item.id) || typeof item.database_ready !== 'boolean')) throw Error('Could not read setup status. Checking again automatically…');
            const results = new Map(payload.data.map(item => [String(item.id), item]));
            tracked.forEach(card => {
                const data = results.get(card.dataset.clientBook);
                if (data) render(card, data);
                else {
                    cards.delete(card.dataset.clientBook);
                    card.remove();
                    let notice = document.querySelector('[data-client-list-notice]');
                    if (!notice) {notice = document.createElement('div'); notice.className = 'notice'; notice.dataset.clientListNotice = ''; notice.setAttribute('role', 'status'); root.before(notice);}
                    notice.textContent = 'A company book was removed from your list.';
                    const count = document.querySelector('.count-badge strong');
                    if (count) {
                        const remaining = Math.max(0, Number(count.textContent) - 1);
                        count.textContent = remaining;
                        count.nextElementSibling.textContent = remaining === 1 ? 'assigned book' : 'assigned books';
                    }
                }
            });
            retryDelay = 4000;
        } catch (error) {
            tracked.forEach(card => {
                const notice = card.querySelector('[data-progress-connection]');
                if (notice) { notice.hidden = false; notice.textContent = stopped ? error.message : 'Connection interrupted. Checking again automatically…'; }
            });
            retryDelay = Math.min(retryDelay * 2, 30000);
        } finally {
            clearTimeout(timeout);
            inFlight = false;
            schedule();
        }
    }
    root.querySelectorAll('[data-client-create], [data-client-retry]').forEach(form => {
        form.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            if (form.dataset.submitting === 'true') {event.preventDefault(); return;}
            form.dataset.submitting = 'true';
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = form.matches('[data-client-create]') ? 'Creating book…' : 'Queuing setup…';
            const progress = form.querySelector('[data-client-progress]');
            if (progress) {
                progress.hidden = false;
                progress.querySelector('[data-progress-bar]').hidden = false;
                progress.querySelector('[data-progress-message]').textContent = 'Creating your company book…';
            }
        });
    });
    window.addEventListener('pageshow', () => {
        root.querySelectorAll('[data-client-create], [data-client-retry]').forEach(form => {delete form.dataset.submitting;});
        const createProgress = root.querySelector('[data-client-create] [data-client-progress]');
        if (createProgress) createProgress.hidden = true;
        root.querySelectorAll('[data-client-create] button[type="submit"], [data-client-retry] button[type="submit"]').forEach(button => {button.disabled = false; button.textContent = button.closest('[data-client-create]') ? '+ Create book' : 'Retry setup';});
        schedule();
    });
    document.addEventListener('visibilitychange', schedule);
    schedule();
})();
