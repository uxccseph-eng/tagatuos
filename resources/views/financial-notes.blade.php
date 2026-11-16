<section class="notes-workspace" aria-label="Notes to financial statements demo">
    <div class="notes-notice"><strong>Demo draft</strong><p>Sample reporting settings with figures from this book. Not approved or ready for filing. Settings stay in this page only.</p></div>
    <div class="notes-controls books-toolbar">
        <label for="notes-year">Fiscal year<input id="notes-year" type="number" min="1901" max="2199" value="{{ now('Asia/Manila')->year }}"></label>
        <div><button type="button" id="notes-settings-open">Reporting settings</button><button type="button" id="notes-pdf" disabled>Download PDF</button><button type="button" id="notes-word" disabled>Download Word</button></div>
    </div>
    <p id="notes-status" role="status" aria-live="polite">Choose demo reporting settings to generate a preview.</p>
    <div id="notes-empty"><h2>Prepare your notes</h2><p>Select the framework and review the sample disclosures. Account balances come from posted entries; draft and void transactions are excluded.</p><button type="button" id="notes-start">Set up demo reporting</button></div>
    <div class="notes-layout" id="notes-result" hidden>
        <nav id="notes-index" aria-label="Notes contents"></nav>
        <article class="notes-paper" id="notes-document" aria-label="Demo notes document"></article>
    </div>
    <dialog id="notes-settings" aria-labelledby="notes-settings-title">
        <form id="notes-settings-form">
            <header><div><h2 id="notes-settings-title">Demo reporting settings</h2><p>These sample choices do not change company registration or accounting policies.</p></div><button type="button" data-notes-close aria-label="Close reporting settings">×</button></header>
            <div class="notes-fields">
                <label>Sample framework<select name="framework" required><option value="PFRS for SMEs">PFRS for SMEs</option><option value="Full PFRS">Full PFRS</option><option value="PFRS for Small Entities">PFRS for Small Entities</option></select></label>
                <label>Materiality (PHP, optional)<input name="materiality" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" placeholder="Leave blank until assessed"></label>
                <label class="notes-wide">Sample nature of operations<textarea name="operations" maxlength="2000" rows="3" required>Demo entity engaged in trading and services. Replace this sample description before actual use.</textarea></label>
                <label>SEC registration<select name="sec"><option>Not answered</option><option>Sample: SEC-registered corporation</option><option>Sample: not SEC-registered</option></select></label>
                <label>Public or regulated status<select name="regulated"><option>Not answered</option><option>Sample: private, unregulated entity</option><option>Sample: public or regulated entity</option></select></label>
                <label class="notes-wide">Policy decisions and estimates<textarea name="policies" maxlength="4000" rows="3" placeholder="Enter sample policy choices for this demo"></textarea></label>
                <label class="notes-wide">Commitments, contingencies and subsequent events<textarea name="events" maxlength="4000" rows="3" placeholder="Leave blank if not yet reviewed"></textarea></label>
                <label class="notes-check notes-wide"><input name="tax" type="checkbox">Include tax-related book balances (these do not establish taxes paid)</label>
            </div>
            <p id="notes-settings-error" role="alert"></p>
            <footer><button type="button" data-notes-close>Cancel</button><button type="submit">Generate demo preview</button></footer>
        </form>
    </dialog>
</section>
