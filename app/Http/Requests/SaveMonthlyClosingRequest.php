<?php

namespace App\Http\Requests;

use App\Models\MonthlyClosing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMonthlyClosingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasApprovedAccess() && $this->user()->companies()->where('companies.id', $this->route('companyId'))->wherePivot('is_suspended', false)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:0'], 'action' => ['required', Rule::in(['save', 'reopen'])]];
        if ($this->input('action') === 'reopen') {
            return [...$rules, 'reason' => ['required', 'string', 'min:5', 'max:1000']];
        }

        return [...$rules,
            'status' => ['required', Rule::in(array_keys(MonthlyClosing::STATUSES))],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
            'checklist' => ['required', 'array:'.implode(',', array_keys(MonthlyClosing::CHECKLIST)), 'size:'.count(MonthlyClosing::CHECKLIST)],
            ...collect(array_keys(MonthlyClosing::CHECKLIST))->mapWithKeys(fn (string $key): array => ['checklist.'.$key => ['required', 'boolean']])->all(),
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
