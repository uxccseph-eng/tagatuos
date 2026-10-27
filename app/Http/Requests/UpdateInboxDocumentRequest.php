<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInboxDocumentRequest extends FormRequest
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
        $rules = ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:metadata,create_draft,link,unlink']];

        return match ($this->input('action')) {
            'metadata' => [...$rules, 'status' => ['required', 'in:received,needs_entry,needs_clarification,archived'], 'assigned_to' => ['nullable', 'integer', 'min:1'], 'notes' => ['nullable', 'string', 'max:2000']],
            'create_draft' => [...$rules, 'ledger_revision' => ['required', 'integer', 'min:0'], 'sheet' => ['required', 'in:sales,purchases'], 'date' => ['required', 'date_format:Y-m-d'], 'party_id' => ['required', 'integer', 'min:1'], 'account_id' => ['required', 'integer', 'min:1'], 'tax_code_id' => ['required', 'integer', 'min:1'], 'gross' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/D'], 'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:1000']],
            'link' => [...$rules, 'ledger_revision' => ['required', 'integer', 'min:0'], 'transaction' => ['required', 'string', 'max:160']],
            'unlink' => [...$rules, 'ledger_revision' => ['required', 'integer', 'min:0']],
            default => $rules,
        };
    }
}
