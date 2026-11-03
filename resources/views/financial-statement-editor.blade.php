<details class="fs-editor"><summary>Package details, disclosures and tax schedules</summary>
<p>These details are saved with the selected annual statement when you select Generate and save. Company and SEC information comes from Settings. Defaults must be reviewed against the client’s circumstances.</p>
<form id="fs-package-form">
<fieldset><legend>Management and approval</legend><div class="fs-editor-grid">
@foreach(['president_name' => 'Individual taxpayer / President / Managing Partner', 'ceo_name' => 'Chief Executive Officer or equivalent', 'cfo_name' => 'Chief Financial Officer or equivalent'] as $key => $label)<label>{{ $label }}<input type="text" data-package-field="{{ $key }}" value="{{ $packageInputs[$key] ?? '' }}" maxlength="255"></label>@endforeach
<label>Approval date<input type="date" data-package-field="approval_date" value="{{ $packageInputs['approval_date'] ?? '' }}"></label>
</div></fieldset>
<fieldset><legend>Notes and accounting policies</legend><div class="fs-editor-grid">
@foreach(\App\Services\FinancialStatementPackage::TEXT_FIELDS as $key => $label)<label>{{ $label }}<textarea data-package-field="{{ $key }}" rows="{{ in_array($key, ['management_statement', 'ppe_policy']) ? 5 : 3 }}" maxlength="15000">{{ $packageInputs[$key] ?? '' }}</textarea></label>@endforeach
</div></fieldset>
<fieldset><legend>Annual tax schedule overrides</legend><p>Leave blank to use posted journal figures. Enter 0 to explicitly override an amount to zero. Input VAT classifications use Masters account RELIEF categories; mixed or missing classifications remain unclassified. These are book figures, and must be reconciled to filed tax returns. If moving unclassified VAT to another row, also set the unclassified row to 0.</p>
<table><thead><tr><th>Schedule field</th><th>Current year</th><th>Prior year</th></tr></thead><tbody>
@foreach(\App\Services\FinancialStatementPackage::TAX_FIELDS as $key => $label)<tr><td>{{ $label }}</td>@foreach(['current', 'previous'] as $period)<td><input type="number" step="0.01" min="-999999999999" max="999999999999" aria-label="{{ $label }} {{ $period }}" data-tax-period="{{ $period }}" data-tax-field="{{ $key }}" value="{{ $packageInputs['tax_overrides'][$period][$key] ?? '' }}"></td>@endforeach</tr>@endforeach
</tbody></table></fieldset>
<label class="fs-confirm"><input type="checkbox" id="fs-management-confirmed" @checked($packageInputs['management_confirmed'] ?? false)> Management has reviewed and confirmed the responsibility statement and disclosures for this reporting year.</label>
<p>Confirmation records the review declaration. It does not sign the document, establish an audit opinion, or submit a tax return.</p>
</form></details>
