(() => {
    'use strict';
    const companyId = document.querySelector('meta[name="tenant-company"]')?.content;
    if (!/^[1-9][0-9]*$/.test(companyId || '')) return;
    const scoped = value => {
        const url = new URL(value, location.href);
        return url.origin === location.origin && /^\/(?:company\/setup|dashboard|sheets|books|masters|reports|tax-reports|settings|lapsing)(?:\/|$)/.test(url.pathname) ? url : null;
    };
    const pin = value => {
        const url = scoped(value);
        if (url && !url.searchParams.has('company_id')) url.searchParams.set('company_id', companyId);
        return url;
    };
    const current = pin(location.href);
    if (current) history.replaceState(history.state, '', current.href);
    const rewrite = link => {
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#')) return;
        const url = pin(link.href);
        if (url) link.href = url.href;
    };
    document.querySelectorAll('a[href]').forEach(rewrite);
    for (const type of ['click', 'auxclick', 'contextmenu']) document.addEventListener(type, event => {
        const link = event.target.closest?.('a[href]');
        if (link) rewrite(link);
    }, true);
    const bindForm = form => {
        const url = pin(form.action || location.href);
        if (!url) return;
        form.action = url.href;
        let field = form.querySelector('input[name="_company_id"]');
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = '_company_id'; form.append(field); }
        if (!field.value) field.value = companyId;
    };
    document.querySelectorAll('form').forEach(bindForm);
    document.addEventListener('submit', event => bindForm(event.target), true);
    const original = window.fetch;
    window.fetch = (input, options = {}) => {
        const request = input instanceof Request;
        const url = pin(request ? input.url : String(input));
        if (!url) return original.call(window, input, options);
        const headers = new Headers(request ? input.headers : undefined);
        new Headers(options.headers).forEach((value, key) => headers.set(key, value));
        if (!headers.has('X-Company-ID')) headers.set('X-Company-ID', companyId);
        return original.call(window, request ? new Request(url.href, input) : url.href, {...options, headers});
    };
})();
