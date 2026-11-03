document.addEventListener('click', event => {
    document.querySelectorAll('.company-switcher[open]').forEach(switcher => {
        if (!switcher.contains(event.target)) switcher.open = false;
    });
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('.company-switcher[open]').forEach(switcher => {
        switcher.open = false;
        switcher.querySelector('summary').focus();
    });
});
