<?php

namespace App\Http\Requests;

use App\Rules\AccountPassword;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeAccountPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->hasOnboardingProof() ? ['nullable', 'string', 'max:128'] : ['bail', 'required', 'string', 'max:128', new AccountPassword, 'current_password:web'],
            'password' => ['required', 'string', 'max:128', 'confirmed', 'different:current_password', new AccountPassword, Password::min(12)->mixedCase()->numbers()],
        ];
    }

    public function hasOnboardingProof(): bool
    {
        return $this->user()?->must_change_password
            && $this->session()->get('onboarding_user_id') === $this->user()->getKey();
    }
}
