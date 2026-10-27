<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveClientFollowUpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasApprovedAccess() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['action' => ['required', 'in:create,edit,resolve,reopen']];
        if ($this->input('action') === 'create') {
            return [...$rules, 'request_id' => ['required', 'uuid'], 'category' => ['required', 'in:receipt,form2307,party_details,clarification'], 'transaction' => ['nullable', 'string', 'max:140'], 'transaction_date' => ['nullable', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:200'], 'party' => ['nullable', 'string', 'max:200'], 'amount' => ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'], ...$this->details()];
        }

        return [...$rules, 'version' => ['required', 'integer', 'min:1'], ...($this->input('action') === 'edit' ? $this->details() : ['note' => ['required', 'string', 'min:3', 'max:1000']])];
    }

    /** @return array<string, list<string>> */
    private function details(): array
    {
        return ['request_text' => ['required', 'string', 'min:3', 'max:2000'], 'due_date' => ['nullable', 'date_format:Y-m-d']];
    }
}
