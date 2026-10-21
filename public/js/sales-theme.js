(() => {
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
        const button = document.getElementById('theme-toggle-btn');
        const icon = document.getElementById('theme-icon');
        if (button) {
            button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
            button.setAttribute('aria-pressed', String(isDark));
            button.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        }
        if (icon) icon.textContent = isDark ? 'light_mode' : 'dark_mode';
    }
    applyTheme(savedTheme() || (systemPreference.matches ? 'dark' : 'light'));
    document.addEventListener('DOMContentLoaded', () => {
        applyTheme(savedTheme() || (systemPreference.matches ? 'dark' : 'light'));
        document.getElementById('theme-toggle-btn')?.addEventListener('click', () => {
            const theme = root.classList.contains('dark') ? 'light' : 'dark';
            applyTheme(theme);
            try {
                localStorage.setItem(preferenceKey, theme);
                localStorage.setItem('kontadurya-theme', theme);
            } catch {
                // The toggle still works when browser storage is unavailable.
            }
            const icon = document.getElementById('theme-icon');
            icon.classList.add('theme-icon-turn');
            setTimeout(() => icon.classList.remove('theme-icon-turn'), 200);
        });
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
