<section class="settings-card">
    <div class="settings-card-title"><span class="material-symbols-outlined">description</span><div><h3>Disclosures</h3><p>Enter the statements approved for the reporting period. Examples are guidance only and are not saved automatically.</p></div></div>
    <div class="settings-disclosures">
        <x-settings-field name="going_concern_statement" label="Going concern statement" type="textarea" :value="$company['going_concern_statement'] ?? ''" rows="4" maxlength="5000" placeholder="Describe management's assessment of the entity's ability to continue as a going concern." hint="Include any material uncertainty and the period covered by the assessment." />
        <x-settings-field name="deficiency_tax_assessments" label="Deficiency tax assessments" type="textarea" :value="$company['deficiency_tax_assessments'] ?? ''" rows="3" maxlength="5000" placeholder="Describe any deficiency tax assessment received from the Bureau of Internal Revenue, or state that there are none as of the reporting date." hint="Review the actual status before entering a statement." />
        <x-settings-field name="tax_cases" label="Tax cases" type="textarea" :value="$company['tax_cases'] ?? ''" rows="3" maxlength="5000" placeholder="Describe pending tax cases before the BIR, Court of Tax Appeals, or other judicial bodies, or state that there are none as of the reporting date." hint="Include relevant case details when applicable." />
    </div>
</section>
<section class="settings-card">
    <div class="settings-card-title"><span class="material-symbols-outlined">business</span><div><h3>Corporation / partnership registration</h3><p>For non-individual taxpayers only. Use the SEC certificate and Articles of Incorporation or Partnership.</p></div></div>
    @if(($company['entity_type'] ?? 'corporation') === 'individual')
        <p class="settings-footnote">SEC registration and share capital fields do not apply to this individual taxpayer. You can still save the disclosures above.</p>
    @endif
    <fieldset class="settings-registration-fields" @disabled(($company['entity_type'] ?? 'corporation') === 'individual')>
        <legend class="sr-only">SEC registration and share capital</legend>
        <div class="settings-grid">
            <x-settings-field name="registration_date" label="Date of incorporation" type="date" :value="$company['registration_date'] ?? ''" hint="From the SEC certificate. This is the same incorporation date shown under BIR Registration." />
            <x-settings-field name="sec_registration_number" label="SEC registration no." :value="$company['sec_registration_number'] ?? ''" maxlength="255" placeholder="e.g. 2023100122011-00" hint="Enter the number exactly as it appears on the SEC certificate." />
            <x-settings-field name="authorized_shares" label="Authorized shares" :value="$company['authorized_shares'] ?? ''" type="number" min="0" max="999999999999" step="1" hint="Number of shares authorized by the Articles. Enter digits without commas." />
            <x-settings-field name="par_value_per_share" label="Par value per share" :value="$company['par_value_per_share'] ?? ''" type="number" min="0" max="999999999999.9999" step="0.0001" hint="For example, 1.00 or 100.00. Leave blank for shares with no par value." />
            <x-settings-field name="issued_outstanding_shares" label="Issued / outstanding shares" :value="$company['issued_outstanding_shares'] ?? ''" type="number" min="0" max="999999999999" step="1" hint="Shares issued and outstanding as of the reporting date. Enter digits without commas." />
        </div>
    </fieldset>
</section>
