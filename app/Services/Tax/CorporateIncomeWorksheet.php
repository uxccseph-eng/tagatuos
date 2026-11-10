<?php

namespace App\Services\Tax;

use Illuminate\Validation\ValidationException;

class CorporateIncomeWorksheet
{
    /** @return array<string, array{label: string, group: string, signed: bool, xml?: string}> */
    public function inputs(string $form): array
    {
        return json_decode(file_get_contents(resource_path('templates/bir-corporate-inputs.json')), true, 512, JSON_THROW_ON_ERROR)[$form] ?? [];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function calculate(string $form, array $input, int $quarter, string $method): array
    {
        $definitions = $this->inputs($form);
        if ($definitions === [] || ! in_array($method, ['itemized', 'osd'], true) || ($input['method'] ?? '') !== $method) {
            $this->fail('Use the deduction method saved for this company and taxable year.');
        }
        if (array_diff(array_keys($input), [...array_keys($definitions), 'method', 'rate', 'mcit', 'small_eligible', 'incorporation_date']) !== []) {
            $this->fail('The worksheet contains an unsupported field. Refresh the page and review the worksheet.');
        }
        $rate = $input['rate'] ?? '';
        if (! in_array($rate, ['20', '25'], true) || ! in_array($input['mcit'] ?? '', ['applicable', 'not_applicable'], true)) {
            $this->fail('Select the reviewed regular income tax rate and MCIT applicability.');
        }
        if ($rate === '20' && ($input['small_eligible'] ?? false) !== true) {
            $this->fail('Confirm domestic small-corporation eligibility for the 20% rate, including the asset limit.');
        }
        $values = [];
        foreach ($definitions as $key => $definition) {
            $raw = $input[$key] ?? null;
            if (($definition['type'] ?? '') === 'text') {
                $raw ??= '';
                if (! is_string($raw) || mb_strlen($raw) > 150 || preg_match('/[<>=\x00-\x1F]/', $raw)) {
                    $this->fail('Use a plain-text description of at most 150 characters.');
                }
                $values[$key] = trim($raw);

                continue;
            }
            $pattern = $definition['signed'] ? '/^-?[0-9]{1,9}(?:\.00)?$/D' : '/^[0-9]{1,9}(?:\.00)?$/D';
            if (! is_string($raw) || ! preg_match($pattern, $raw)) {
                $this->fail($definition['label'].': enter reviewed whole pesos, including zero; round centavos before entry.');
            }
            $values[$key] = (int) $raw;
        }
        foreach ($definitions as $key => $definition) {
            if (str_starts_with($key, 'description_') && $values[substr($key, 12)] !== 0 && $values[$key] === '') {
                $this->fail('Describe each nonzero other deduction and reconciliation adjustment.');
            }
        }
        $gross = $values['sales'] - ($values['returns'] ?? 0) - $values['cost'] + $values['other_income'];
        $ordinary = array_sum(array_filter($values, fn (string $key): bool => str_starts_with($key, 'expense_'), ARRAY_FILTER_USE_KEY));
        $enteredDeductions = $form === '1702Q' ? $values['deductions'] : $ordinary;
        if ($method === 'osd' && $enteredDeductions !== 0) {
            $this->fail('Remove itemized expenses when using OSD. The worksheet calculates the 40% OSD.');
        }
        $deductions = $method === 'osd' ? intdiv(max(0, $gross) * 40 + 50, 100) : $enteredDeductions;
        $taxable = $gross - $deductions + ($values['previous_taxable'] ?? 0);
        if ($rate === '20' && $taxable > 5000000) {
            $this->fail('The reviewed taxable income exceeds the supported small-corporation 20% threshold. Review the rate.');
        }
        $mcitBase = $gross;
        if ($form === '1702Q') {
            if ($quarter < 1 || $quarter > 3) {
                $this->fail('1702Q XML is available for the first three taxable quarters only.');
            }
            if (($quarter === 1 && ($values['previous_taxable'] !== 0 || $values['gross_q1'] !== 0 || $values['credit_regular_previous'] !== 0 || $values['credit_mcit_previous'] !== 0 || $values['credit_cwt_previous'] !== 0)) || ($quarter < 3 && $values['gross_q2'] !== 0)) {
                $this->fail('Previous-quarter income and payments must match the selected taxable quarter.');
            }
            $mcitBase += $values['gross_q1'] + $values['gross_q2'];
        } else {
            $reconciled = $values['book_net'] + $values['addback1'] + $values['addback2'] - $values['recon_less1'] - $values['recon_less2'] - $values['recon_less3'] - $values['recon_less4'];
            if ($reconciled !== $taxable) {
                $this->fail('Schedule V book-to-tax reconciliation must equal the computed net taxable income / loss.');
            }
        }
        $rcit = intdiv(max(0, $taxable) * (int) $rate + 50, 100);
        $mcit = $input['mcit'] === 'applicable' ? intdiv(max(0, $mcitBase) * 2 + 50, 100) : 0;
        $tax = max($rcit, $mcit);
        $credits = array_sum(array_filter($values, fn (string $key): bool => str_starts_with($key, 'credit_'), ARRAY_FILTER_USE_KEY));
        foreach ([$tax, $credits, $gross, $taxable] as $amount) {
            if (abs($amount) > 999999999) {
                $this->fail('The worksheet total exceeds the supported amount width.');
            }
        }

        return ['input' => $input, 'values' => $values, 'method' => $method, 'rate' => (int) $rate, 'gross' => $gross, 'ordinary' => $ordinary, 'deductions' => $deductions, 'taxable' => $taxable, 'rcit' => $rcit, 'mcit' => $mcit, 'mcit_base' => $mcitBase, 'tax' => $tax * 100, 'credits' => $credits * 100];
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function review(string $form, array $context): array
    {
        $settings = $context['annual_settings']['settings'] ?? [];
        if (($context['options']['corporate_reviewed'] ?? false) !== true || ($settings['regime'] ?? 'regular') !== 'regular') {
            $this->fail('Confirm the regular IC010 worksheet, tax-rate eligibility, MCIT applicability, deductions and credits before exporting.');
        }
        if (($context['taxpayer']['entity_type'] ?? '') !== 'corporation' || (($context['period']['from'] ?? '') < '2024-01-01' || (int) ($context['period']['year'] ?? 0) > 2099)) {
            $this->fail('This IC010 domestic-corporation layout supports complete taxable periods starting in 2024 or later. Other taxpayer ATCs need separate preparation.');
        }
        $input = $context['corporate_input'] ?? [];
        $result = $this->calculate($form, $input, (int) ($context['period']['quarter'] ?? 1), $settings['deduction_method'] ?? 'itemized');
        $startYear = $settings['mcit_start_year'] ?? null;
        if ($startYear !== null && ($input['mcit'] === 'applicable') !== ((int) $context['period']['year'] >= (int) $startYear)) {
            $this->fail('MCIT applicability disagrees with the saved MCIT starting year. Review year settings.');
        }
        $calculation = $context['calculation'] ?? [];
        if (($calculation['tax'] ?? null) !== $result['tax'] || ($calculation['credits'] ?? null) !== $result['credits'] || ($calculation['additional_tax'] ?? 0) !== 0) {
            $this->fail('The reviewed tax and credits must match the corporate worksheet. Calculate and review again; additional tax must be zero.');
        }
        $penalties = 0;
        foreach (['surcharge', 'interest', 'compromise'] as $key) {
            $amount = $calculation[$key] ?? 0;
            if (! is_int($amount) || $amount < 0 || $amount % 100 !== 0) {
                $this->fail('Enter penalties in reviewed whole pesos.');
            }
            $result[$key] = intdiv($amount, 100);
            $penalties += $result[$key];
        }
        $result['penalties'] = $penalties;
        $result['net'] = intdiv($result['tax'] - $result['credits'], 100);
        $result['payable'] = $result['net'] + $penalties;
        if ($form === '1702-RT') {
            $date = $input['incorporation_date'] ?? '';
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (! $parsed || $parsed->format('Y-m-d') !== $date || $date > $context['period']['to']) {
                $this->fail('Enter the certificate date of incorporation, on or before the taxable year end.');
            }
            if ($result['payable'] < 0 && ! in_array($context['options']['overpayment_action'] ?? '', ['refund', 'certificate', 'carry_over'], true)) {
                $this->fail('Choose the annual overpayment treatment.');
            }
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['xml_corporate' => $message]);
    }
}
