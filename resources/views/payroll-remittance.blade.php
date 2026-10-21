<dialog id="payroll-remittance" aria-labelledby="payroll-remittance-title" aria-describedby="payroll-remittance-description">
    <header><h2 id="payroll-remittance-title">Remit payroll liabilities</h2><button type="button" data-remittance-close aria-label="Close remittance window"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>
    <p id="payroll-remittance-description">Creates a Journal Voucher that debits the chosen payroll liabilities and credits cash — linked back to each run. Tick more runs to clear them in one remittance.</p>
    <form id="payroll-remittance-form">
        <div class="remittance-field-pair"><label>Date<input type="date" data-remittance-date required></label><label>Reference<input type="text" data-remittance-reference maxlength="255" placeholder="Check / transfer no…"></label></div>
        <fieldset><legend>Payroll runs</legend><div data-remittance-runs></div></fieldset>
        <fieldset><legend>Liabilities to remit</legend><div data-remittance-liabilities></div></fieldset>
        <label>Cash/Bank account<select data-remittance-bank><option value="">Select a cash / bank account</option></select></label>
        <details data-remittance-mapping><summary>Liability account mappings</summary><p>Select the payable accounts from Masters. Saved mappings are suggested for the next remittance.</p><div data-remittance-accounts></div></details>
        <div class="remittance-total"><span>Total remittance:</span><strong data-remittance-total>₱0.00</strong></div>
        <p data-remittance-error role="alert"></p>
        <footer><button type="button" data-remittance-draft>Save draft</button><button type="submit" class="remittance-primary">Remit &amp; post</button><button type="button" data-remittance-close>Close</button></footer>
    </form>
</dialog>
