<?php

namespace App\Http\Requests;

use App\Services\CompanyLedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCompanyDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        abort_if(strlen($this->getContent()) > 8 * 1024 * 1024, 413, 'This private draft exceeds the supported save size.');

        return $this->user()?->hasApprovedAccess() && $this->attributes->has('active_company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:0'],
            'payload' => ['required', 'array:format,company_id,revision,changes,before,request,error'],
            'payload.format' => ['required', 'integer', 'in:2'],
            'payload.company_id' => ['required', 'integer'],
            'payload.revision' => ['required', 'integer', 'min:0'],
            'payload.changes' => ['present', 'array', 'list', 'max:10000'],
            'payload.before' => ['present', 'array', 'list', 'max:10000'],
            'payload.request' => ['present', 'nullable', 'array:revision,request_id,operations'],
            'payload.request.revision' => ['required_with:payload.request', 'integer', 'min:0'],
            'payload.request.request_id' => ['required_with:payload.request', 'uuid'],
            'payload.request.operations' => ['required_with:payload.request', 'array', 'list', 'max:10000'],
            'payload.error' => ['present', 'nullable', 'array'],
        ];
        foreach (['payload.changes', 'payload.before', 'payload.request.operations'] as $path) {
            $rules[$path.'.*'] = ['array:sheet,id,record'.($path === 'payload.before' ? '' : ',expected_version')];
            $rules[$path.'.*.sheet'] = ['required', Rule::in([...CompanyLedgerService::SHEETS, 'payroll'])];
            $rules[$path.'.*.id'] = ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,120}$/D'];
            $rules[$path.'.*.record'] = ['present', 'nullable', 'array'];
            if ($path !== 'payload.before') {
                $rules[$path.'.*.expected_version'] = ['required', 'integer', 'min:0'];
            }
        }

        return $rules;
    }
}
