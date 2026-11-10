<?php

namespace App\Services\Tax\Forms;

use App\Services\Tax\BaseBirExporter;
use App\Services\Tax\CorporateIncomeWorksheet;

class Bir1702QExporter extends BaseBirExporter
{
    public function form(): string
    {
        return '1702Q';
    }

    protected function schema(): string
    {
        return '1702q-v2018';
    }

    protected function filename(array $context, array $profile, array $period): string
    {
        return $profile['tin'].$profile['branch_code'].'-1702Qv2018C-'.$period['year'].'Q'.$period['quarter'].'.xml';
    }

    protected function fields(array $context, array $profile, array $period): array
    {
        $r = app(CorporateIncomeWorksheet::class)->review($this->form(), [...$context, 'period' => $period]);
        $v = $r['values'];
        $p = 'frm1702q:';
        $fields = [$p.'rbForClndrFscl_1' => $period['fiscal'] ? 'false' : 'true', $p.'rbForClndrFscl_2' => $period['fiscal'] ? 'true' : 'false', $p.'rbYrEndMonth' => (string) $period['year_end_month'], $p.'txtYrEndYear' => substr((string) $period['year'], -2), $p.'rbAmendedRtn_1' => 'false', $p.'rbAmendedRtn_2' => 'true', $p.'txtATC_1' => 'IC055-Minimum Corporate Income Tax (MCIT)', $p.'rbATC_1' => $r['mcit'] > $r['rcit'] ? 'true' : 'false', $p.'cbATC_2' => 'IC010_'.$r['rate'].'%', $p.'rbATC_2' => $r['mcit'] > $r['rcit'] ? 'false' : 'true', $p.'txtTaxpayerName1' => $this->text($profile['name'], true), $p.'txtAddress' => $this->text($profile['address'], true), $p.'txtBranchCode' => $profile['branch_code'], $p.'txtRDOCode' => strtoupper($profile['rdo']), $p.'txtZipCode' => $profile['zip_code'], $p.'txtTelNum' => $this->text($profile['contact_number']), 'txtEmail' => $this->text($profile['contact_email']), $p.'txtPg2TaxpayerName' => $profile['name'], $p.'txtPg2BranchCode' => $profile['branch_code'], $p.'rbMthdOfDdctns_1' => $r['method'] === 'itemized' ? 'true' : 'false', $p.'rbMthdOfDdctns_2' => $r['method'] === 'osd' ? 'true' : 'false', $p.'rbTxRlf_1' => 'false', $p.'rbTxRlf_2' => 'true', $p.'txtLOB' => $this->text($profile['line_of_business'] ?? '', true)];
        foreach (range(1, 3) as $part) {
            $fields[$p.'txtTIN'.$part] = substr($profile['tin'], ($part - 1) * 3, 3);
            $fields[$p.'txtPg2TIN'.$part] = $fields[$p.'txtTIN'.$part];
            $fields[$p.'rbQuarter_'.$part] = (int) $period['quarter'] === $part ? 'true' : 'false';
        }
        $tax = intdiv($r['tax'], 100);
        foreach ([14 => $tax, 15 => 0, 16 => $tax, 17 => 0, 18 => $tax, 19 => intdiv($r['credits'], 100), 20 => $r['net'], 21 => $r['surcharge'], 22 => $r['interest'], 23 => $r['compromise'], 24 => $r['penalties'], 25 => $r['payable']] as $item => $amount) {
            $fields[$p.'txtTax'.$item] = $this->amount($amount * 100);
        }
        foreach ([1 => $v['sales'], 2 => $v['cost'], 3 => $v['sales'] - $v['cost'], 4 => $v['other_income'], 5 => $r['gross'], 6 => $r['deductions'], 7 => $r['gross'] - $r['deductions'], 8 => $v['previous_taxable'], 9 => $r['taxable'], 10 => $r['rate'], 11 => $r['rcit'], 12 => $r['mcit'], 13 => $tax] as $item => $amount) {
            $fields[$p.'Sched2:txtTax'.$item] = $this->amount($amount * 100);
        }
        foreach ([1 => (int) $period['quarter'] === 1 ? $r['gross'] : $v['gross_q1'], 2 => (int) $period['quarter'] === 2 ? $r['gross'] : $v['gross_q2'], 3 => (int) $period['quarter'] === 3 ? $r['gross'] : 0, 4 => $r['mcit_base'], 6 => $r['mcit']] as $item => $amount) {
            $fields[$p.'Sched3:txtTax'.$item] = $this->amount($amount * 100);
        }
        $fields[$p.'Sched3:txtTax5'] = $context['corporate_input']['mcit'] === 'applicable' ? '2.00' : '';
        foreach ([1 => $v['credit_prior_year'], 2 => $v['credit_regular_previous'], 3 => $v['credit_mcit_previous'], 4 => $v['credit_cwt_previous'], 5 => $v['credit_cwt_current'], 6 => 0, 7 => intdiv($r['credits'], 100)] as $item => $amount) {
            $fields[$p.'Sched4:txtTax'.$item] = $this->amount($amount * 100);
        }

        return $fields;
    }
}
