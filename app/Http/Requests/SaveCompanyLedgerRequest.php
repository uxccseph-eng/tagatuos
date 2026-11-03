<?php

namespace App\Http\Requests;

use App\Services\CompanyLedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCompanyLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if(strlen($this->getContent()) > 8 * 1024 * 1024, 413, 'This book exceeds the supported save size. Contact the system owner before adding more attachments.');

        return $this->user()?->hasApprovedAccess() && $this->attributes->has('active_company');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid'],
            'state' => ['required_without:operations', 'array', Rule::prohibitedIf($this->has('operations'))],
            'payroll' => [Rule::requiredIf(! $this->has('operations') && ! $this->has('payroll')), 'array', 'list', 'max:5000', Rule::prohibitedIf($this->has('operations'))],
            'operations' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:10000'],
            'operations.*' => ['required', 'array:sheet,id,expected_version,record'],
            'operations.*.sheet' => ['required', Rule::in([...CompanyLedgerService::SHEETS, 'payroll'])],
            'operations.*.id' => ['required', 'regex:/^[A-Za-z0-9_.:-]{1,120}$/D'],
            'operations.*.expected_version' => ['required', 'integer', 'min:0'],
            'operations.*.record' => ['present', 'nullable', 'array'],
        ];
    }
}
