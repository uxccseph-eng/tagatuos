<?php

namespace App\Services\Tax;

use Carbon\CarbonImmutable;

class Bir0619EPrintData
{
    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function build(object $record, array $snapshot): array
    {
        $profile = $snapshot['taxpayer'];
        $calculation = $snapshot['calculation'];
        $tax = (int) $calculation['tax'];
        $penalties = (int) $calculation['surcharge'] + (int) $calculation['interest'] + (int) $calculation['compromise'];
        $tin = preg_replace('/[^0-9]/', '', (string) ($profile['tin'] ?? ''));

        return [
            'mapped' => (int) ($calculation['additional_tax'] ?? 0) === 0 && (int) ($calculation['credits'] ?? 0) === 0,
            'name' => mb_strtoupper((string) ($profile['registered_name'] ?? $profile['company_name'] ?? '')),
            'address' => mb_strtoupper(trim((string) ($profile['address'] ?? implode(' ', array_filter(array_map(fn (string $key): string => (string) ($profile[$key] ?? ''), ['building', 'street', 'barangay', 'city', 'province'])))))),
            'tin' => substr($tin, 0, 9),
            'branch' => str_pad((string) ($profile['branch_code'] ?? substr($tin, 9) ?: '0'), 5, '0', STR_PAD_LEFT),
            'rdo' => mb_strtoupper((string) ($profile['rdo'] ?? '')),
            'zip' => (string) ($profile['zip_code'] ?? ''),
            'contact' => (string) ($profile['contact_number'] ?? ''),
            'email' => mb_strtoupper((string) ($profile['contact_email'] ?? '')),
            'month' => CarbonImmutable::parse($record->period_from)->format('mY'),
            'due' => empty($snapshot['period']['due']) ? '' : CarbonImmutable::parse($snapshot['period']['due'])->format('mdY'),
            'amended' => (bool) ($snapshot['amended'] ?? false),
            'withheld' => $tax > 0,
            'category' => strtolower((string) ($profile['agent_category'] ?? '')),
            'amounts' => ['14' => $tax, '15' => 0, '16' => $tax, '17A' => (int) $calculation['surcharge'], '17B' => (int) $calculation['interest'], '17C' => (int) $calculation['compromise'], '17D' => $penalties, '18' => $tax + $penalties],
        ];
    }
}
