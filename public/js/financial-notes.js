(() => {
    'use strict';
    const el = id => document.getElementById(id), main = el('books-main'), api = window.FinancialNotes;
    const company = JSON.parse(el('financial-company').textContent), dialog = el('notes-settings'), form = el('notes-settings-form');
    const yearInput = el('notes-year'), fiscalEnd = Number(main.dataset.fiscalEnd) || 12;
    const params = new URLSearchParams(location.search);
    if (/^(19|20|21)\d{2}$/.test(params.get('year') || '')) yearInput.value = params.get('year');
    let settings = null, report = null, busy = false;
    const node = (tag, text) => { const item = document.createElement(tag); if (text !== undefined) item.textContent = text; return item; };
    function build() {
        const year = Number(yearInput.value);
        if (year < 1901 || year > 2199 || !Number.isInteger(year)) throw Error('Choose a fiscal year from 1901 to 2199.');
        const current = window.FinancialReports.build(window.KontaduryaLedger, 'trial-balance', year, fiscalEnd);
        const previous = window.FinancialReports.build(window.KontaduryaLedger, 'trial-balance', year - 1, fiscalEnd);
        return api.build(company, settings, current, previous, window.CompanyLedgerDatabase?.revision);
    }
    function render() {
        if (!settings) return;
        try {
            report = build();
            el('notes-document').replaceChildren(); el('notes-index').replaceChildren();
            const header = node('header');
            header.append(node('h2', report.companyName), node('p', 'Notes to financial statements'), node('p', report.startDate + ' to ' + report.endDate), node('strong', 'Demo draft — not approved or for filing'));
            el('notes-document').append(header);
            for (const [index, section] of report.sections.entries()) {
                const content = node('section'); content.id = 'notes-section-' + (index + 1);
                content.append(node('h3', (index + 1) + '. ' + section.title));
                for (const text of section.paragraphs) content.append(node('p', text));
                const link = node('a', (index + 1) + '. ' + section.title); link.href = '#' + content.id;
                el('notes-index').append(link); el('notes-document').append(content);
            }
            el('notes-empty').hidden = true; el('notes-result').hidden = false;
            el('notes-pdf').disabled = false; el('notes-word').disabled = false;
            el('notes-status').textContent = 'Demo preview generated from posted book entries. ' + (report.warnings.length ? report.warnings.join(' ') : 'Review the sample disclosures before using them.');
            const url = new URL(location.href); url.searchParams.set('year', yearInput.value); history.replaceState(null, '', url);
            filter();
        } catch (error) {
            report = null; el('notes-pdf').disabled = true; el('notes-word').disabled = true;
            el('notes-result').hidden = true; el('notes-status').textContent = error.message;
        }
    }
    function filter() {
        const query = el('sales-global-search').value.trim().toLowerCase();
        for (const [index, section] of [...el('notes-document').querySelectorAll('section')].entries()) {
            section.hidden = !section.textContent.toLowerCase().includes(query);
            el('notes-index').children[index].hidden = section.hidden;
        }
    }
    const open = () => { el('notes-settings-error').textContent = ''; dialog.showModal(); };
    el('notes-settings-open').addEventListener('click', open); el('notes-start').addEventListener('click', open);
    document.querySelectorAll('[data-notes-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    form.addEventListener('submit', event => {
        event.preventDefault();
        const candidate = Object.fromEntries(new FormData(form)); candidate.tax = form.elements.tax.checked;
        try {
            const old = settings; settings = candidate;
            try { build(); } catch (error) { settings = old; throw error; }
            dialog.close(); render();
        } catch (error) { el('notes-settings-error').textContent = error.message; }
    });
    yearInput.addEventListener('change', render);
    el('sales-global-search').addEventListener('input', filter);
    window.addEventListener('company-ledger:saved', render); window.addEventListener('company-ledger:refreshed', render);
    async function exportFile(kind) {
        if (!settings || busy) return;
        busy = true; el('notes-pdf').disabled = true; el('notes-word').disabled = true;
        el('notes-status').textContent = 'Generating demo ' + kind.toUpperCase() + '…';
        try {
            const snapshot = build();
            let bytes, mime;
            if (kind === 'docx') {
                bytes = api.docx(snapshot); mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            } else {
                const meta = window.FinancialReportTemplate.metadata(company, 'Notes to FS — DEMO DRAFT', snapshot, 'notes-to-fs');
                meta.title = 'Notes to FS — DEMO DRAFT'; meta.period = 'Not approved or for filing';
                bytes = await window.FinancialReportPdf.create(meta, ['Note', 'Disclosure'], api.rows(snapshot), {portrait: true, textOnly: true, widths: [.08, .92]}); mime = 'application/pdf';
            }
            const url = URL.createObjectURL(new Blob([bytes], {type: mime}));
            const link = document.createElement('a'); link.href = url; link.download = 'demo-notes-to-fs-' + snapshot.endDate + '.' + kind; link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            el('notes-status').textContent = 'Demo draft downloaded. The complete notes are exported, including sections hidden by search.';
        } catch (error) { el('notes-status').textContent = 'Export failed. ' + error.message; }
        finally { busy = false; el('notes-pdf').disabled = !report; el('notes-word').disabled = !report; }
    }
    el('notes-pdf').addEventListener('click', () => exportFile('pdf')); el('notes-word').addEventListener('click', () => exportFile('docx'));
    el('books-info-close').addEventListener('click', () => el('books-info').close());
    document.querySelector('[data-journal-add]')?.addEventListener('click', () => { location.href = main.dataset.journalUrl; });
    const toggle = el('sales-menu-toggle'), backdrop = el('sales-backdrop');
    const closeMenu = () => { document.body.classList.remove('sales-menu-open'); backdrop.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
    toggle.addEventListener('click', () => { const visible = document.body.classList.toggle('sales-menu-open'); backdrop.hidden = !visible; toggle.setAttribute('aria-expanded', String(visible)); });
    backdrop.addEventListener('click', closeMenu);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); el('sales-global-search').focus(); } });
})();
