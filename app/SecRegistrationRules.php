<?php

namespace App;

use Illuminate\Validation\Rule;

class SecRegistrationRules
{
    public const array DISCLOSURE_DEFAULTS = [
        'going_concern_statement' => "Management has assessed the entity's ability to continue as a going concern. As of the reporting date, management believes the entity has the ability to continue its operations for at least twelve (12) months from the reporting date.",
        'deficiency_tax_assessments' => 'As of the reporting date, the business has no deficiency tax assessment received from the Bureau of Internal Revenue.',
        'tax_cases' => 'As of the reporting date, the business has no pending tax cases before the Bureau of Internal Revenue, the Court of Tax Appeals, or any other judicial body.',
    ];

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function withDisclosureDefaults(array $profile): array
    {
        foreach (self::DISCLOSURE_DEFAULTS as $field => $statement) {
            if (trim((string) ($profile[$field] ?? '')) === '') {
                $profile[$field] = $statement;
            }
        }

        return $profile;
    }

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
