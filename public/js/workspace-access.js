(() => {
    const element = document.getElementById('workspace-access-config');
    if (!element) return;
    const access = JSON.parse(element.textContent);
    const modules = ['dashboard', 'sheets', 'books', 'lapsing', 'masters', 'reports', 'tax-reports', 'settings'];
    const isWorkspaceUrl = value => {
        const url = new URL(value, location.href);
        return url.origin === location.origin && (modules.includes(url.pathname.split('/').filter(Boolean)[0]) || url.pathname === '/company/setup');
    };
    const bindForm = form => {
        if (!isWorkspaceUrl(form.action) || form.method.toLowerCase() === 'get') return;
        let field = form.querySelector('input[name="_company_id"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = '_company_id';
            form.appendChild(field);
        }
        field.value = String(access.companyId);
    };
    document.querySelectorAll('form[action]').forEach(bindForm);
    document.addEventListener('submit', event => {
        if (event.target instanceof HTMLFormElement) bindForm(event.target);
    }, true);
    const fetch = window.fetch.bind(window);
    window.fetch = (input, options = {}) => {
        const url = input instanceof Request ? input.url : input;
        if (!isWorkspaceUrl(url)) return fetch(input, options);
        const headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));
        headers.set('X-Company-ID', String(access.companyId));
        const path = new URL(url, location.href).pathname;
        const method = (options.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        if (window.CompanyLedger && method === 'POST' && /^\/(?:reports|tax-reports)\//.test(path)) {
            return window.CompanyLedger.flush().then(() => {
                if (window.CompanyLedger.pending()) throw Error('Transfer or recover the pending browser records before exporting this company book.');
                headers.set('X-Ledger-Revision', String(window.CompanyLedgerDatabase.revision));
                return fetch(input, {...options, headers});
            });
        }
        return fetch(input, {...options, headers});
    };
    document.querySelectorAll('a[href]').forEach(link => {
        const url = new URL(link.getAttribute('href'), location.href);
        if (url.origin !== location.origin) return;
        const module = url.pathname.split('/').filter(Boolean)[0];
        if ((modules.includes(module) && !access.tabs.includes(module)) || (module === 'team' && access.role !== 'owner')) { link.hidden = true; link.style.display = 'none'; }
    });
    if (access.role !== 'owner') {
        document.querySelectorAll('form[action]').forEach(form => {
            const url = new URL(form.action, location.href);
            if (url.origin === location.origin && url.pathname.startsWith('/settings/')) {
                form.querySelectorAll('input,select,textarea,button').forEach(control => { control.disabled = true; });
            }
        });
    }
})();
