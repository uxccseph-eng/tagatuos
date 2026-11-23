(() => {
    'use strict';
    const configNode = document.getElementById('workspace-presence-config');
    const sidebar = document.querySelector('#sales-sidebar, #sidebarNav, #workspace-app-sidebar');
    if (!configNode || !sidebar || document.getElementById('working-now-panel')) return;
    const config = JSON.parse(configNode.textContent);
    let slot = sidebar.querySelector('.workspace-sidebar-foot');
    if (!slot && sidebar.id === 'sales-sidebar') {
        const candidate = sidebar.lastElementChild;
        if (candidate?.tagName === 'DIV' && !candidate.querySelector('nav')) slot = candidate;
    }
    if (!slot) { slot = document.createElement('div'); sidebar.append(slot); }
    slot.classList.add('working-now-slot');
    const element = (tag, className, text) => {
        const node = document.createElement(tag); node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const panel = element('section', 'working-now-panel'); panel.id = 'working-now-panel'; panel.setAttribute('aria-label', 'Working now');
    const heading = element('div', 'working-now-heading');
    const title = element('strong', 'working-now-title', 'Working now');
    const count = element('span', 'working-now-count', '');
    count.setAttribute('role', 'status'); count.setAttribute('aria-atomic', 'true');
    heading.append(title, count);
    const list = element('ul', 'working-now-list');
    const message = element('p', 'working-now-message', 'Checking team activity…');
    const expand = element('button', 'working-now-expand', 'View team activity'); expand.type = 'button'; expand.disabled = true;
    panel.append(heading, list, message, expand); slot.replaceChildren(panel);
    const dialog = element('dialog', 'working-now-dialog'); dialog.id = 'working-now-dialog'; dialog.setAttribute('aria-labelledby', 'working-now-dialog-title');
    const dialogHeader = document.createElement('header'), dialogTitle = element('h2', '', 'Team activity'); dialogTitle.id = 'working-now-dialog-title';
    const close = element('button', 'working-now-close', 'Close'); close.type = 'button'; close.addEventListener('click', () => dialog.close());
    dialogHeader.append(dialogTitle, close);
    const help = element('p', 'working-now-help', 'Recent activity in the company books you can access. Updates every 30 seconds.');
    const fullList = element('ul', 'working-now-list working-now-full-list');
    dialog.append(dialogHeader, help, fullList); document.body.append(dialog);
    const text = value => typeof value === 'string' ? value : '';
    function row(member) {
        const item = element('li', 'working-now-person');
        const initials = member.name.trim().split(/\s+/).slice(0, 2).map(part => [...part][0]).join('').toUpperCase();
        const avatar = element('span', 'working-now-avatar', initials); avatar.setAttribute('aria-hidden', 'true'); avatar.dataset.status = member.status;
        const detail = element('div', 'working-now-person-detail'), line = element('div', 'working-now-person-heading');
        const name = element('span', 'working-now-name', member.name + (member.is_you ? ' (you)' : '')); name.title = name.textContent;
        const status = element('span', 'working-now-person-status', member.status === 'online' ? 'Online' : 'Away');
        line.append(name, status);
        const location = [text(member.company_name), text(member.section)].filter(Boolean).join(' · ');
        const book = element('span', 'working-now-book', location); book.title = location;
        detail.append(line, book); item.append(avatar, detail); return item;
    }
    let members = [], signature = null, failed = false;
    function render(next) {
        const nextSignature = JSON.stringify(next);
        const recovering = failed; failed = false;
        if (!recovering && signature === nextSignature) return;
        signature = nextSignature; members = next;
        count.textContent = members.filter(member => member.status === 'online').length + ' online';
        list.replaceChildren(...members.slice(0, 2).map(row));
        message.hidden = members.length > 0; message.textContent = 'No teammates online right now.';
        fullList.replaceChildren(...members.map(row));
        if (!members.length) fullList.append(element('li', 'working-now-message', 'No recent team activity.'));
        expand.disabled = false; expand.textContent = 'View team activity';
    }
    function unavailable() {
        failed = true; signature = null; count.textContent = 'Unavailable';
        list.replaceChildren(); fullList.replaceChildren(element('li', 'working-now-message', 'Team status is temporarily unavailable.'));
        message.hidden = false; message.textContent = 'Team status is temporarily unavailable.';
        expand.disabled = false; expand.textContent = 'Retry team status';
    }
    let tabId;
    try {
        tabId = sessionStorage.getItem('kontadurya.presence.tab.v1');
        if (!tabId || !/^[0-9a-f-]{36}$/i.test(tabId)) {
            tabId = crypto.randomUUID(); sessionStorage.setItem('kontadurya.presence.tab.v1', tabId);
        }
    } catch { tabId = crypto.randomUUID(); }
    function locationInfo() {
        const path = location.pathname;
        const bookId = Number(document.querySelector('meta[name="tenant-company"]')?.content || config.companyId || path.match(/^\/account\/companies\/(\d+)/)?.[1]) || null;
        const sheet = path.match(/^\/sheets\/(sales|collections|purchases|payments|cwt|wtp|payroll|journal)(?:\/|$)/)?.[1];
        if (bookId && sheet) return {company_id: bookId, section: sheet};
        if (bookId) {
            const sections = {'/company/setup': 'setup', '/dashboard': 'dashboard', '/books': 'books', '/masters': 'masters', '/reports': 'reports', '/tax-reports': 'tax-reports', '/tax-returns': 'tax-returns', '/tax-summary': 'tax-summary', '/settings': 'settings', '/lapsing': 'lapsing'};
            for (const [prefix, section] of Object.entries(sections)) if (path === prefix || path.startsWith(prefix + '/')) return {company_id: bookId, section};
            if (/^\/account\/companies\/\d+\/documents(?:\/|$)/.test(path)) return {company_id: bookId, section: 'documents'};
            if (/^\/account\/companies\/\d+\/follow-ups(?:\/|$)/.test(path)) return {company_id: bookId, section: 'follow-ups'};
            if (/^\/account\/companies\/\d+\/closing(?:\/|$)/.test(path)) return {company_id: bookId, section: 'closing'};
        }
        return {company_id: null, section: path === '/my-work' ? 'my-work' : /^\/account(?:\/companies)?\/?$/.test(path) ? 'clients' : 'account'};
    }
    let lastActivity = Date.now(), busy = false, stopped = false, queued = false;
    const activity = () => { lastActivity = Date.now(); };
    for (const name of ['pointerdown', 'keydown', 'wheel']) document.addEventListener(name, activity, {passive: true});
    let lastPointer = 0;
    document.addEventListener('pointermove', () => { const now = Date.now(); if (now - lastPointer > 1000) { lastPointer = now; lastActivity = now; } }, {passive: true});
    async function refresh(force = false) {
        if (busy) { queued = queued || force; return; }
        if (stopped || (document.hidden && !force)) return;
        busy = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(config.url, {method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: force, signal: controller.signal,
                headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf},
                body: JSON.stringify({...locationInfo(), tab_id: tabId, idle: Date.now() - lastActivity >= 300000, visible: !document.hidden && document.hasFocus()})});
            if ([401, 403, 419].includes(response.status)) stopped = true;
            if (!response.ok) throw Error('Team status unavailable');
            const payload = await response.json();
            if (!Array.isArray(payload.members) || payload.members.some(member => typeof member.name !== 'string' || !['online', 'away'].includes(member.status))) throw Error('Invalid team status');
            render(payload.members);
        } catch { unavailable(); }
        finally { clearTimeout(timeout); busy = false; if (queued) { queued = false; refresh(true); } }
    }
    expand.addEventListener('click', () => {
        if (failed) { stopped = false; refresh(true); }
        else if (!dialog.open) dialog.showModal();
    });
    document.addEventListener('visibilitychange', () => refresh(true));
    window.addEventListener('focus', () => { activity(); refresh(true); });
    window.addEventListener('blur', () => refresh(true));
    window.addEventListener('online', () => { stopped = false; refresh(); });
    let timer = setInterval(() => refresh(), 30000);
    window.addEventListener('pagehide', () => clearInterval(timer));
    window.addEventListener('pageshow', event => {
        if (event.persisted) { timer = setInterval(() => refresh(), 30000); refresh(true); }
    });
    refresh();
})();
