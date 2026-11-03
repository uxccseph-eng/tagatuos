(() => {
    const root = document.getElementById('financial-statement-main');
    if (!root) return;
    const button = document.getElementById('fs-generate');
    const status = document.getElementById('fs-status');
    button.addEventListener('click', async () => {
        if (!root.dataset.generateUrl || !document.getElementById('fs-year-form').reportValidity()) return;
        button.disabled = true;
        status.textContent = 'Calculating the posted ledger and saving financial statements…';
        try {
            const year = Number(document.getElementById('fs-year').value);
            const response = await fetch(root.dataset.generateUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content,'X-Company-ID':root.dataset.companyId,'X-Ledger-Revision':root.dataset.revision},
                body: JSON.stringify({reporting_year:year})
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
