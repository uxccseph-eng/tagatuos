(() => {
    const configElement = document.getElementById('masters-import-config');
    if (!configElement) return;
    const config = JSON.parse(configElement.textContent), tools = window.MasterImportData, registry = config.registry;
    const titles = {'chart-of-accounts':'Chart of Accounts', parties:'Parties', employees:'Employees', 'bank-accounts':'Bank Accounts', 'tax-codes':'Tax Codes', 'atc-codes':'Custom ATC Codes'};
    const guidance = {
        'chart-of-accounts':'Name and type are required. Type: Asset, Liability, Equity, Income or Expense. Blank normal balance and statement section use the type defaults. Flags accept Yes/No, True/False or 1/0. Control accounts need a subsidiary.',
        parties:'Classification: Customer, Supplier or Both. Regular parties require TIN and registered address; street is optional. Petty cash suppliers (is_petty_cash = Yes) may omit TIN and address. Individual parties need first and last names. Use Both instead of duplicating a customer who is also a supplier.',
        employees:'Name is required. Classification defaults to Regular. Dates: YYYY-MM-DD. TIN and government IDs are optional.',
        'bank-accounts':'Name and gl_account are required. Use an active cash/bank account code or name from this company. Currency defaults to PHP. Keep bank account numbers as text to preserve leading zeros.',
        'tax-codes':'Name, kind and rate are required. Kind: VAT, Exempt, Zero Rated, Percentage Tax or Withholding. Rate: 0–100, up to four decimal places. ATC is optional.',
        'atc-codes':'Use the actual ATC code, name and rate (0–100). Supplied reference codes cannot be replaced.'
    };
    const dialog = document.createElement('dialog');
    dialog.className = 'sheet-import-dialog masters-guided-import';
    dialog.setAttribute('aria-labelledby', 'masters-import-title');
    dialog.innerHTML = `<header><div><span class="sheet-import-eyebrow">MASTERS DATABASE · BATCH IMPORT</span><h2 id="masters-import-title">Import ${titles[registry]}</h2></div><button type="button" data-close aria-label="Close Masters import">×</button></header>
        <div class="sheet-import-body"><p>Choose a file or paste spreadsheet cells, map the columns, then review and validate before adding.</p>
        <div class="sheet-import-controls"><button type="button" data-template>Download CSV template</button><label>CSV or Excel file<input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-file></label><label data-worksheet-label hidden>Worksheet<select data-worksheet></select></label></div>
        <details class="masters-paste"><summary>Paste from Excel or Google Sheets</summary><label>Copy the headings and data cells<textarea data-paste rows="5" placeholder="Paste spreadsheet cells here"></textarea></label><button type="button" data-use-paste>Use pasted cells</button></details>
        <details><summary>Required fields and duplicate rules</summary><p data-guide></p><p>Maximum 500 data rows. CSV/paste: 5 MB; Excel: 2 MB. Excel formulas must be replaced with values. Codes generate if blank, except custom ATC codes. Duplicate names and codes (including archived records) are blocked. Non-placeholder TINs, bank/account-number pairs and withholding ATC/rate pairs are checked too. Existing records are never overwritten.</p></details>
        <p data-feedback role="status" aria-live="polite">No source loaded. Nothing has been added.</p>
        <section data-mapping hidden><h3>1. Map source columns</h3><label>Header row (1–20)<input type="number" min="1" max="20" value="1" data-header></label><p>Check every suggested mapping. Unmapped fields stay blank or use their defaults. Applying a mapping replaces unsaved review edits.</p><div class="masters-mapping-grid" data-mapping-grid></div><button type="button" data-apply>Apply mapping and review</button></section>
        <section data-review hidden><h3>2. Review included rows</h3><button type="button" data-change-mapping>Review / change column mapping</button><div class="sheet-import-controls"><label>Fill blank fields<select data-bulk-field></select></label><label>Value<input type="text" maxlength="4000" data-bulk-value></label><button type="button" data-fill>Fill blanks in included rows</button></div>
        <div class="sheet-import-controls"><label class="masters-inline"><input type="checkbox" data-errors-only>Show issue rows only</label><label>Search rows<input type="search" data-search></label><button type="button" data-report disabled>Download error report</button></div><ul data-issues hidden></ul>
        <div class="sheet-import-table-wrap"><table><thead></thead><tbody></tbody></table></div><div class="sheet-import-controls"><button type="button" data-previous>Previous</button><span data-page></span><button type="button" data-next>Next</button></div></section></div>
        <footer><span data-count>Preview required before adding</span><div><button type="button" data-close>Close</button><button type="button" data-preview disabled>Validate preview</button><button type="button" data-add disabled>Add batch to Masters</button></div></footer>`;
    document.body.append(dialog);
    const field = name => dialog.querySelector(`[data-${name}]`);
    const cell = (tag, value) => {const item = document.createElement(tag); item.textContent = String(value ?? ''); return item;};
    const labelFor = key => key.replace(/_/g, ' ');
    field('guide').textContent = guidance[registry];
    config.columns.forEach(column => field('bulk-field').add(new Option(labelFor(column), column)));
    let matrix = [], source = null, mapping = {}, rows = [], issues = [], normalized = new Map(), previewToken = null, busy = false, mappingDirty = true, page = 1, currentFile = null;
    const selected = () => tools.included(rows);
    function message(text, error = false) {field('feedback').textContent = text; field('feedback').dataset.state = error ? 'error' : '';}
    function buttons() {
        field('preview').disabled = busy || mappingDirty || !selected().length;
        field('add').disabled = busy || mappingDirty || !previewToken;
        field('report').disabled = busy || !issues.length;
    }
    function invalidate() {previewToken = null; normalized.clear(); buttons();}
    function setBusy(value) {
        busy = value;
        dialog.querySelectorAll('input,select,textarea,button').forEach(control => control.disabled = value);
        if (!value && rows.length) renderReview();
        buttons();
    }
    function showIssues() {
        field('issues').replaceChildren(...issues.slice(0, 100).map(issue => cell('li', `${issue.row ? 'Source row '+issue.row+' · ' : ''}${issue.field || 'Batch'}: ${issue.message}`)));
        field('issues').hidden = !issues.length;
    }
    function fail(problem) {invalidate(); if (problem.issues) issues = problem.issues; message(problem.message, true); showIssues(); if (rows.length) renderReview();}
    function resetSource() {
        rows = []; source = null; issues = []; mappingDirty = true; invalidate();
        field('review').hidden = true; field('mapping').hidden = true; showIssues(); field('count').textContent = 'Preview required before adding';
    }
    async function request(url, data) {
        const isFile = data instanceof FormData;
        const headers = {Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content};
        if (!isFile) headers['Content-Type'] = 'application/json';
        const response = await fetch(url, {method:'POST', headers, body:isFile ? data : JSON.stringify(data)});
        let payload;
        try {payload = await response.json();} catch {throw Error(`Could not read the response (HTTP ${response.status}). Keep this window open and try validating again.`);}
        if (!response.ok) {
            const problem = Error(response.status === 419 ? 'Session expired. Refresh and sign in before importing.' : response.status >= 500 ? 'The server could not process this batch. Try validating again.' : payload.message || `Request failed (HTTP ${response.status}).`);
            const included = selected();
            problem.issues = Object.entries(payload.errors || {}).flatMap(([key, messages]) => [].concat(messages).map(text => ({row:/^records\.(\d+)\./.test(key) ? included[Number(key.match(/^records\.(\d+)\./)[1])]?.sourceRow : null, field:key.replace(/^records\.\d+\./, ''), message:text})));
            throw problem;
        }
        return payload;
    }
    function renderMapping() {
        resetSource();
        field('mapping').hidden = false;
        field('mapping-grid').replaceChildren();
        source = tools.source(matrix, Number(field('header').value));
        mapping = tools.guessMapping(source.headers, config.columns, registry);
        const grid = field('mapping-grid'); grid.replaceChildren();
        config.columns.forEach(column => {
            const label = cell('label', labelFor(column)), select = document.createElement('select');
            select.setAttribute('aria-label', 'Source column for '+labelFor(column));
            select.add(new Option('Unmapped / default', ''));
            source.headers.forEach((header, index) => select.add(new Option(`${index+1}. ${header || '(blank heading)'}`, String(index))));
            select.value = mapping[column] === null ? '' : String(mapping[column]);
            select.addEventListener('change', () => {mapping[column] = select.value === '' ? null : Number(select.value); mappingDirty = true; invalidate(); message('Apply the mapping again before validating. This replaces unsaved review edits.');});
            label.append(select); grid.append(label);
        });
        field('mapping').hidden = false;
    }
    function loadMatrix(values) {matrix = values; field('header').value = '1'; renderMapping(); message(`${source.rows.length} source rows loaded. Check the column mapping.`);}
    async function loadWorkbook() {
        const data = new FormData(); data.append('file', currentFile); data.append('worksheet', field('worksheet').value || '0');
        const result = await request(config.workbookUrl, data);
        field('worksheet').replaceChildren(...result.worksheets.map((name, index) => new Option(name, String(index))));
        field('worksheet').value = String(result.selected); field('worksheet-label').hidden = false;
        loadMatrix(result.rows);
    }
    field('file').addEventListener('change', async () => {
        resetSource(); currentFile = field('file').files[0]; field('worksheet-label').hidden = true; field('worksheet').replaceChildren();
        if (!currentFile) return;
        setBusy(true);
        try {
            if (/\.xlsx$/i.test(currentFile.name)) {
                if (currentFile.size > 2*1024*1024) throw Error('Choose an Excel file up to 2 MB.');
                await loadWorkbook();
            } else {
                if (!/\.csv$/i.test(currentFile.name) || currentFile.size > 5*1024*1024) throw Error('Choose CSV up to 5 MB or Excel .xlsx up to 2 MB.');
                loadMatrix(window.SheetImport.parseCsv(await currentFile.text(), true));
            }
        } catch (problem) {fail(problem);} finally {setBusy(false);}
    });
    field('worksheet').addEventListener('change', async () => {resetSource(); setBusy(true); try {await loadWorkbook();} catch (problem) {fail(problem);} finally {setBusy(false);}});
    field('use-paste').addEventListener('click', () => {
        resetSource();
        try {loadMatrix(window.SheetImport.parseClipboard(field('paste').value)); currentFile = null; field('file').value = ''; field('worksheet-label').hidden = true;} catch (problem) {fail(problem);}
    });
    field('header').addEventListener('change', () => {try {renderMapping();} catch (problem) {field('mapping').hidden = false; fail(problem);}});
    field('apply').addEventListener('click', () => {
        try {
            if (!source) throw Error('Choose a valid header row first.');
            rows = tools.mapRows(source, mapping, config.columns); issues = []; mappingDirty = false; page = 1; invalidate();
            field('search').value = ''; field('errors-only').checked = false; field('mapping').hidden = true; field('review').hidden = false; showIssues(); renderReview();
            message('Review the values below. Exclude unwanted rows, fill blanks or edit cells, then validate the preview.');
        } catch (problem) {fail(problem);}
    });
    field('change-mapping').addEventListener('click', () => {field('mapping').hidden = false; field('header').scrollIntoView({block:'center'}); field('header').focus();});
    function visibleRows() {
        const search = field('search').value.trim().toLowerCase(), errorRows = new Set(issues.map(issue => issue.row));
        return rows.filter(row => (!field('errors-only').checked || errorRows.has(row.sourceRow)) && (!search || Object.values(row.record).some(value => String(value).toLowerCase().includes(search))));
    }
    function renderReview() {
        const visible = visibleRows(), pages = Math.max(1, Math.ceil(visible.length/25)); page = Math.min(page, pages);
        const heading = document.createElement('tr'); heading.append(cell('th', 'Include / Source row'), cell('th', 'Check'), ...config.columns.map(column => cell('th', labelFor(column))));
        dialog.querySelector('thead').replaceChildren(heading);
        const body = dialog.querySelector('tbody'); body.replaceChildren();
        visible.slice((page-1)*25, page*25).forEach(row => {
            const rowIssues = issues.filter(issue => issue.row === row.sourceRow), tr = document.createElement('tr');
            tr.dataset.invalid = String(row.included && rowIssues.length > 0); tr.classList.toggle('masters-excluded', !row.included);
            const includeCell = document.createElement('td'), includeLabel = cell('label', 'Row '+row.sourceRow), checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.checked = row.included; checkbox.disabled = busy;
            checkbox.addEventListener('change', () => {row.included = checkbox.checked; invalidate(); renderReview(); message('Included rows changed. Validate the preview again.');});
            includeLabel.prepend(checkbox); includeCell.append(includeLabel); tr.append(includeCell, cell('td', !row.included ? 'Excluded' : rowIssues.length ? 'Needs correction' : previewToken ? 'Ready' : 'Needs preview'));
            config.columns.forEach(column => {
                const td = document.createElement('td'), input = document.createElement('input'), errors = rowIssues.filter(issue => issue.field === column);
                input.type = 'text'; input.maxLength = 4000; input.value = row.record[column]; input.disabled = busy || !row.included;
                input.setAttribute('aria-label', `Source row ${row.sourceRow}: ${labelFor(column)}`);
                if (errors.length && row.included) input.setAttribute('aria-invalid', 'true');
                input.addEventListener('change', () => {row.record[column] = input.value.trim(); invalidate(); message('Values changed. Validate again to update the checks.'); renderReview();});
                td.append(input);
                errors.forEach(issue => td.append(cell('small', issue.message)));
                const generated = normalized.get(row.sourceRow)?.[column];
                if (!row.record[column] && generated !== undefined && generated !== null && generated !== '') td.append(cell('small', 'Preview: '+String(generated)));
                tr.append(td);
            });
            body.append(tr);
        });
        field('page').textContent = `Page ${page} of ${pages} · ${visible.length} matching rows · 25 per page`;
        field('previous').disabled = busy || page <= 1; field('next').disabled = busy || page >= pages;
        field('count').textContent = `${selected().length} included · ${rows.length-selected().length} excluded · ${issues.length} issues`;
        buttons();
    }
    field('fill').addEventListener('click', () => {
        const value = field('bulk-value').value.trim();
        if (!value) {message('Enter a value to fill blank fields.', true); return;}
        const count = tools.fillBlanks(rows, field('bulk-field').value, value); invalidate(); renderReview(); message(`${count} blank field(s) filled in included rows. Validate again.`);
    });
    ['search', 'errors-only'].forEach(name => field(name).addEventListener(name === 'search' ? 'input' : 'change', () => {page = 1; renderReview();}));
    field('previous').addEventListener('click', () => {page--; renderReview();}); field('next').addEventListener('click', () => {page++; renderReview();});
    field('preview').addEventListener('click', async () => {
        if (busy || mappingDirty || !selected().length) return;
        invalidate(); setBusy(true);
        try {
            const included = selected(), result = await request(config.previewUrl, {records:included.map(row => row.record)});
            issues = result.errors.map(issue => ({...issue, row:included[issue.row-2]?.sourceRow, message:issue.message.replace(/CSV row (\d+)/g, (_, number) => 'source row '+(included[Number(number)-2]?.sourceRow ?? number))}));
            const badRows = new Set(issues.map(issue => issue.row)); let validIndex = 0;
            included.forEach(row => {if (!badRows.has(row.sourceRow)) normalized.set(row.sourceRow, result.records[validIndex++]);});
            previewToken = issues.length ? null : result.preview_token; showIssues();
            message(issues.length ? `${issues.length} issue(s). Correct or exclude the affected rows, then validate again. Nothing has been added.` : `${included.length} rows passed validation. Check the generated defaults below, then add the batch.`, !!issues.length);
        } catch (problem) {fail(problem);} finally {setBusy(false);}
    });
    field('add').addEventListener('click', async () => {
        if (busy || mappingDirty || !previewToken) return;
        setBusy(true);
        try {
            const result = await request(config.storeUrl, {records:selected().map(row => row.record), preview_token:previewToken});
            resetSource(); matrix = []; field('file').value = ''; field('paste').value = ''; field('worksheet-label').hidden = true;
            message(`${result.count} records added successfully. Existing records were kept.`); field('count').textContent = result.count+' added records';
            window.MasterRegistryImport?.add(result.records);
        } catch (problem) {fail(problem);} finally {setBusy(false);}
    });
    function download(name, values) {
        const url = URL.createObjectURL(new Blob([tools.csv(values)], {type:'text/csv;charset=utf-8'})), link = document.createElement('a');
        link.href = url; link.download = name; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    field('template').addEventListener('click', () => download('tagatuos-masters-'+registry+'-template.csv', [config.columns]));
    field('report').addEventListener('click', () => download('tagatuos-masters-'+registry+'-errors.csv', [['Source row','Field','Issue',...config.columns], ...issues.map(issue => {const row = rows.find(item => item.sourceRow === issue.row); return [issue.row, issue.field, issue.message, ...config.columns.map(column => row?.record[column] ?? '')];})]));
    dialog.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => {if (!busy) dialog.close();}));
    dialog.addEventListener('cancel', event => event.preventDefault());
    document.querySelector('[data-masters-import]').addEventListener('click', () => dialog.showModal());
})();
