(() => {
    'use strict';
    const main = document.querySelector('main');
    if (main && !document.querySelector('.skip-link, .workspace-skip-link')) {
        if (!main.id) {
            let suffix = 1;
            do { main.id = 'workspace-main-' + suffix++; } while (document.getElementById(main.id) !== main);
        }
        const skip = document.createElement('a');
        skip.className = 'workspace-skip-link';
        skip.href = '#' + main.id;
        skip.textContent = 'Skip to content';
        skip.addEventListener('click', () => {
            if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
            main.focus({preventScroll: true});
        });
        document.body.prepend(skip);
    }
    const drawers = '.sales-entry-drawer, .cwt-entry, #payroll-entry, #journal-entry';
    const imports = '.sheet-import-dialog';
    const previews = '.sales-print-dialog, .form2307-dialog, .form2316-dialog, #return-print-dialog';
    function prepare(dialog) {
        dialog.classList.add('workspace-popup');
        dialog.dataset.popupLayout = dialog.matches(drawers) ? 'drawer' : dialog.matches(imports) ? 'import' : dialog.matches(previews) ? 'preview' : 'form';
    }
    function scan(root) {
        if (root.matches?.('dialog')) prepare(root);
        root.querySelectorAll?.('dialog').forEach(prepare);
    }
    scan(document);
    new MutationObserver(records => {
        for (const record of records) {
            for (const node of record.addedNodes) scan(node);
        }
    }).observe(document.body, {childList: true, subtree: true});
})();
