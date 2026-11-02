<section class="settings-card">
    <div class="settings-card-title"><span class="material-symbols-outlined">point_of_sale</span><div><h3>Sales columns and defaults</h3><p>New sales and blank import cells use these client defaults. Entered values and existing transactions are preserved.</p></div></div>
    @foreach(['sales_hide_cwt' => ['Hide CWT column', 'Hide income withholding in the Sales grid.'], 'sales_hide_vat_cwt' => ['Hide VAT withholding column', 'Hide VAT withholding in the Sales grid.']] as $name => [$label, $hint])
        <label class="settings-toggle-row" for="setting-{{ $name }}"><span class="material-symbols-outlined">visibility_off</span><span><strong>{{ $label }}</strong><small>{{ $hint }} Values remain available in entry details and reports.</small></span><input type="hidden" name="{{ $name }}" value="0"><input class="settings-switch" type="checkbox" id="setting-{{ $name }}" name="{{ $name }}" value="1" @checked(old($name, $company['sheet_settings'][$name] ?? false))></label>
    @endforeach
    <div class="settings-grid">
        <x-settings-field name="sales_default_terms" label="Default Sales payment terms" :value="$company['sheet_settings']['sales_default_terms'] ?? 'Cash'" :options="['Cash' => 'Cash', 'On Account' => 'On Account']" />
        <x-settings-field name="sales_default_classification" label="Default Sales classification" :value="$company['sheet_settings']['sales_default_classification'] ?? 'Regular'" :options="['Regular' => 'Regular', 'Top Withholding Agent' => 'Top Withholding Agent', 'Government' => 'Government', 'ZeroRated' => 'Zero Rated', 'PEZA' => 'PEZA / Ecozone']" />
        <x-settings-field name="sales_default_bank_code" label="Default Sales deposit account" :value="$company['sheet_settings']['sales_default_bank_code'] ?? ($accounts->contains('code', '1020') ? '1020' : '')" :options="['' => 'Use an available cash / bank account'] + $accounts->mapWithKeys(fn ($account) => [$account->code => $account->code.' · '.$account->name])->all()" hint="Defaults to Cash in Bank when available. Used for blank deposit accounts in imports." />
        @foreach(['sales_default_cwt_code' => ['Default Sales CWT', 'Income-tax CWT'], 'sales_default_vat_cwt_code' => ['Default Sales VAT withholding', 'VAT Withholding']] as $name => [$label, $category])
            <x-settings-field :name="$name" :label="$label" :value="$company['sheet_settings'][$name] ?? ''" :options="['' => 'None (0%)'] + $withholdingCodes->where('category', $category)->mapWithKeys(fn ($code) => [$code['code'] => $code['code'].' / '.$code['rate'].'% / '.$code['name']])->all()" />
        @endforeach
    </div>
    <p>Hiding a column changes the grid display only. It does not remove withholding or change tax calculations.</p>
</section>
