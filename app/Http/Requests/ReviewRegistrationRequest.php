<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_system_owner && $this->user()->hasApprovedAccess();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->routeIs('system.registrations.reject')
            ? ['reason' => ['required', 'string', 'max:1000']]
            : ['username' => ['required', 'string', 'max:64', 'regex:'.User::USERNAME_PATTERN, Rule::notIn(User::RESERVED_USERNAMES), Rule::unique('users', 'username')->ignore($this->route('applicantId'))]];
    }

    protected function prepareForValidation(): void
    {
        foreach (['username', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $field === 'username' ? strtolower(trim($this->input($field))) : trim($this->input($field))]);
            }
        }
    }
}
