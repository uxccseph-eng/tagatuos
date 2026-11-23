(() => {
    'use strict';
    if (window.WorkspaceRail) { window.WorkspaceRail.refresh(); return; }
    const sidebar = document.querySelector('#sales-sidebar, #sidebarNav, #workspace-app-sidebar');
    const toggle = document.querySelector('#sales-menu-toggle, #sidebarToggle, #workspace-nav-toggle, #settings-menu');
    if (!sidebar || !toggle) return;
    document.body.classList.add('js');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const brand = sidebar.querySelector('.workspace-sidebar-brand, .dashboard-sidebar-brand') || sidebar.querySelector(':scope > div:first-child > div:first-child');
    const originalPosition = document.createComment('Navigation menu control');
    toggle.before(originalPosition);
    const icon = toggle.querySelector('.material-symbols-outlined');
    const mobileIcon = icon?.textContent;
    brand?.classList.add('workspace-rail-brand');
    const key = 'kontadurya.navigation.collapsed';
    let collapsed = false;
    try { collapsed = localStorage.getItem(key) === 'true'; } catch {}
    const detailsStates = new Map(), sectionStates = new Map();
    const links = [...sidebar.querySelectorAll('nav a')];
    for (const link of links) {
        const caption = [...link.children].filter(child => !child.classList.contains('material-symbols-outlined') && !child.querySelector('.material-symbols-outlined')).map(child => child.textContent.trim()).join(' ');
        if (caption && !link.hasAttribute('aria-label')) link.setAttribute('aria-label', caption);
    }
    const titles = new Map(links.map(link => [link, link.getAttribute('title')]));
    toggle.setAttribute('aria-controls', sidebar.id);
    function apply() {
        const compact = desktop.matches && collapsed;
        document.body.classList.toggle('workspace-rail-collapsed', compact);
        if (sidebar.id === 'sidebarNav') document.body.classList.remove('sidebar-closed');
        for (const detail of sidebar.querySelectorAll('details')) {
            if (compact) {
                if (!detailsStates.has(detail)) detailsStates.set(detail, detail.open);
                detail.open = true;
            } else if (detailsStates.has(detail)) {
                detail.open = detailsStates.get(detail); detailsStates.delete(detail);
            }
        }
        for (const button of sidebar.querySelectorAll('[data-section-toggle]')) {
            const section = button.nextElementSibling;
            if (compact) {
                if (!sectionStates.has(button)) sectionStates.set(button, {hidden: section.hidden, expanded: button.getAttribute('aria-expanded')});
                section.hidden = false; button.setAttribute('aria-expanded', 'true');
            } else if (sectionStates.has(button)) {
                const previous = sectionStates.get(button); section.hidden = previous.hidden;
                button.setAttribute('aria-expanded', previous.expanded); sectionStates.delete(button);
            }
        }
        for (const link of links) {
            const title = compact ? link.getAttribute('aria-label') : titles.get(link);
            if (title) link.setAttribute('title', title); else link.removeAttribute('title');
        }
        if (desktop.matches) {
            if (brand && toggle.parentNode !== brand) brand.append(toggle);
            toggle.classList.add('workspace-rail-control');
            if (icon) icon.textContent = compact ? 'chevron_right' : 'chevron_left';
            sidebar.inert = false;
            document.body.classList.remove('settings-menu-open');
            toggle.setAttribute('aria-expanded', String(!compact));
            toggle.setAttribute('aria-label', compact ? 'Expand navigation' : 'Collapse navigation');
            toggle.title = compact ? 'Expand navigation' : 'Collapse navigation';
        } else {
            originalPosition.after(toggle);
            toggle.classList.remove('workspace-rail-control');
            if (icon) icon.textContent = mobileIcon;
            if (sidebar.id === 'sales-sidebar') sidebar.inert = false;
            toggle.setAttribute('aria-label', sidebar.id === 'workspace-app-sidebar' ? 'Open navigation' : 'Toggle navigation');
            toggle.removeAttribute('title');
        }
    }
    document.addEventListener('click', event => {
        if (!desktop.matches || !toggle.contains(event.target)) return;
        event.preventDefault(); event.stopImmediatePropagation();
        collapsed = !collapsed;
        try { localStorage.setItem(key, String(collapsed)); } catch {}
        apply();
    }, true);
    if (toggle.id === 'settings-menu') {
        document.addEventListener('click', () => {
            if (!desktop.matches) queueMicrotask(() => toggle.setAttribute('aria-expanded', String(document.body.classList.contains('settings-menu-open'))));
        });
        document.addEventListener('keydown', event => {
            if (!desktop.matches && event.key === 'Escape' && document.body.classList.contains('settings-menu-open')) {
                document.body.classList.remove('settings-menu-open'); toggle.setAttribute('aria-expanded', 'false'); toggle.focus();
            }
        });
    }
    desktop.addEventListener('change', () => queueMicrotask(apply));
    window.addEventListener('storage', event => { if (event.key === key) { collapsed = event.newValue === 'true'; apply(); } });
    window.addEventListener('pageshow', () => queueMicrotask(apply));
    document.addEventListener('DOMContentLoaded', apply, {once: true});
    window.WorkspaceRail = {refresh: apply};
    apply();
})();
