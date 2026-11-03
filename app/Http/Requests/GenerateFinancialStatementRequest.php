<?php

namespace App\Http\Requests;

use App\Services\FinancialStatementPackage;
use Illuminate\Foundation\Http\FormRequest;

class GenerateFinancialStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->attributes->get('active_company') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $keys = [...array_keys(FinancialStatementPackage::TEXT_FIELDS), 'approval_date', 'president_name', 'ceo_name', 'cfo_name', 'management_confirmed', 'tax_overrides'];
        $rules = ['reporting_year' => ['required', 'integer', 'between:1902,2199'], 'package_inputs' => ['sometimes', 'array:'.implode(',', $keys)]];
        foreach (FinancialStatementPackage::TEXT_FIELDS as $key => $label) {
            $rules['package_inputs.'.$key] = ['nullable', 'string', 'max:15000'];
        }
        foreach (['president_name', 'ceo_name', 'cfo_name'] as $key) {
            $rules['package_inputs.'.$key] = ['nullable', 'string', 'max:255'];
        }
        $rules['package_inputs.approval_date'] = ['nullable', 'date_format:Y-m-d'];
        $rules['package_inputs.management_confirmed'] = ['sometimes', 'boolean'];
        $rules['package_inputs.tax_overrides'] = ['sometimes', 'array:current,previous'];
        foreach (['current', 'previous'] as $period) {
            $rules['package_inputs.tax_overrides.'.$period] = ['sometimes', 'array:'.implode(',', array_keys(FinancialStatementPackage::TAX_FIELDS))];
            foreach (FinancialStatementPackage::TAX_FIELDS as $key => $label) {
                $rules['package_inputs.tax_overrides.'.$period.'.'.$key] = ['nullable', 'numeric', 'between:-999999999999,999999999999', 'decimal:0,2'];
            }
        }

        return $rules;
    }
}
