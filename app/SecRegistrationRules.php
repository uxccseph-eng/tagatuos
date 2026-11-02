<?php

namespace App;

use Illuminate\Validation\Rule;

class SecRegistrationRules
{
    /** @return array<string, mixed> */
    public static function forCompany(bool $isIndividual, bool $hasAuthorizedShares): array
    {
        return [
            'going_concern_statement' => ['nullable', 'string', 'max:5000'],
            'deficiency_tax_assessments' => ['nullable', 'string', 'max:5000'],
            'tax_cases' => ['nullable', 'string', 'max:5000'],
            'registration_date' => [Rule::excludeIf($isIndividual), 'nullable', 'date_format:Y-m-d'],
            'sec_registration_number' => [Rule::excludeIf($isIndividual), 'nullable', 'string', 'max:255'],
            'authorized_shares' => [Rule::excludeIf($isIndividual), 'nullable', 'integer', 'min:0', 'max:999999999999'],
            'par_value_per_share' => [Rule::excludeIf($isIndividual), 'nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/D'],
            'issued_outstanding_shares' => [Rule::excludeIf($isIndividual), 'nullable', 'integer', 'min:0', 'max:999999999999', ...($hasAuthorizedShares ? ['lte:authorized_shares'] : [])],
        ];
    }
}
