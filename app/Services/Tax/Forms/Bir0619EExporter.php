<?php

namespace App\Services\Tax\Forms;

use App\Services\Tax\BaseBirExporter;
use Carbon\CarbonImmutable;

class Bir0619EExporter extends BaseBirExporter
{
    public function form(): string
    {
        return '0619-E';
    }

    protected function schema(): string
    {
        return '0619e';
    }

    protected function filename(array $context, array $profile, array $period): string
    {
        return $profile['tin'].$profile['branch_code'].'-0619E-'.sprintf('%02d%04d', $period['month'], $period['year']).'.xml';
    }

    protected function fields(array $context, array $profile, array $period): array
    {
        $prefix = 'frm0619E:';
        $fields = $this->monthlyProfile($prefix, $profile);
        $business = $this->text($profile['line_of_business'] ?? '');
        if ($business === '' || mb_strlen($business) > 150) {
            $this->fail('taxpayer.line_of_business', 'Enter the registered line of business, up to 150 characters.');
        }
        $total = 0;
        if (! isset($context['withholding']) || ! is_array($context['withholding']) || count($context['withholding']) > 50000) {
            $this->fail('withholding', 'Supply the reviewed monthly withholding rows, including an explicit empty list for a nil month.');
        }
        foreach ($context['withholding'] as $row) {
            if (! is_array($row) || ! preg_match('/^W[IC][0-9]{3}$/D', (string) ($row['atc'] ?? '')) || ! is_int($row['rate'] ?? null) || $row['rate'] <= 0 || $row['rate'] > 1000000) {
                $this->fail('withholding', 'A monthly source has an invalid expanded-withholding ATC or rate.');
            }
            $base = $this->cents($row, 'base');
            $tax = $this->cents($row, 'withheld');
            if ($base <= 0 || abs($this->percentage($base, $row['rate']) - $tax) > 1) {
                $this->fail('withholding', 'Monthly withholding does not reconcile to the saved tax base and rate.');
            }
            $total += $tax;
        }
        $calculation = $context['calculation'] ?? [];
        $this->assertOriginalMonthlyCalculation($calculation, $total);
        $surcharge = $this->cents($calculation, 'surcharge');
        $interest = $this->cents($calculation, 'interest');
        $compromise = $this->cents($calculation, 'compromise');
        $due = CarbonImmutable::parse($period['to'])->addDay()->day(10);
        // This offline layout stores the line-of-business control with two encoding passes.
        $fields[$prefix.'txtLineBus'] = rawurlencode(rawurlencode($business));
        $fields[$prefix.'txtMonth'] = sprintf('%02d', $period['month']);
        $fields[$prefix.'txtYear'] = (string) $period['year'];
        $fields += [$prefix.'txtDueMonth' => $due->format('m'), $prefix.'txtDueDay' => $due->format('d'), $prefix.'txtDueYear' => $due->format('Y'), $prefix.'optAmend:Y' => 'false', $prefix.'optAmend:N' => 'true', $prefix.'optWithheld:Y' => $total > 0 ? 'true' : 'false', $prefix.'optWithheld:N' => $total > 0 ? 'false' : 'true', $prefix.'optCategory:P' => $profile['agent_category'] === 'private' ? 'true' : 'false', $prefix.'optCategory:G' => $profile['agent_category'] === 'government' ? 'true' : 'false'];
        foreach (['14' => $total, '15' => 0, '16' => $total, '17A' => $surcharge, '17B' => $interest, '17C' => $compromise, '17D' => $surcharge + $interest + $compromise, '18' => $total + $surcharge + $interest + $compromise] as $number => $value) {
            $fields[$prefix.'txtTax'.$number] = $this->amount($value);
        }

        return $fields;
    }
}
