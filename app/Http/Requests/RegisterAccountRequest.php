<?php

namespace App\Http\Requests;

use App\Rules\AccountPassword;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')],
            'type' => ['required', Rule::in(['individual', 'firm'])],
            'password' => ['required', 'string', 'max:128', 'confirmed', new AccountPassword, Password::min(12)->mixedCase()->numbers()],
            'must_change_password' => ['prohibited'],
            'role' => ['prohibited'],
            'company_id' => ['prohibited'],
            'username' => ['prohibited'],
            'registration_status' => ['prohibited'],
            'is_system_owner' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
