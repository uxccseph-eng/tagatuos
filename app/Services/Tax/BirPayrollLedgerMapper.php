<?php

namespace App\Services\Tax;

class BirPayrollLedgerMapper
{
    /** @param iterable<array<string, mixed>> $runs
     * @param  array<string, mixed>  $period
     * @return array{values: array<string, int>, issues: list<string>, warnings: list<string>}
     */
    public function map(iterable $runs, array $period): array
    {
        $values = array_fill_keys(['total_compensation', 'tax_withheld', 'minimum_wage', 'mwe_premium', 'exempt_benefits', 'de_minimis', 'mandatory_contributions', 'other_exempt', 'no_withholding_compensation'], 0);
        $issues = $warnings = $seen = [];
        $count = 0;
        foreach ($runs as $run) {
            if (! is_array($run)) {
                $issues['run_shape'] = 'A saved payroll run has an invalid structure.';

                continue;
            }
            if (($run['status'] ?? '') !== 'posted' || ($run['date'] ?? '') < $period['from'] || ($run['date'] ?? '') > $period['to']) {
                continue;
            }
            $id = (string) ($run['id'] ?? '');
            if ($id === '' || isset($seen[$id]) || ! is_array($run['employees'] ?? null) || $run['employees'] === []) {
                $issues['run_identity'] = 'A posted payroll run has missing or duplicate identifying information or no employees.';

                continue;
            }
            $seen[$id] = true;
            foreach ($run['employees'] as $employee) {
                if (++$count > 50000) {
                    $issues['limit'] = 'Export at most 50,000 employee payroll rows per month.';
                    break 2;
                }
                if (! is_array($employee)) {
                    $issues['employee_shape'] = 'A saved employee payroll row has an invalid structure.';

                    continue;
                }
                $row = [];
                foreach (['basic', 'taxableAllowance', 'deMinimis', 'benefits', 'sssEe', 'phicEe', 'hdmfEe', 'withholding'] as $key) {
                    $row[$key] = $this->money($employee[$key] ?? null);
                    if ($row[$key] === null) {
                        $issues['amount_'.$key] = 'A posted payroll amount is missing or invalid: '.$key.'. Correct Payroll before XML export.';
                        $row[$key] = 0;
                    }
                }
                $gross = $row['basic'] + $row['taxableAllowance'] + $row['deMinimis'] + $row['benefits'];
                $mandatory = $row['sssEe'] + $row['phicEe'] + $row['hdmfEe'];
                $exemptBenefits = 0;
                if ($row['benefits'] > 0) {
                    $taxableBenefits = $this->money($employee['taxableBenefits'] ?? null);
                    if ($taxableBenefits === null || $taxableBenefits > $row['benefits']) {
                        $warnings['benefits'] = 'Some benefits have no usable taxable/non-taxable split. Review Item 17 against the annual employee benefit limits; no exemption was inferred for those benefits.';
                    } else {
                        $exemptBenefits = $row['benefits'] - $taxableBenefits;
                    }
                }
                $values['total_compensation'] += $gross;
                $values['tax_withheld'] += $row['withholding'];
                $values['de_minimis'] += $row['deMinimis'];
                $values['mandatory_contributions'] += $mandatory;
                $values['exempt_benefits'] += $exemptBenefits;
                if (($employee['payType'] ?? '') === 'Minimum Wage Earner') {
                    $values['minimum_wage'] += $row['basic'];
                    $warnings['mwe'] = 'Review MWE eligibility and Item 16 premiums separately; Payroll has no separate holiday/overtime/night/hazard-pay bucket.';
                } elseif (($employee['payType'] ?? '') === 'Non-taxable') {
                    $values['other_exempt'] += max(0, $gross - $row['deMinimis'] - $mandatory - $exemptBenefits);
                    $warnings['non_taxable'] = 'Review the basis for Non-taxable payroll rows and describe Item 20.';
                } elseif (($employee['payType'] ?? '') !== 'Taxable') {
                    $warnings['pay_type'] = 'Some payroll rows lack a pay classification. Review the compensation buckets explicitly.';
                }
            }
        }
        if (max($values) > 9999999999999) {
            $issues['amount_limit'] = 'The monthly payroll totals exceed the supported XML amount width.';
        }
        $warnings['annual_threshold'] = 'Item 23 defaults to zero. Review employees’ annual taxable compensation; zero withholding alone does not establish the annual threshold exemption.';

        return ['values' => $values, 'issues' => array_values($issues), 'warnings' => array_values($warnings)];
    }

    private function money(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
