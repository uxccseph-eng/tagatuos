<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePortalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->companies()
            ->where('companies.id', $this->route('companyId'))->wherePivot('role', 'owner')->wherePivot('is_suspended', false)->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::notIn(Company::RESERVED_SLUGS), Rule::unique('companies', 'slug')->ignore($this->route('companyId'))],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }
    }
}
