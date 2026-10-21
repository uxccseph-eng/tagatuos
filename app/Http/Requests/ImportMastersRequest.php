<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportMastersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['records' => ['required', 'array', 'list', 'min:1', 'max:500'], 'records.*' => ['required', 'array'], 'preview_token' => ['nullable', 'string', 'max:100']];
    }
}
