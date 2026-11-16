(function (root) {
    'use strict';
    const template = typeof module !== 'undefined' && module.exports ? require('./financial-report-template.js') : root.FinancialReportTemplate;
    const frameworks = ['Full PFRS', 'PFRS for SMEs', 'PFRS for Small Entities'];
    const money = cents => (cents < 0 ? '(' : '') + (Math.abs(cents) / 100).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + (cents < 0 ? ')' : '');
    const clean = value => String(value ?? '').trim();
    function build(company, settings, current, previous, revision) {
        if (!frameworks.includes(settings.framework)) throw Error('Choose a supported sample framework.');
        if (!clean(settings.operations)) throw Error('Enter a sample nature of operations.');
        if (settings.materiality && (!/^\d+(\.\d{1,2})?$/.test(settings.materiality) || Number(settings.materiality) > 9999999999.99)) throw Error('Enter materiality as a positive amount with up to two decimal places.');
        const address = ['building', 'street', 'barangay', 'city', 'province', 'zip_code'].map(key => clean(company[key])).filter(Boolean).join(', ') || 'Registered address not provided.';
        const name = company.registered_name || company.company_name || 'Selected company book';
        const sections = [
            {title: 'Entity information', paragraphs: [name, address, 'Sample operations: ' + clean(settings.operations), 'SEC profile: ' + settings.sec + '. Public or regulated status: ' + settings.regulated + '. These are demo settings, not verified registration facts.']},
            {title: 'Basis and scope of preparation', paragraphs: ['Demo framework selection: ' + settings.framework + '. This preview does not assert compliance with that framework.', 'Reporting period: ' + current.startDate + ' to ' + current.endDate + '. Comparative period: ' + previous.startDate + ' to ' + previous.endDate + '. Amounts are presented in Philippine pesos.', 'Source: posted book entries only. Current period: ' + current.entries + ' entries. Comparative period: ' + previous.entries + ' entries. Confirmed book version: ' + (revision ?? 'unavailable') + '.', settings.materiality ? 'Sample materiality: PHP ' + money(Math.round(Number(settings.materiality) * 100)) + '. No disclosures have been omitted automatically.' : 'Materiality has not been assessed. No disclosures have been omitted automatically.']},
            {title: 'Accounting policies and estimates', paragraphs: [clean(settings.policies) || 'Policy decisions and estimates have not been supplied. Accountant review is required; no recognition or measurement policies have been inferred from account names.']},
        ];
        const older = new Map(previous.rows.filter(row => row.kind === 'account').map(row => [row.code, row]));
        const balances = current.rows.filter(row => row.kind === 'account').map(row => ({code: row.code, name: row.label, current: row.values[2], previous: older.get(row.code)?.values[2] ?? 0}));
        for (const row of older.values()) if (!balances.some(item => item.code === row.code)) balances.push({code: row.code, name: row.label, current: 0, previous: row.values[2]});
        balances.sort((a, b) => a.code.localeCompare(b.code));
        const balanceText = rows => rows.length ? rows.map(row => row.code + ' — ' + row.name + ': PHP ' + money(row.current) + ' / PHP ' + money(row.previous)) : ['No posted account balances are available.'];
        sections.push({title: 'Supporting account balances', paragraphs: ['Current / comparative closing balances. Debit balances are positive; credit balances appear in parentheses. These are book balances, not a complete disclosure checklist.', ...balanceText(balances)]});
        if (settings.tax) sections.push({title: 'Tax-related book balances', paragraphs: ['Balances identified by account names containing tax, VAT or withholding. This is not a taxes-paid schedule or a completed BIR supplementary disclosure.', ...balanceText(balances.filter(row => /tax|vat|withhold/i.test(row.name)))]});
        sections.push({title: 'Commitments and events requiring review', paragraphs: [clean(settings.events) || 'Not supplied. Do not interpret an empty disclosure as confirmation that no commitments, contingencies or subsequent events exist.']});
        const warnings = [...new Set([...current.warnings, ...previous.warnings])];
        if (current.variance || current.postedVariance || previous.variance || previous.postedVariance) warnings.push('The supporting book does not balance. Resolve the variance before actual use.');
        sections.push({title: 'Preparation and review status', paragraphs: ['DEMO DRAFT — NOT APPROVED OR FOR FILING. This demonstration does not implement a complete PFRS disclosure checklist, approval workflow or assurance opinion.', ...warnings]});
        return {companyName: name, startDate: current.startDate, endDate: current.endDate, sections, warnings, balances};
    }
    function rows(report) {
        return report.sections.flatMap((section, index) => [{kind: 'section', cells: [String(index + 1), section.title]}, ...section.paragraphs.map(text => ({kind: 'account', cells: ['', text]}))]);
    }
    const xml = value => String(value ?? '').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
    function docx(report) {
        const paragraph = (value, bold = false) => '<w:p><w:pPr><w:spacing w:after="160"/></w:pPr><w:r><w:rPr>' + (bold ? '<w:b/>' : '') + '<w:sz w:val="22"/></w:rPr><w:t xml:space="preserve">' + xml(value) + '</w:t></w:r></w:p>';
        const title = [report.companyName, 'Notes to financial statements', 'DEMO DRAFT — NOT APPROVED OR FOR FILING', report.startDate + ' to ' + report.endDate];
        const body = title.map(text => paragraph(text, true)).join('') + report.sections.map((section, index) => paragraph((index + 1) + '. ' + section.title, true) + section.paragraphs.map(text => paragraph(text)).join('')).join('');
        return template.zip({
            '[Content_Types].xml': '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels': '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml': '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' + body + '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>',
        });
    }
    const api = {build, rows, docx};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.FinancialNotes = api;
})(typeof window === 'undefined' ? null : window);
