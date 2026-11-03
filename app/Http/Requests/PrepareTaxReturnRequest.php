<?php

namespace App\Http\Requests;

use App\Services\Tax\TaxReturnCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrepareTaxReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->attributes->get('active_company') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'form' => ['required', Rule::in(array_keys(TaxReturnCatalog::forms()))],
            'year' => ['required', 'integer', 'between:2023,2199'],
            'period' => ['required', 'integer', 'between:1,12'],
            'request_id' => ['required', 'uuid'],
            'ledger_revision' => ['required', 'integer', 'min:0'],
            'source_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'reviewed_tax' => ['nullable', 'string', 'regex:/^-?[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'xml_requested' => ['sometimes', 'boolean'],
            'xml_vat_reviewed' => ['sometimes', 'boolean'],
            'xml_carryover_input' => ['sometimes', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'xml_remittance_month1' => ['sometimes', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'xml_remittance_month2' => ['sometimes', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'xml_prior_overremittance' => ['sometimes', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'xml_overpayment_action' => ['sometimes', 'nullable', Rule::in(['refund', 'certificate', 'carry_over'])],
            'additional_tax' => ['required', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'credits' => ['required', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'surcharge' => ['required', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'interest' => ['required', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'compromise' => ['required', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D'],
            'review_note' => ['required', 'string', 'min:3', 'max:2000'],
            'reviewed' => ['accepted'],
        ];
    }
}
