<?php

namespace App\Services\Tax;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class TaxReturnCatalog
{
    /** @return array<string, array{label: string, frequency: string, category: string, source: string, description: string}> */
    public static function forms(): array
    {
        return [
            '1601-C' => ['label' => 'Monthly Compensation Withholding', 'frequency' => 'monthly', 'category' => 'Withholding', 'source' => 'payroll', 'description' => 'Posted payroll compensation and actual tax withheld.'],
            '0619-E' => ['label' => 'Monthly Expanded Remittance', 'frequency' => 'monthly', 'category' => 'Withholding', 'source' => 'expanded', 'description' => 'Expanded withholding from posted issued 2307 certificates. Months 1 and 2 of each calendar quarter.'],
            '0619-F' => ['label' => 'Monthly Final Remittance', 'frequency' => 'monthly', 'category' => 'Withholding', 'source' => 'manual', 'description' => 'Enter a separately reviewed final withholding computation; final withholding sources are not classified in Sheets.'],
            '1601-EQ' => ['label' => 'Quarterly Expanded Withholding', 'frequency' => 'quarterly', 'category' => 'Withholding', 'source' => 'expanded', 'description' => 'Quarterly issued 2307 totals. Enter actual prior remittances as credits.'],
            '1601-FQ' => ['label' => 'Quarterly Final Withholding', 'frequency' => 'quarterly', 'category' => 'Withholding', 'source' => 'manual', 'description' => 'Requires a separately reviewed final withholding computation.'],
            '2550Q' => ['label' => 'Quarterly VAT Return', 'frequency' => 'quarterly', 'category' => 'Business tax', 'source' => 'manual', 'description' => 'Review VAT eligibility, carryovers, adjustments and credits before entering the reviewed tax due. Linked RELIEF schedules provide source details.'],
            '2551Q' => ['label' => 'Quarterly Percentage Tax', 'frequency' => 'quarterly', 'category' => 'Business tax', 'source' => 'manual', 'description' => 'Confirm registered tax type, applicable rate and tax base; enter the reviewed computation.'],
            '1701Q' => ['label' => 'Quarterly Individual Income Tax', 'frequency' => 'quarterly', 'category' => 'Income tax', 'source' => 'manual', 'description' => 'Confirm graduated / 8% election, cumulative income, deductions and credits. First three quarters only.'],
            '1702Q' => ['label' => 'Quarterly Corporate Income Tax', 'frequency' => 'quarterly', 'category' => 'Income tax', 'source' => 'manual', 'description' => 'Confirm RCIT / MCIT, cumulative taxable income and tax credits. First three taxable quarters only.'],
            '1604-C' => ['label' => 'Annual Compensation Information Return', 'frequency' => 'annual', 'category' => 'Information return', 'source' => 'payroll', 'description' => 'Annual posted payroll summary for reconciliation; employee 2316 and alphalist review remain separate.'],
            '1604-E' => ['label' => 'Annual Expanded Withholding Information Return', 'frequency' => 'annual', 'category' => 'Information return', 'source' => 'expanded', 'description' => 'Annual issued 2307 totals. This information return does not create another tax payable.'],
            '1701' => ['label' => 'Annual Individual Income Tax', 'frequency' => 'annual', 'category' => 'Income tax', 'source' => 'manual', 'description' => 'Review the applicable individual annual form, deductions and tax credits before preparation.'],
            '1702-RT' => ['label' => 'Annual Corporate Income Tax', 'frequency' => 'annual', 'category' => 'Income tax', 'source' => 'manual', 'description' => 'Confirm that regular-rate Form 1702-RT applies; exempt / mixed-rate returns need their own forms.'],
        ];
    }

    /** @param array<string, mixed> $profile
     * @return array{from: string, to: string, label: string, due: ?string, applicable: bool, reason: string}
     */
    public function period(string $form, int $year, int $period, array $profile): array
    {
        $definition = self::forms()[$form] ?? throw ValidationException::withMessages(['form' => 'Choose a supported tax return.']);
        $frequency = $definition['frequency'];
        $endMonth = ($profile['accounting_period'] ?? 'calendar') === 'fiscal' ? (int) ($profile['fiscal_end_month'] ?? 12) : 12;
        $taxable = in_array($form, ['1702Q', '1702-RT', '2550Q'], true);
        $yearEnd = CarbonImmutable::create($year, $taxable ? $endMonth : 12, 1, 0, 0, 0, 'Asia/Manila')->endOfMonth();
        $start = $yearEnd->subMonthsNoOverflow(11)->startOfMonth();
        if ($frequency === 'monthly') {
            if ($period < 1 || $period > 12) {
                throw ValidationException::withMessages(['period' => 'Choose a month from 1 to 12.']);
            }
            $start = CarbonImmutable::create($year, $period, 1, 0, 0, 0, 'Asia/Manila');
            $end = $start->endOfMonth();
            $label = $start->format('F Y');
        } elseif ($frequency === 'quarterly') {
            if ($period < 1 || $period > 4) {
                throw ValidationException::withMessages(['period' => 'Choose a quarter from 1 to 4.']);
            }
            $start = $start->addMonthsNoOverflow(($period - 1) * 3);
            $end = $start->addMonthsNoOverflow(2)->endOfMonth();
            $label = 'Q'.$period.' · '.$start->format('M Y').' – '.$end->format('M Y');
        } else {
            if ($period !== 1) {
                throw ValidationException::withMessages(['period' => 'Annual returns use period 1.']);
            }
            $end = $yearEnd;
            $label = $start->format('M Y').' – '.$end->format('M Y');
        }
        $reason = '';
        if (in_array($form, ['0619-E', '0619-F'], true) && $period % 3 === 0) {
            $reason = 'Use the quarterly withholding return for the third month of the quarter.';
        }
        if (in_array($form, ['1701Q', '1702Q'], true) && $period === 4) {
            $reason = 'The fourth quarter is covered by the annual income tax return.';
        }
        $individual = ($profile['entity_type'] ?? 'corporation') === 'individual';
        if ((in_array($form, ['1701', '1701Q'], true) && ! $individual) || (in_array($form, ['1702Q', '1702-RT'], true) && $individual)) {
            $reason = 'This form does not match the taxpayer type in company settings.';
        }
        if (($form === '2550Q' && ($profile['vat_type'] ?? '') !== 'vat') || ($form === '2551Q' && ($profile['vat_type'] ?? '') !== 'non_vat')) {
            $reason = 'Confirm the registered business tax type in company settings.';
        }
        $due = match ($form) {
            '1601-C' => $end->addMonthNoOverflow()->day($period === 12 ? 15 : 10),
            '0619-E', '0619-F' => $end->addMonthNoOverflow()->day(10),
            '1601-EQ', '1601-FQ' => $end->addMonthNoOverflow()->endOfMonth(),
            '2550Q', '2551Q' => $end->addMonthNoOverflow()->day(25),
            '1702Q' => $end->addDays(60),
            '1701Q' => CarbonImmutable::create($year, min(11, $period * 3 + 2), 15, 0, 0, 0, 'Asia/Manila'),
            '1604-C' => $end->addYear()->startOfYear()->endOfMonth(),
            '1604-E' => $end->addYear()->startOfYear()->month(3)->day(1),
            default => null,
        };

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => $label, 'due' => $due?->toDateString(), 'applicable' => $reason === '', 'reason' => $reason];
    }
}
