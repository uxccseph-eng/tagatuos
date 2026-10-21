<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateSawtDatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'form_type' => ['required', Rule::in(['1700', '1701', '1701Q', '1702', '1702Q', '2550M', '2550Q', '2551Q', '2553'])],
            'tax_kind' => ['required', Rule::in(['income', 'vat'])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'taxpayer' => ['required', 'array'],
            'taxpayer.tin' => ['required', 'string', 'max:20'],
            'taxpayer.entity_type' => ['required', Rule::in(['corporation', 'individual'])],
            'taxpayer.registered_name' => ['nullable', 'required_if:taxpayer.entity_type,corporation', 'string', 'max:50'],
            'taxpayer.last_name' => ['nullable', 'required_if:taxpayer.entity_type,individual', 'string', 'max:30'],
            'taxpayer.first_name' => ['nullable', 'required_if:taxpayer.entity_type,individual', 'string', 'max:30'],
            'taxpayer.middle_name' => ['nullable', 'string', 'max:30'],
            'taxpayer.rdo' => ['required', 'string', 'regex:/^(?:[0-9]{3}|[0-9]{2}[A-Za-z])$/D'],
            'records' => ['required', 'array', 'list', 'min:1', 'max:50000'],
            'records.*' => ['required', 'array'],
            'records.*.source_id' => ['required', 'string', 'max:100', 'distinct:strict'],
            'records.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:to'],
            'records.*.status' => ['required', Rule::in(['posted'])],
            'records.*.tin' => ['required', 'string', 'max:20'],
            'records.*.entity_type' => ['required', Rule::in(['corporation', 'individual'])],
            'records.*.registered_name' => ['nullable', 'required_if:records.*.entity_type,corporation', 'string', 'max:50'],
            'records.*.last_name' => ['nullable', 'required_if:records.*.entity_type,individual', 'string', 'max:30'],
            'records.*.first_name' => ['nullable', 'required_if:records.*.entity_type,individual', 'string', 'max:30'],
            'records.*.middle_name' => ['nullable', 'string', 'max:30'],
            'records.*.nature_income' => ['nullable', 'string', 'max:50'],
            'records.*.atc' => ['required', 'string', 'regex:/^W[ICVB][0-9]{3}$/D'],
            'records.*.tax_rate' => ['required', 'numeric', 'gt:0', 'max:99.99', 'decimal:0,2'],
        ];
        foreach (['income_payment', 'amount_withheld'] as $field) {
            $rules['records.*.'.$field] = ['required', 'numeric', 'gt:0', 'max:99999999999.99', 'decimal:0,2'];
        }

        return $rules;
    }
}
