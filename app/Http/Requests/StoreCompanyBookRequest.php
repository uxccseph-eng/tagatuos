<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyBookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null
            && ! $this->user()->must_change_password
            && in_array($this->user()->type, ['individual', 'firm', 'accountant'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'firm_id' => ['nullable', 'integer', 'min:1', Rule::prohibitedIf($this->user()?->type !== 'accountant')],
            'organization_owner_id' => ['prohibited'],
            'slug' => [
                'nullable', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('companies', 'slug'),
                Rule::notIn(Company::RESERVED_SLUGS),
            ],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'type' => ['prohibited'],
            'must_change_password' => ['prohibited'],
            'users' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug'))) ?: null]);
        }
    }
}
