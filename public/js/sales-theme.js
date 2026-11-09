(() => {
    if (window.WorkspaceTheme) { window.WorkspaceTheme.refresh(); return; }
    const root = document.documentElement;
    const preferenceKey = 'kontadurya.workspace.theme';
    const systemPreference = window.matchMedia('(prefers-color-scheme: dark)');
    function savedTheme() {
        try {
            const saved = localStorage.getItem(preferenceKey) || localStorage.getItem('kontadurya-theme');
            return ['dark', 'light'].includes(saved) ? saved : null;
        } catch {
            return null;
        }
    }
    function applyTheme(theme) {
        const isDark = theme === 'dark';
        root.classList.toggle('dark', isDark);
        root.classList.toggle('theme-light', !isDark);
        root.style.colorScheme = theme;
        for (const button of document.querySelectorAll('#theme-toggle-btn, #themeToggleBtn, [data-theme-toggle]')) {
            const label = isDark ? 'Switch to light mode' : 'Switch to dark mode';
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-pressed', String(isDark));
            button.title = label;
            const caption = button.querySelector('[data-theme-label]');
            if (caption) caption.textContent = isDark ? 'Light mode' : 'Dark mode';
        }
        for (const icon of document.querySelectorAll('#theme-icon, #themeToggleIcon')) icon.textContent = isDark ? 'light_mode' : 'dark_mode';
    }
    function refresh() { applyTheme(savedTheme() || (systemPreference.matches ? 'dark' : 'light')); }
    window.WorkspaceTheme = {refresh};
    applyTheme(savedTheme() || (systemPreference.matches ? 'dark' : 'light'));
    document.addEventListener('DOMContentLoaded', () => {
        applyTheme(savedTheme() || (systemPreference.matches ? 'dark' : 'light'));
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('#theme-toggle-btn, #themeToggleBtn, [data-theme-toggle]');
        if (!button) return;
        const theme = root.classList.contains('dark') ? 'light' : 'dark';
        applyTheme(theme);
        try {
            localStorage.setItem(preferenceKey, theme);
            localStorage.setItem('kontadurya-theme', theme);
        } catch {
            // Theme switching remains available when browser storage is disabled.
        }
    });
    systemPreference.addEventListener('change', () => {
        if (!savedTheme()) applyTheme(systemPreference.matches ? 'dark' : 'light');
    });
    window.addEventListener('storage', (event) => {
        if ([preferenceKey, 'kontadurya-theme'].includes(event.key)) {
            applyTheme(savedTheme() || (systemPreference.matches ? 'dark' : 'light'));
        }
    });
})();
