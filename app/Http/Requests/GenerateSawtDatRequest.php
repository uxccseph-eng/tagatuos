<?php

namespace App\Http\Requests;

use App\Services\SawtDatService;
use Illuminate\Foundation\Http\FormRequest;

class GenerateSawtDatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->session()->get('preview_access') && $this->session()->has('company_setup');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return SawtDatService::validationRules();
    }
}
