<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveLarkDocumentBackupRequest extends FormRequest
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
        return [
            'version' => ['required', 'integer', 'min:0'],
            'app_id' => ['required', 'string', 'regex:/^cli_[a-zA-Z0-9]{4,70}$/D'],
            'app_secret' => ['nullable', 'string', 'min:8', 'max:255', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'folder_token' => ['required', 'string', 'regex:/^[a-zA-Z0-9]{10,100}$/D'],
            'enabled' => ['sometimes', 'boolean'],
            'consent' => ['accepted_if:enabled,1'],
        ];
    }
}
