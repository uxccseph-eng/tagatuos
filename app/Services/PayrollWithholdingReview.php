<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PayrollWithholdingReview
{
    private const TABLES = [
        'monthly' => [[2083300, 0, 15], [3333300, 187500, 20], [6666700, 854180, 25], [16666700, 3354180, 30], [66666700, 18354180, 35]],
        'semi_monthly' => [[1041700, 0, 15], [1666700, 93750, 20], [3333300, 427070, 25], [8333300, 1677070, 30], [33333300, 9177070, 35]],
        'weekly' => [[480800, 0, 15], [769200, 43260, 20], [1538500, 197120, 25], [3846200, 774045, 30], [15384600, 4235565, 35]],
    ];

    public function cents(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value)) {
            $this->fail('Imported payroll amounts must be explicit non-negative amounts with at most two decimals. Enter 0.00 when zero.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** @param array<string, mixed> $employee
     * @return array{reference: int, actual: int, difference: int, needs_review: bool}
     */
    public function compare(array $employee, string $frequency, string $date): array
    {
        if (! isset(self::TABLES[$frequency]) || $date < '2023-01-01') {
            $this->fail('Choose Monthly, Semi-monthly or Weekly and a pay date from January 2023.');
        }
        if (! in_array($employee['payType'] ?? null, ['Taxable', 'Non-taxable', 'Minimum Wage Earner'], true)) {
            $this->fail('Choose a valid employee pay type.');
        }
        $values = [];
        foreach (['basic', 'taxableAllowance', 'deMinimis', 'benefits', 'sssEe', 'phicEe', 'hdmfEe', 'withholding', 'sssEr', 'phicEr', 'hdmfEr'] as $field) {
            $values[$field] = $this->cents($employee[$field] ?? null);
        }
        $benefits = $values['benefits'] > 0 ? $this->cents($employee['taxableBenefits'] ?? null) : 0;
        if ($benefits > $values['benefits']) {
            $this->fail('Taxable benefits cannot exceed total benefits.');
        }
        $basis = $employee['reviewBasis'] ?? 'regular';
        if (! in_array($basis, ['regular', 'year_end', 'final_pay', 'other'], true)) {
            $this->fail('Choose a valid payroll review basis.');
        }
        $taxable = $employee['payType'] === 'Non-taxable' ? 0 : max(0, ($employee['payType'] === 'Minimum Wage Earner' ? 0 : $values['basic']) + $values['taxableAllowance'] + $benefits - $values['sssEe'] - $values['phicEe'] - $values['hdmfEe']);
        $reference = 0;
        foreach (array_reverse(self::TABLES[$frequency]) as [$floor, $fixed, $rate]) {
            if ($taxable > $floor) {
                $reference = $fixed + intdiv(($taxable - $floor) * $rate + 50, 100);
                break;
            }
        }
        $difference = $values['withholding'] - $reference;

        return ['reference' => $reference, 'actual' => $values['withholding'], 'difference' => $difference, 'needs_review' => $difference !== 0 || substr($date, 5, 2) === '12' || $basis !== 'regular' || $employee['payType'] !== 'Taxable'];
    }

    /** @param array<string, mixed> $run */
    public function validate(array $run): void
    {
        if (($run['importReviewVersion'] ?? null) !== 1 || ($run['automation'] ?? true) !== false) {
            $this->fail('Client payroll imports must preserve actual deductions in manual mode.');
        }
        foreach ($run['employees'] as $employee) {
            $result = $this->compare($employee, $run['frequency'] ?? '', $run['date']);
            $review = $employee['withholdingReview'] ?? [];
            if (($review['reference'] ?? null) !== $result['reference'] || ($review['difference'] ?? null) !== $result['difference'] || ($review['version'] ?? null) !== 'BIR-2023-periodic-v1') {
                $this->fail('The payroll comparison is missing or out of date. Review the imported rows again.');
            }
            if ($result['needs_review'] && (($review['acknowledged'] ?? null) !== true || ! is_string($review['note'] ?? null) || mb_strlen(trim($review['note'])) < 3 || mb_strlen($review['note']) > 1000)) {
                $this->fail('Review each withholding difference or special payroll basis and record an explanation before posting.');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payroll_import' => $message]);
    }
}
