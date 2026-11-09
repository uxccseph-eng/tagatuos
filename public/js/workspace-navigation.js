(() => {
    const sidebar = document.getElementById('workspace-app-sidebar');
    const toggle = document.getElementById('workspace-nav-toggle');
    if (!sidebar || !toggle) return;
    const mobile = window.matchMedia('(max-width: 1023px)');
    const backdrop = document.querySelector('.workspace-nav-backdrop');
    let open = false;
    function update(restoreFocus = false) {
        const expanded = mobile.matches && open;
        document.body.classList.toggle('workspace-nav-open', expanded);
        toggle.setAttribute('aria-expanded', String(expanded));
        sidebar.inert = mobile.matches && !expanded;
        backdrop.hidden = !expanded;
        for (const element of document.querySelectorAll('#account-workspace-main, .workspace-app-header, .account-footer')) element.inert = expanded;
        if (restoreFocus) toggle.focus();
    }
    toggle.addEventListener('click', () => { open = !open; update(); if (open) sidebar.querySelector('[data-workspace-close]').focus(); });
    document.querySelectorAll('[data-workspace-close]').forEach(button => button.addEventListener('click', () => { open = false; update(true); }));
    sidebar.addEventListener('click', event => { if (event.target.closest('a[href]') && open) { open = false; update(); } });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && window.innerWidth > 650) {
            event.preventDefault(); document.querySelector('.workspace-header-search input')?.focus();
        }
        if (!mobile.matches || !open) return;
        if (event.key === 'Escape') { event.preventDefault(); open = false; update(true); }
        if (event.key === 'Tab') {
            const items = [...sidebar.querySelectorAll('a[href], button:not(:disabled)')].filter(item => item.getClientRects().length);
            const first = items[0], last = items.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    document.addEventListener('click', event => {
        const profile = document.querySelector('.workspace-profile');
        if (profile?.open && !profile.contains(event.target)) profile.open = false;
    });
    mobile.addEventListener('change', () => { open = false; update(); });
    update();
})();
