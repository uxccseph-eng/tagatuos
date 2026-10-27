(() => {
    const el = id => document.getElementById(id), main = el('masters-main'), form = el('registry-form'), editor = el('registry-editor'), confirmation = el('registry-confirm'), info = el('registry-info');
    const employees = main.dataset.registry === 'employees';
    const parties = main.dataset.registry === 'parties', bank = main.dataset.registry === 'bank-accounts', atc = main.dataset.registry === 'atc-codes', label = employees ? 'employee' : parties ? 'party' : bank ? 'bank account' : 'tax code', keys = employees ? ['code','name','tin','classification','alphalist'] : bank ? ['name','bank','account_number','gl_account','currency'] : atc ? ['code','name','rate','category','payee','form'] : parties ? ['code','name','classification','tin'] : ['code','name','kind','rate','atc'];
    let records = JSON.parse(el('registry-data').textContent), editing = null, pending = null, page = 1, sortKey = bank ? 'name' : 'code', direction = 1;
    const token = document.querySelector('meta[name="csrf-token"]').content;
    function filtered() {
        const search = (el('registry-search').value + ' ' + el('sales-global-search').value).trim().toLowerCase(), classification = el('registry-filter')?.value;
        return records.filter(record => (el('registry-archived')?.checked || !record.archived) && (!classification || record.classification === classification || (record.classification === 'Both' && classification !== 'Both')) && [record.code,record.name,record.tin,record.description,record.kind,record.atc,record.bank,record.account_number,record.gl_account,record.currency,record.category,record.payee,record.form].join(' ').toLowerCase().includes(search)).sort((a,b) => direction * (sortKey === 'rate' ? Number(a.rate)-Number(b.rate) : String(a[sortKey] || '').localeCompare(String(b[sortKey] || ''))));
    }
    function icon(name) { const span = document.createElement('span'); span.className = 'material-symbols-outlined'; span.textContent = name; span.setAttribute('aria-hidden','true'); return span; }
    function tool(label, symbol, callback) { const button = document.createElement('button'); button.type = 'button'; button.className = 'masters-row-action'; button.title = label; button.setAttribute('aria-label', label); button.append(icon(symbol)); button.addEventListener('click',callback); return button; }
    function render() {
        const list = filtered(), size = Number(el('registry-page-size').value), pages = Math.max(1,Math.ceil(list.length/size)); page = Math.min(page,pages); const start = (page-1)*size;
        el('registry-rows').replaceChildren();
        for (const record of list.slice(start,start+size)) {
            const row = document.createElement('tr'); row.classList.toggle('masters-row-archived', record.archived);
            keys.forEach(key => {
                const cell = row.insertCell(); cell.textContent = key === 'rate' ? Number(record.rate).toLocaleString(undefined,{maximumFractionDigits:4})+'%' : key === 'alphalist' ? (record.tin && /[1-9]/.test(record.tin) && record.address ? 'Ready' : 'Incomplete') : record[key] || '—';
                if (key === 'gl_account') { const link = document.createElement('a'); const url = new URL(main.dataset.accountsUrl,location.origin); url.searchParams.set('account',String(record.gl_account).split(' · ')[0]); link.href = url.href; link.target = '_blank'; link.rel = 'noopener'; link.textContent = record.gl_account; link.className = 'registry-gl-link'; cell.replaceChildren(link); }
                if (key === 'code') { cell.className = 'registry-code'; }
                if (key === 'name' && parties && record.description) { const small = document.createElement('small'); small.textContent = record.description; cell.append(small); }
                if (key === 'name' && parties && record.is_petty_cash) { const badge = document.createElement('small'); badge.textContent = 'Petty cash / miscellaneous payee · TIN optional'; cell.append(badge); }
                if (key === 'classification' && !employees) { cell.className = 'registry-classification'; cell.prepend(icon(record.classification === 'Supplier' ? 'local_shipping' : record.classification === 'Both' ? 'swap_horiz' : 'storefront')); }
                if (key === 'alphalist') { cell.className = 'registry-classification'; }
                if (key === 'tin' || key === 'rate' || key === 'atc' || key === 'account_number' || key === 'currency') { cell.classList.add('registry-numeric'); }
            });
            if (!atc) { const status = row.insertCell(), badge = document.createElement('span'); badge.className = record.archived ? 'masters-chip masters-archived-chip' : 'masters-chip masters-active-chip'; badge.append(icon(record.archived ? 'inventory_2' : 'check_circle'),document.createTextNode(record.archived ? 'Archived' : 'Active')); status.append(badge);
            const actions = row.insertCell(); actions.append(tool('Edit '+(record.code || record.name),'edit',()=>openEditor(record)),tool((record.archived ? 'Restore ' : 'Archive ')+(record.code || record.name),record.archived ? 'restore' : 'inventory_2',()=>confirmArchive(record))); } el('registry-rows').append(row);
        }
        if (!list.length) { const row = document.createElement('tr'), cell = row.insertCell(); cell.colSpan = keys.length+(atc ? 0 : 2); cell.className = 'registry-empty'; cell.textContent = 'No matching records. Adjust the filters or add a new record.'; el('registry-rows').append(row); }
        el('registry-range').textContent = 'Showing '+(list.length ? start+1 : 0)+'–'+Math.min(start+size,list.length)+' of '+list.length+' records'; el('registry-page').textContent = 'Page '+page+' of '+pages; el('registry-previous').disabled = page === 1; el('registry-next').disabled = page === pages;
        document.querySelectorAll('[data-sort]').forEach(button => { const key = keys[Number(button.dataset.sort)]; button.parentElement.setAttribute('aria-sort',key === sortKey ? direction === 1 ? 'ascending' : 'descending' : 'none'); button.disabled = !key; });
    }
    function updatePartyType(){
        if(!parties)return;
        const individual=form.elements.entity_type.value==='individual';
        el('party-person-fields').hidden=!individual;
        el('party-entity-name').hidden=individual;
        form.elements.name.disabled=individual;form.elements.name.required=!individual;
        for(const key of ['last_name','first_name','middle_name']){form.elements[key].disabled=!individual;form.elements[key].required=individual&&key!=='middle_name';}
    }
    if(parties)form.elements.entity_type.addEventListener('change',updatePartyType);
    function updatePettyCash(){
        if(!parties)return;
        const petty=form.elements.is_petty_cash.value==='1';
        form.elements.tin.required=!petty;
        if(petty)form.elements.classification.value='Supplier';
        for(const key of ['street','city','province'])form.elements[key].required=!petty;
        el('party-tin-help').textContent=petty?'TIN and unknown address details may be left blank. This does not establish tax deductibility or input VAT eligibility.':'Required: 14 digits including the five-digit branch.';
    }
    if(parties){form.elements.is_petty_cash.addEventListener('change',updatePettyCash);form.elements.classification.addEventListener('change',updatePettyCash);}
    function updatePartyAddress(){if(!parties)return;const parts=Array.from(form.querySelectorAll('[data-party-address-part]')).map(input=>input.value.trim()).filter(Boolean);const address=parts.join(', ');form.elements.address.value=address;el('party-address-preview').textContent=address || 'Enter the registered address above.';}
    if(parties)form.querySelectorAll('[data-party-address-part]').forEach(input=>input.addEventListener('input',updatePartyAddress));
    function openEditor(record = null) {
        editing = record; form.reset(); el('registry-error').textContent = ''; el('registry-title').textContent = (record ? 'Edit ' : 'New ')+label;
        if (bank) { form.elements.chart_account_id.querySelectorAll('[data-saved-account]').forEach(option=>option.remove()); if (record && !Array.from(form.elements.chart_account_id.options).some(option=>option.value === String(record.chart_account_id))) { const option = new Option(record.gl_account+' (saved)',String(record.chart_account_id)); option.dataset.savedAccount = 'true'; form.elements.chart_account_id.add(option); } }
        if (record) { for (const [key,value] of Object.entries(record)) { const field = form.elements.namedItem(key); if (field) { field.value = key==='is_petty_cash'?(value?'1':'0'):value ?? ''; } } }
        if(parties){updatePartyType();updatePettyCash();const legacy=record?.address&&!['building','street','barangay','city','province','zip_code'].some(key=>record[key]);el('party-legacy-address').hidden=!legacy;el('party-legacy-address').textContent=legacy?'Previously saved address: '+record.address+'. Enter its individual parts above to update it.':'';updatePartyAddress();}
        el('registry-save').textContent = record ? 'Save changes' : 'Create '+label; editor.showModal(); form.elements[bank ? 'name' : 'code'].focus();
    }
    async function request(url, method, data) {
        const response = await fetch(url,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:data ? JSON.stringify(data) : undefined});
        let payload; try { payload = await response.json(); } catch { throw new Error('Unable to read the response. Refresh the page and try again.'); }
        if (!response.ok) { throw new Error(response.status === 422 ? Object.values(payload.errors || {}).flat().join(' ') : response.status === 419 ? 'Your session expired. Refresh before saving.' : 'Unable to save this record. Please try again.'); } return payload.record ?? payload.records;
    }
    function remember(record) { records = records.filter(item=>item.id !== record.id); records.push(record); render(); }
    form.addEventListener('submit',async event => { event.preventDefault(); if (!form.reportValidity()) { return; } el('registry-save').disabled = true; el('registry-error').textContent = ''; const data = Object.fromEntries(new FormData(form)); for (const key of Object.keys(data)) { data[key] = data[key].trim(); } if(data.code) { data.code = data.code.toUpperCase(); } if(data.currency) { data.currency = data.currency.toUpperCase(); } if (data.atc) { data.atc = data.atc.toUpperCase(); }
        try { const record = await request(main.dataset.storeUrl+(editing ? '/'+editing.id : ''),editing ? 'PUT' : 'POST',data); remember(record); editor.close(); el('registry-status').textContent = (record.code || record.name)+' saved to your company registry.'; } catch(error) { el('registry-error').textContent = error.message; } finally { el('registry-save').disabled = false; }
    });
    function confirmArchive(record) { pending = record; el('registry-confirm-title').textContent = (record.archived ? 'Restore ' : 'Archive ')+(record.code || record.name)+'?'; el('registry-confirm-text').textContent = record.archived ? 'Restore this record to the active registry.' : 'Hide this record from the active registry and keep its saved details.'; el('registry-confirm-save').textContent = record.archived ? 'Restore' : 'Archive'; el('registry-confirm-error').textContent = ''; confirmation.showModal(); }
    el('registry-confirm-save').addEventListener('click',async()=>{ el('registry-confirm-save').disabled = true; try { const record = await request(main.dataset.storeUrl+'/'+pending.id+(pending.archived ? '/restore' : '/archive'),'POST'); remember(record); confirmation.close(); el('registry-status').textContent = (record.code || record.name)+(record.archived ? ' archived.' : ' restored.'); } catch(error) { el('registry-confirm-error').textContent = error.message; } finally { el('registry-confirm-save').disabled = false; } });
    el('registry-new')?.addEventListener('click',()=>openEditor()); document.querySelector('[data-journal-add]')?.addEventListener('click',()=>atc ? showInfo('ATC reference catalog','This reference list is read-only. Use search or export to find a code.') : openEditor()); document.querySelectorAll('[data-registry-close]').forEach(button=>button.addEventListener('click',()=>editor.close())); el('registry-cancel').addEventListener('click',()=>confirmation.close());
    for (const id of ['registry-search','sales-global-search']) { el(id).addEventListener('input',()=>{ page = 1; render(); }); }
    for (const id of ['registry-archived','registry-filter','registry-page-size']) { el(id)?.addEventListener('change',()=>{ page = 1; render(); }); }
    el('registry-previous').addEventListener('click',()=>{ page--; render(); }); el('registry-next').addEventListener('click',()=>{ page++; render(); });
    document.querySelectorAll('[data-sort]').forEach(button=>button.addEventListener('click',()=>{ const key = keys[Number(button.dataset.sort)]; if (!key) { return; } direction = sortKey === key ? -direction : 1; sortKey = key; page = 1; render(); }));
    el('registry-export').addEventListener('click',()=>{ const columns = employees ? ['code','name','classification','tin','email','phone','address','birth_date','hire_date','end_date','sss_no','philhealth_no','pagibig_no','archived'] : bank ? ['name','bank','account_number','gl_account','currency','archived'] : atc ? keys : parties ? ['code','name','entity_type','last_name','first_name','middle_name','classification','is_petty_cash','tin','description','email','phone','building','street','barangay','city','province','zip_code','address','archived'] : ['code','name','kind','rate','atc','archived']; const quote = value => '"'+String(value ?? '').replace(/^[=+@\-\t\r]/,"'$&").replace(/"/g,'""')+'"'; const csv = [columns,...filtered().map(record=>columns.map(key=>record[key]))].map(row=>row.map(quote).join(',')).join('\r\n'),url = URL.createObjectURL(new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'})),link = document.createElement('a'); link.href = url; link.download = main.dataset.registry+'.csv'; link.click(); setTimeout(()=>URL.revokeObjectURL(url),1000); });
    window.MasterRegistryImport={add(imported){records.push(...imported.map(record=>atc?{...record,category:/^W[VB]/.test(record.code)?'VAT Withholding':'Income-tax CWT',payee:'',form:'',custom:true}:record));page=1;render();el('registry-status').textContent=imported.length+' records added to Masters.';}};
    function showInfo(title,text) { el('registry-info-title').textContent = title; el('registry-info-text').textContent = text; info.showModal(); }
    document.querySelectorAll('[data-masters-planned],[data-sales-info],[data-wtp-info]').forEach(button=>button.addEventListener('click',event=>{ event.preventDefault(); showInfo(button.dataset.mastersPlanned || button.dataset.salesInfo || button.dataset.wtpInfo,'This module is not connected yet. Chart of Accounts, Parties, Tax Codes, ATC Codes, and Bank Accounts are available in Masters.'); })); el('registry-info-close').addEventListener('click',()=>info.close());
    for (const dialog of [editor,confirmation,info]) { dialog.addEventListener('click',event=>{ if (event.target !== dialog) { return; } const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) { dialog.close(); } }); }
    const toggle = el('sales-menu-toggle'), backdrop = el('sales-backdrop'); function closeSidebar() { document.body.classList.remove('sales-menu-open'); toggle.setAttribute('aria-expanded','false'); backdrop.hidden = true; } toggle.addEventListener('click',()=>{ const open = document.body.classList.toggle('sales-menu-open'); toggle.setAttribute('aria-expanded',String(open)); backdrop.hidden = !open; }); backdrop.addEventListener('click',closeSidebar); document.addEventListener('keydown',event=>{ if (event.key === 'Escape') { closeSidebar(); } if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); el('sales-global-search').focus(); } }); render();
})();

const tinInput = document.querySelector('#registry-form input[name="tin"][required]'), branchInput = null;
    if (tinInput) {
        tinInput.addEventListener('input', () => {
            const digits = tinInput.value.replace(/\D/g, '');
            tinInput.value = [digits.slice(0,3), digits.slice(3,6), digits.slice(6,9), digits.slice(9)].filter(Boolean).join('-');
            if (branchInput) branchInput.value = digits.slice(9);
        });
    }
