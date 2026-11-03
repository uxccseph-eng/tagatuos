(() => {
    const root = document.getElementById('financial-statement-main');
    if (!root) return;
    const button = document.getElementById('fs-generate');
    const status = document.getElementById('fs-status');
    document.getElementById('fs-package-form').addEventListener('submit', event => {event.preventDefault(); button.click();});
    document.getElementById('fs-year').addEventListener('change', () => { status.textContent = 'Select View saved to load the details for this year before editing or generating.'; button.disabled = true; });
    document.querySelectorAll('[data-package-field], [data-tax-field]').forEach(input => input.addEventListener('input', () => {document.getElementById('fs-management-confirmed').checked = false;}));
    button.addEventListener('click', async () => {
        if (!root.dataset.generateUrl || !document.getElementById('fs-year-form').reportValidity()) return;
        button.disabled = true;
        status.textContent = 'Calculating the posted ledger and saving financial statements…';
        try {
            const year = Number(document.getElementById('fs-year').value);
            const packageInputs = {tax_overrides: {current: {}, previous: {}}, management_confirmed: document.getElementById('fs-management-confirmed').checked};
            document.querySelectorAll('[data-package-field]').forEach(input => { packageInputs[input.dataset.packageField] = input.value; });
            document.querySelectorAll('[data-tax-field]').forEach(input => { packageInputs.tax_overrides[input.dataset.taxPeriod][input.dataset.taxField] = input.value === '' ? null : input.value; });
            if (!document.getElementById('fs-package-form').reportValidity()) return;
            const response = await fetch(root.dataset.generateUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'X-Company-ID':root.dataset.companyId,'X-Ledger-Revision':root.dataset.revision},
                body: JSON.stringify({reporting_year:year, package_inputs:packageInputs})
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Generation failed. Your saved statement was not replaced.');
            document.getElementById('fs-document').innerHTML = data.html;
            const link = document.getElementById('fs-pdf');
            const url = new URL(root.dataset.pdfUrl, location.origin);
            url.searchParams.set('year', year);
            url.searchParams.set('company_id', root.dataset.companyId);
            link.href = url.href;
            link.hidden = false;
            status.textContent = data.message;
        } catch (error) {
            status.textContent = error.message || 'Connection failed. Check the connection and try again.';
        } finally { button.disabled = false; }
    });
})();
