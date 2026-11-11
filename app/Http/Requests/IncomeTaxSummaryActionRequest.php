<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncomeTaxSummaryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasApprovedAccess() && $this->attributes->has('active_company');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['settings', 'credit', 'reverse', 'payment', 'nolco', 'apply_nolco', 'adjustment'])],
            'year' => ['required', 'integer', 'between:2024,2099'],
            'quarter' => ['required_if:action,payment,apply_nolco,adjustment', 'nullable', 'integer', 'between:1,4'],
            'amount' => ['required_if:action,credit,reverse,payment,nolco,apply_nolco,adjustment', 'nullable', 'regex:/^-?[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'penalty' => ['nullable', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'date' => ['required_if:action,payment', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'origin_year' => ['required_if:action,credit,nolco', 'nullable', 'integer', 'between:1900,2099'],
            'credit_id' => ['required_if:action,reverse,apply_nolco', 'nullable', 'integer', 'min:1'],
            'credit_type' => ['required_if:action,credit', 'nullable', Rule::in(['prior_excess', 'overpayment', 'excess_cwt'])],
            'adjustment_target' => ['sometimes', Rule::in(['taxable', 'mcit_gross', 'both'])],
            'subtype' => ['nullable', Rule::in(['regular', 'mcit'])],
            'rate' => ['required_if:action,settings', 'nullable', 'string', Rule::in(['20', '25'])],
            'mcit' => ['required_if:action,settings', 'nullable', Rule::in(['applicable', 'not_applicable'])],
            'small_eligible' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:150'],
            'method' => ['nullable', Rule::in(['Cash', 'Check', 'eFPS', 'GCash', 'Tax Debit Memo', 'Other'])],
            'post_now' => ['sometimes', 'boolean'],
            'bank' => ['nullable', 'string', 'max:30'],
            'linked_source' => ['nullable', 'string', 'max:150'],
            'net_account' => ['nullable', 'string', 'max:30'],
            'prepaid_account' => ['nullable', 'string', 'max:30'],
            'penalty_account' => ['nullable', 'string', 'max:30'],
            'request_id' => ['required', 'uuid'],
            'preview_hash' => ['sometimes', 'required', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['sometimes', 'accepted'],
        ];
    }
}
