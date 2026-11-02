<section class="settings-card">
    <div class="settings-card-title"><span class="material-symbols-outlined">shopping_cart</span><div><h3>Purchases columns and defaults</h3><p>New purchases and blank import cells use these client defaults. Entered values and existing transactions are preserved.</p></div></div>
    @foreach(['purchase_hide_wvat' => ['Hide WVAT column', 'Hide VAT withholding in the Purchases grid.']] as $name => [$label, $hint])
        <label class="settings-toggle-row" for="setting-{{ $name }}"><span class="material-symbols-outlined">visibility_off</span><span><strong>{{ $label }}</strong><small>{{ $hint }} Values remain available in entry details and reports.</small></span><input type="hidden" name="{{ $name }}" value="0"><input class="settings-switch" type="checkbox" id="setting-{{ $name }}" name="{{ $name }}" value="1" @checked(old($name, $company['sheet_settings'][$name] ?? true))></label>
    @endforeach
    <div class="settings-grid">
        <x-settings-field name="purchase_default_terms" label="Default Purchases payment terms" :value="$company['sheet_settings']['purchase_default_terms'] ?? 'Cash'" :options="['Cash' => 'Cash', 'On Account' => 'On Account']" />
        <x-settings-field name="purchase_default_bank_code" label="Default Purchases payment account" :value="$company['sheet_settings']['purchase_default_bank_code'] ?? ($accounts->contains('code', '1020') ? '1020' : '')" :options="['' => 'Use an available cash / bank account'] + $accounts->mapWithKeys(fn ($account) => [$account->code => $account->code.' · '.$account->name])->all()" hint="Defaults to Cash in Bank when available. Used for blank payment accounts in imports." />
        @foreach(['purchase_default_ewt_code' => ['Default Purchases EWT', 'Income-tax CWT'], 'purchase_default_wvat_code' => ['Default Purchases VAT withholding', 'VAT Withholding']] as $name => [$label, $category])
            <x-settings-field :name="$name" :label="$label" :value="$company['sheet_settings'][$name] ?? ''" :options="['' => 'None (0%)'] + $withholdingCodes->where('category', $category)->mapWithKeys(fn ($code) => [$code['code'] => $code['code'].' / '.$code['rate'].'% / '.$code['name']])->all()" />
        @endforeach
    </div>
    <p>Hiding a column changes the grid display only. It does not remove withholding or change tax calculations.</p>
</section>
