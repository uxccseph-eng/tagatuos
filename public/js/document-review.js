(() => {
    const form = document.getElementById('document-entry-form');
    const config = JSON.parse(document.getElementById('document-masters-data').textContent);
    const zoom = document.getElementById('document-zoom');
    zoom?.addEventListener('input', () => { document.getElementById('source-image').style.width = zoom.value + '%'; });
    let submitting = false;
    const dirtyForms = new Set();
    document.querySelectorAll('.document-workspace form').forEach(item => {
        item.addEventListener('input', () => { if (item.method.toLowerCase() !== 'get') dirtyForms.add(item); });
        item.addEventListener('submit', () => { submitting = [...dirtyForms].every(dirty => dirty === item); });
        if (item.querySelector('[name="ledger_revision"]')) item.addEventListener('submit', event => {
            let message = '';
            try {
                const key = 'kontadurya.ledger.v1.' + config.ledgerBrowserKey;
                if (sessionStorage.getItem(key + '.pending-database-save')) message = 'This browser has a pending company save. Return to Sheets and finish saving or recovering that work before creating or linking an entry.';
                if (!config.ledgerInitialized) {
                    const legacy = JSON.parse(sessionStorage.getItem(key) || '{}');
                    const payroll = JSON.parse(sessionStorage.getItem('kontadurya.payroll.v1.' + config.ledgerBrowserKey) || '[]');
                    if (Object.values(legacy).some(rows => Array.isArray(rows) && rows.length) || payroll.length) message = 'Existing browser records need review and transfer. Open this company in Sheets and use Transfer reviewed records before creating or linking inbox entries.';
                }
            } catch { message = 'Existing browser work could not be checked. Open this company in Sheets and resolve its storage notice before creating or linking entries.'; }
            if (message) {
                event.preventDefault(); submitting = false;
                const warning = document.getElementById('document-ledger-warning');
                warning.textContent = message; warning.hidden = false; warning.scrollIntoView({block: 'nearest'});
            }
        });
    });
    window.addEventListener('beforeunload', event => { if (dirtyForms.size && !submitting) { event.preventDefault(); event.returnValue = ''; } });
    if (!form) return;
    const masters = config;
    const sheet = document.getElementById('entry-sheet');
    const party = document.getElementById('entry-party'), partyId = document.getElementById('entry-party-id');
    const account = document.getElementById('entry-account'), accountId = document.getElementById('entry-account-id');
    let parties = [], accounts = [];
    const label = record => record.code + ' · ' + record.name;
    function sync(input, hidden, choices) {
        const record = choices.find(item => label(item).toLowerCase() === input.value.trim().toLowerCase());
        hidden.value = record ? String(record.id) : '';
        input.setCustomValidity(record || !input.value ? '' : 'Choose a matching value from this company’s Masters.');
        if (input === party) document.getElementById('entry-party-details').textContent = record ? [record.tin, record.address].filter(Boolean).join(' · ') : '';
    }
    function options(id, choices) {
        document.getElementById(id).replaceChildren(...choices.map(record => Object.assign(document.createElement('option'), {value: label(record)})));
    }
    function refresh() {
        const sales = sheet.value === 'sales';
        parties = masters.parties.filter(item => item.classification === (sales ? 'Customer' : 'Supplier') || item.classification === 'Both');
        accounts = masters.accounts.filter(item => sales ? item.type === 'Income' : ['Asset', 'Expense'].includes(item.type));
        options('document-parties', parties); options('document-accounts', accounts);
        if (!party.value && partyId.value) party.value = parties.find(item => String(item.id) === partyId.value) ? label(parties.find(item => String(item.id) === partyId.value)) : '';
        if (!account.value && accountId.value) account.value = accounts.find(item => String(item.id) === accountId.value) ? label(accounts.find(item => String(item.id) === accountId.value)) : '';
        sync(party, partyId, parties); sync(account, accountId, accounts);
    }
    party.addEventListener('input', () => sync(party, partyId, parties));
    account.addEventListener('input', () => sync(account, accountId, accounts));
    sheet.addEventListener('change', () => { party.value = ''; account.value = ''; partyId.value = ''; accountId.value = ''; refresh(); });
    form.addEventListener('submit', event => {
        sync(party, partyId, parties); sync(account, accountId, accounts);
        if (!partyId.value || !accountId.value || !form.reportValidity()) { event.preventDefault(); submitting = false; }
    });
    document.addEventListener('keydown', event => {
        if (event.altKey && ['p', 'a'].includes(event.key.toLowerCase())) { event.preventDefault(); (event.key.toLowerCase() === 'p' ? party : account).focus(); }
        if (event.ctrlKey && event.key === 'Enter') { event.preventDefault(); form.requestSubmit(); }
    });
    refresh();
})();
