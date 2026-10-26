<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrintForm2316Request extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $amount = ['required', 'numeric', 'between:0,999999999.99', 'decimal:0,2'];
        $tin = ['nullable', 'string', 'regex:/^(?:[0-9]{9}|[0-9]{14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{5})?)$/D'];
        $rules = [
            'year' => ['required', 'integer', 'between:2023,2199'],
            'preview' => ['sometimes', 'boolean'],
            'reviewed' => ['required', 'boolean'],
            'use_default_signatory' => ['required', 'boolean'],
            'signatory_name' => ['nullable', 'required_if:use_default_signatory,false', 'string', 'max:150'],
            'signatory_title' => ['nullable', 'required_if:use_default_signatory,false', 'string', 'max:100'],
            'signatory_tin' => $tin,
            'date_signed' => ['nullable', 'date_format:Y-m-d'],
            'employee_name' => ['required', 'string', 'max:150'],
            'employee_rdo' => ['nullable', 'string', 'regex:/^(?:[0-9]{3}|[0-9]{2,3}[A-Z])$/D'],
            'employee_zip' => ['nullable', 'string', 'regex:/^[0-9]{4}$/D'],
            'employer_type' => ['required', Rule::in(['main', 'secondary'])],
            'has_previous' => ['required', 'boolean'],
            'previous_name' => ['nullable', 'required_if:has_previous,true', 'string', 'max:150'],
            'previous_tin' => [...$tin, 'required_if:has_previous,true'],
            'previous_address' => ['nullable', 'required_if:has_previous,true', 'string', 'max:350'],
            'previous_zip' => ['nullable', 'string', 'regex:/^[0-9]{4}$/D'],
            'previous_taxable' => $amount,
            'previous_withheld' => $amount,
            'adjusted_withheld' => $amount,
            'pera_credit' => $amount,
            'records' => ['required', 'array', 'list', 'min:1', 'max:5000'],
            'records.*.source_id' => ['required', 'string', 'max:100', 'distinct:strict'],
            'records.*.employee_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in(['posted'])],
            'records.*.date' => ['required', 'date_format:Y-m-d'],
            'records.*.pay_type' => ['required', Rule::in(['Taxable', 'Non-taxable'])],
        ];
        foreach (['basic', 'allowance', 'de_minimis', 'benefits', 'taxable_benefits', 'contributions', 'withheld'] as $field) {
            $rules['records.*.'.$field] = $amount;
        }

        return $rules;
    }
}
