<dialog id="payroll-entry" aria-labelledby="payroll-entry-title" data-journal-url="{{ route('sheets.journal') }}" data-user="{{ $company['full_name'] ?? 'Workspace user' }}">
    <header class="payroll-entry-header">
        <div><span class="payroll-badge" data-entry-status></span><h2 id="payroll-entry-title">Payroll entry</h2><span data-entry-mode></span></div>
        <button type="button" data-entry-close aria-label="Close payroll entry"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </header>
    <div class="payroll-entry-body">
        <section aria-label="Derived payroll totals"><h3>Derived</h3><div class="payroll-entry-cards">
            <div><span>Gross</span><strong data-entry-total="gross">₱0.00</strong></div>
            <div><span>Net Pay</span><strong data-entry-total="net">₱0.00</strong></div>
            <div><span>Employer Cost</span><strong data-entry-total="cost">₱0.00</strong></div>
        </div></section>
        <section><h3>Journal preview</h3><p class="payroll-entry-hint">Calculated from this run’s employee earnings, deductions and employer contributions.</p><div class="payroll-entry-scroll"><table aria-label="Payroll journal preview"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody data-entry-journal></tbody></table></div><p class="payroll-entry-balance" data-entry-balance></p></section>
        <details class="payroll-entry-employees"><summary>Employee breakdown</summary><div class="payroll-entry-scroll"><table aria-label="Payroll employee summary"><thead><tr><th>Employee</th><th>Pay Type</th><th>Gross</th><th>Net Pay</th></tr></thead><tbody data-entry-employees></tbody></table></div></details>
        <section data-entry-remittance hidden><div class="payroll-remittance-heading"><h3>Remittance</h3><button type="button" data-entry-action="remit" data-entry-remit><span class="material-symbols-outlined" aria-hidden="true">payments</span>Remit liabilities…</button></div><p class="payroll-entry-hint">Generate a posted Journal voucher to settle this run’s payroll liabilities.</p><h3 data-remitted-heading hidden>Remitted by</h3><ul class="payroll-entry-list" data-entry-remittances></ul></section>
        <section><h3>Attachments</h3><label class="payroll-entry-attach"><span class="material-symbols-outlined" aria-hidden="true">attach_file</span>Attach document<input data-entry-file type="file" accept="application/pdf,image/png,image/jpeg,text/plain" aria-label="Attach payroll document"></label><p class="payroll-entry-hint">PDF, PNG, JPG or TXT · 1 MB per file, 2 MB total. Saved with this run in this browser tab.</p><ul class="payroll-entry-list" data-entry-files></ul></section>
        <section><h3>Links</h3><form data-entry-link-form class="payroll-entry-link-form"><input data-entry-link type="url" placeholder="Paste an https:// link" aria-label="Payroll document link" required maxlength="2000"><button type="submit"><span class="material-symbols-outlined" aria-hidden="true">add</span>Add</button></form><ul class="payroll-entry-list" data-entry-links></ul></section>
        <section><h3>Inputs</h3><div class="payroll-entry-inputs">
            <label>Pay Date<input data-entry-field="date" type="date"></label>
            <label>Run Name<textarea data-entry-field="name" maxlength="120" rows="2" placeholder="Payroll period / run name"></textarea></label>
            <label>Period Start<input data-entry-field="periodStart" type="date"></label>
            <label>Period End<input data-entry-field="periodEnd" type="date"></label>
            <div><span>Frequency</span><strong data-entry-frequency></strong></div>
        </div></section>
        <p data-entry-void class="payroll-calculation-error"></p><p data-entry-error class="payroll-calculation-error" role="alert"></p>
    </div>
    <footer class="payroll-entry-footer">
        <button type="button" data-entry-action="delete" data-entry-delete class="payroll-entry-danger"><span class="material-symbols-outlined" aria-hidden="true">delete</span>Delete entry</button>
        <button type="button" data-entry-action="void" data-entry-void-button class="payroll-entry-danger"><span class="material-symbols-outlined" aria-hidden="true">block</span>Void</button>
        <button type="button" data-entry-action="duplicate"><span class="material-symbols-outlined" aria-hidden="true">content_copy</span>Duplicate as draft</button>
        <button type="button" data-entry-action="edit" data-entry-edit aria-label="Edit payroll entry"><span class="material-symbols-outlined" aria-hidden="true">edit</span>Edit</button>
        <button type="button" data-entry-action="post" data-entry-post class="payroll-entry-primary"><span class="material-symbols-outlined" aria-hidden="true">done_all</span>Post</button>
        <button type="button" data-entry-close>Close</button>
    </footer>
</dialog>
