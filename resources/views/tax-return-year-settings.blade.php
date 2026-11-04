<section class="returns-year-settings" aria-labelledby="year-settings-title">
    <div class="return-card-top"><h2 id="year-settings-title">Income Tax Setting — {{ $year }}</h2><span class="return-badge">{{ $annualSettings['revision'] ? 'Saved · revision '.$annualSettings['revision'] : 'Defaults · review required' }}</span></div>
    <p>One set of reviewed settings for this client’s quarterly and annual income tax returns. Prepared versions retain their original settings; changed settings are flagged for review.</p>
    <form id="return-year-settings" data-url="{{ route('tax-returns.year-settings', ['company_id' => $company['company_id']]) }}">
        <input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="revision" value="{{ $annualSettings['revision'] }}">
        <fieldset @disabled(!$canEditTaxSettings)><div class="return-review-grid">
            <label>Income tax setting<select name="regime">@foreach(['regular' => 'Regular corporate rate', 'special' => 'Mixed / special rates', 'exempt' => 'Exempt'] as $value => $label)<option value="{{ $value }}" @selected($annualSettings['settings']['regime'] === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Deduction method<select name="deduction_method"><option value="itemized" @selected($annualSettings['settings']['deduction_method'] === 'itemized')>Itemized</option><option value="osd" @selected($annualSettings['settings']['deduction_method'] === 'osd')>OSD</option></select></label>
            <label>Reviewed MCIT start year<input type="number" name="mcit_start_year" min="1900" max="2199" value="{{ $annualSettings['settings']['mcit_start_year'] }}" placeholder="Enter if applicable"></label>
            <label>President / authorized officer<input name="president" maxlength="150" value="{{ $annualSettings['settings']['president'] }}"></label><label>Treasurer<input name="treasurer" maxlength="150" value="{{ $annualSettings['settings']['treasurer'] }}"></label>
        </div><label class="return-check"><input type="checkbox" name="reviewed" required> I verified eligibility, the annual election and the MCIT start year.</label><button class="returns-button" type="submit">Save annual settings</button></fieldset>
        @if(!$canEditTaxSettings)<p>Your role needs Settings access to edit these values.</p>@endif
        <p class="returns-caption">These settings are retained with income tax worksheets. RCIT, MCIT, OSD, special-rate eligibility and income allocations still require a separately reviewed computation; saving a setting does not calculate them.</p><p class="return-inline-status" role="status"></p>
    </form>
</section>
