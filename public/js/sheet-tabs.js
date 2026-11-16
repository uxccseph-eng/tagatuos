(() => {
    'use strict';
    document.querySelectorAll('[data-sheet-navigation]').forEach(navigation => {
        const links = () => Array.from(navigation.querySelectorAll('a[href]')).filter(link => !link.hidden && link.getAttribute('aria-disabled') !== 'true');
        const reveal = link => {
            if (!link) return;
            const bounds = navigation.getBoundingClientRect();
            const target = link.getBoundingClientRect();
            if (target.right > bounds.right) navigation.scrollLeft += target.right - bounds.right + 8;
            else if (target.left < bounds.left) navigation.scrollLeft -= bounds.left - target.left + 8;
        };
        const revealCurrent = () => reveal(links().includes(document.activeElement) ? document.activeElement : navigation.querySelector('[aria-current="page"]'));
        navigation.addEventListener('keydown', event => {
            if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
            const link = event.target.closest('a[href]');
            const items = links();
            const index = items.indexOf(link);
            if (index < 0) return;
            let next;
            if (event.key === 'ArrowRight') next = items[Math.min(index + 1, items.length - 1)];
            else if (event.key === 'ArrowLeft') next = items[Math.max(index - 1, 0)];
            else if (event.key === 'Home') next = items[0];
            else if (event.key === 'End') next = items[items.length - 1];
            else return;
            event.preventDefault();
            event.stopPropagation();
            next.focus({preventScroll: true});
            reveal(next);
        });
        revealCurrent();
        if (document.fonts?.ready) document.fonts.ready.then(revealCurrent);
        if (typeof ResizeObserver !== 'undefined') new ResizeObserver(revealCurrent).observe(navigation);
    });
})();
