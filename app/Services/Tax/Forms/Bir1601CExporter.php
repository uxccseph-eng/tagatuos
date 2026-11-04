<?php

namespace App\Services\Tax\Forms;

use App\Services\Tax\BaseBirExporter;

class Bir1601CExporter extends BaseBirExporter
{
    public function form(): string
    {
        return '1601-C';
    }

    protected function schema(): string
    {
        return '1601c-v2018';
    }

    protected function filename(array $context, array $profile, array $period): string
    {
        return $profile['tin'].$profile['branch_code'].'-1601Cv2018-'.sprintf('%02d%04d', $period['month'], $period['year']).'.xml';
    }

    protected function fields(array $context, array $profile, array $period): array
    {
        if (($context['options']['payroll_reviewed'] ?? false) !== true) {
            $this->fail('payroll_reviewed', 'Review compensation classifications, benefit eligibility and annual employee thresholds before exporting.');
        }
        $values = $context['payroll'] ?? [];
        foreach (['total_compensation', 'tax_withheld', 'minimum_wage', 'mwe_premium', 'exempt_benefits', 'de_minimis', 'mandatory_contributions', 'other_exempt', 'no_withholding_compensation'] as $key) {
            if (! array_key_exists($key, $values)) {
                $this->fail('payroll.'.$key, 'Confirm each compensation bucket explicitly, including zero.');
            }
            $this->cents($values, $key);
        }
        $gross = $values['total_compensation'];
        $withheld = $values['tax_withheld'];
        $exempt = array_sum(array_intersect_key($values, array_flip(['minimum_wage', 'mwe_premium', 'exempt_benefits', 'de_minimis', 'mandatory_contributions', 'other_exempt'])));
        if ($exempt > $gross || $values['no_withholding_compensation'] > $gross - $exempt) {
            $this->fail('payroll', 'Exempt compensation and compensation not subject to withholding cannot exceed the posted compensation.');
        }
        if ($withheld > $gross - $exempt - $values['no_withholding_compensation']) {
            $this->fail('payroll', 'Withheld tax must reconcile to the taxable compensation; review prior-month adjustments separately.');
        }
        $other = $this->text($context['options']['payroll_other_description'] ?? '');
        if (($values['other_exempt'] > 0 && $other === '') || mb_strlen($other) > 150) {
            $this->fail('payroll_other_description', 'Describe other non-taxable compensation, up to 150 characters.');
        }
        $calculation = $context['calculation'] ?? [];
        $this->assertOriginalMonthlyCalculation($calculation, $withheld);
        $surcharge = $this->cents($calculation, 'surcharge');
        $interest = $this->cents($calculation, 'interest');
        $compromise = $this->cents($calculation, 'compromise');
        $prefix = 'frm1601c:';
        $fields = $this->monthlyProfile($prefix, $profile);
        $fields[$prefix.'txtMonth'] = sprintf('%02d', $period['month']);
        $fields[$prefix.'txtYear'] = (string) $period['year'];
        $fields += [$prefix.'AmendedRtn_1' => 'false', $prefix.'AmendedRtn_2' => 'true', $prefix.'TaxWithheld_1' => $withheld > 0 ? 'true' : 'false', $prefix.'TaxWithheld_2' => $withheld > 0 ? 'false' : 'true', $prefix.'CatAgent_P' => $profile['agent_category'] === 'private' ? 'true' : 'false', $prefix.'CatAgent_G' => $profile['agent_category'] === 'government' ? 'true' : 'false', $prefix.'SpecialTax_1' => 'false', $prefix.'SpecialTax_2' => 'true', $prefix.'txt20Other' => $other, $prefix.'txtPg2BranchCode' => $profile['branch_code'], $prefix.'txtPg2TaxpayerName' => $this->text($profile['name']), $prefix.'txtLineBus' => $this->text($profile['line_of_business'] ?? '', true)];
        foreach (range(1, 3) as $part) {
            $fields[$prefix.'txtPg2TIN'.$part] = substr($profile['tin'], ($part - 1) * 3, 3);
        }
        foreach ([14 => $gross, 15 => $values['minimum_wage'], 16 => $values['mwe_premium'], 17 => $values['exempt_benefits'], 18 => $values['de_minimis'], 19 => $values['mandatory_contributions'], 20 => $values['other_exempt'], 21 => $exempt, 22 => $gross - $exempt, 23 => $values['no_withholding_compensation'], 24 => $gross - $exempt - $values['no_withholding_compensation'], 25 => $withheld, 26 => 0, 27 => $withheld, 28 => 0, 29 => 0, 30 => 0, 31 => $withheld, 32 => $surcharge, 33 => $interest, 34 => $compromise, 35 => $surcharge + $interest + $compromise, 36 => $withheld + $surcharge + $interest + $compromise] as $number => $value) {
            $fields[$prefix.'txtTax'.$number] = $this->amount($value);
        }

        return $fields;
    }
}
