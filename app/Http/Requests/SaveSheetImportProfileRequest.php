<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveSheetImportProfileRequest extends FormRequest
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
            'sheet' => ['required', 'in:sales,purchases,payroll'],
            'headers' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'headers.*' => ['nullable', 'string', 'max:150'],
            'mapping' => ['required', 'array', 'min:1', 'max:20'],
            'mapping.*' => ['nullable', 'integer', 'between:0,99'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }
}
