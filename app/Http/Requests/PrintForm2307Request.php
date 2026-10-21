<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrintForm2307Request extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preview' => ['sometimes', 'boolean'],
            'quarter' => ['required', 'integer', 'between:1,4'],
            'year' => ['required', 'integer', 'between:1900,2100'],
            'use_default_signatory' => ['required', 'boolean'],
            'signatory_name' => ['nullable', 'required_if:use_default_signatory,false', 'string', 'max:150'],
            'signatory_title' => ['nullable', 'required_if:use_default_signatory,false', 'string', 'max:100'],
            'signatory_tin' => ['nullable', 'string', 'regex:/^(?:[0-9]{9}|[0-9]{3}-[0-9]{3}-[0-9]{3})$/D'],
            'records' => ['required', 'array', 'list', 'min:1', 'max:50000'],
            'records.*' => ['required', 'array'],
            'records.*.source_id' => ['required', 'string', 'max:100', 'distinct:strict'],
            'records.*.supplier_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in(['posted'])],
            'records.*.transaction_date' => ['required', 'date_format:Y-m-d'],
            'records.*.atc_code' => ['required', 'string', 'regex:/^W[IC][0-9]{3}$/D'],
            'records.*.income_payment' => ['required', 'numeric', 'gt:0', 'max:99999999999.99', 'decimal:0,2'],
            'records.*.tax_withheld' => ['required', 'numeric', 'gt:0', 'max:99999999999.99', 'decimal:0,2'],
        ];
    }
}
