(function (root) {
    'use strict';
    function payload(report, book, range) {
        const labels = {reference: 'Ref No', account: 'Account', party: 'Party', remarks: 'Particulars', debit: 'Debit', credit: 'Credit', balance: 'Balance', ewt: 'EWT/2307'};
        const byBook = {
            'subsidiary-sales': {reference: 'SI No.', party: 'Customer', exempt: 'VAT-Exempt Sales', zero: 'Zero-Rated Sales'},
            'subsidiary-purchase': {reference: 'Supplier Invoice No.', party: 'Supplier', exempt: 'VAT-Exempt Purchases', zero: 'Zero-Rated Purchases'},
            'cash-receipts': {reference: 'OR/Collection Ref.', party: 'Customer/Payor', bank: 'Cash/Bank Account'},
            'cash-disbursements': {number: 'DV/CV No.', reference: 'Check/Bank Ref.', party: 'Payee', sourceBill: 'Source Ref.', gross: 'Gross Amount', net: 'Net Cash Paid', bank: 'Cash/Bank Account', offset: 'Account Charged'},
            'general-ledger': {party: 'Subsidiary'},
            'subsidiary-ledger': {party: 'Subsidiary'}
        };
        const names = {...labels, ...byBook[book]};
        let columns = report.columns.map(column => ({key: column.key, title: names[column.key] || column.title, ...(column.format ? {format: column.format} : {})}));
        if (book === 'cash-disbursements') {
            const order = ['date', 'number', 'reference', 'party', 'tin', 'remarks', 'sourceBill', 'gross', 'vat', 'ewt', 'wvat', 'net', 'bank', 'offset', 'branch'];
            columns.sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
        }
        const keys = [...columns.map(column => column.key), 'kind', 'group', 'groupAccount', 'groupParty', 'voucherId'];
        const rows = report.rows.map(row => Object.fromEntries(keys.filter(key => row[key] !== undefined).map(key => [key, row[key]])));
        return {from: range.from, to: range.to, columns, rows};
    }
    root.BooksExport = {payload};
    if (typeof module !== 'undefined') module.exports = root.BooksExport;
})(typeof window === 'undefined' ? globalThis : window);
