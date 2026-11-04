<?php

namespace App;

class PayrollColumns
{
    public const CORE = ['basic', 'taxableAllowance', 'deMinimis', 'benefits', 'sssEe', 'phicEe', 'hdmfEe', 'withholding', 'sssEr', 'phicEr', 'hdmfEr'];

    /** @return array<string, array{label: string, fields: array<string, array{label: string, kind: string, tax: string, bir_item: int}>}> */
    public static function groups(): array
    {
        static $groups = null;

        return $groups ??= json_decode(file_get_contents(resource_path('templates/payroll-columns.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{label: string, kind: string, tax: string, bir_item: int}> */
    public static function extras(): array
    {
        $fields = [];
        foreach (self::groups() as $group) {
            $fields += $group['fields'];
        }

        return $fields;
    }

    /** @return list<string> */
    public static function fields(string $kind): array
    {
        $core = match ($kind) {
            'earning' => ['basic', 'taxableAllowance', 'deMinimis', 'benefits'],
            'deduction' => ['sssEe', 'phicEe', 'hdmfEe', 'withholding'],
            'employer' => ['sssEr', 'phicEr', 'hdmfEr'],
            default => [],
        };

        return [...$core, ...array_keys(array_filter(self::extras(), fn (array $field): bool => $field['kind'] === $kind))];
    }

    /** @return list<string> */
    public static function taxableExtras(bool $mwe = false): array
    {
        return array_keys(array_filter(self::extras(), fn (array $field): bool => $field['kind'] === 'earning' && ($field['tax'] === 'taxable' || (! $mwe && $field['tax'] === 'premium'))));
    }

    /** @param array<string, int> $values
     * @param  list<string>  $fields
     */
    public static function sum(array $values, array $fields): int
    {
        return array_sum(array_intersect_key($values, array_flip($fields)));
    }
}
