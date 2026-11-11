<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VatSummaryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasApprovedAccess() && $this->attributes->has('active_company');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['credit', 'reverse', 'payment', 'carryover', 'close'])],
            'year' => ['required', 'integer', 'between:2023,2198'],
            'quarter' => ['required_if:action,payment,close', 'nullable', 'integer', 'between:1,4'],
            'amount' => ['required_if:action,credit,reverse,payment', 'nullable', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'penalty' => ['nullable', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'date' => ['required_if:action,payment,close', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'credit_id' => ['required_if:action,reverse', 'nullable', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:150'],
            'method' => ['nullable', Rule::in(['Cash', 'Check', 'eFPS', 'GCash', 'Tax Debit Memo', 'Other'])],
            'post_now' => ['sometimes', 'boolean'],
            'bank' => ['nullable', 'string', 'max:30'],
            'linked_source' => ['nullable', 'string', 'max:150'],
            'net_account' => ['nullable', 'string', 'max:30'],
            'penalty_account' => ['nullable', 'string', 'max:30'],
            'input_account' => ['nullable', 'string', 'max:30'],
            'output_account' => ['nullable', 'string', 'max:30'],
            'vat_credit_account' => ['nullable', 'string', 'max:30'],
            'request_id' => ['required', 'uuid'],
            'preview_hash' => ['sometimes', 'required', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['sometimes', 'accepted'],
        ];
    }
}
