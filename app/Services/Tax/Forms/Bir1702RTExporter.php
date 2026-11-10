<?php

namespace App\Services\Tax\Forms;

use App\Services\Tax\BaseBirExporter;
use App\Services\Tax\CorporateIncomeWorksheet;

class Bir1702RTExporter extends BaseBirExporter
{
    public function form(): string
    {
        return '1702-RT';
    }

    protected function schema(): string
    {
        return '1702rt-v2018';
    }

    protected function filename(array $context, array $profile, array $period): string
    {
        return $profile['tin'].$profile['branch_code'].'-1702RTv2018C-'.sprintf('%02d%04d', $period['year_end_month'], $period['year']).'.xml';
    }

    protected function fields(array $context, array $profile, array $period): array
    {
        $worksheet = app(CorporateIncomeWorksheet::class);
        $r = $worksheet->review($this->form(), [...$context, 'period' => $period]);
        $v = $r['values'];
        $p = 'frm1702RT:';
        $fields = [$p.'rdoPg1I1Calendar' => $period['fiscal'] ? 'false' : 'true', $p.'rdoPg1I1Fiscal' => $period['fiscal'] ? 'true' : 'false', $p.'ddlPg1I2Month' => (string) $period['year_end_month'], $p.'txtPg1I2Year' => substr((string) $period['year'], -2), $p.'rdoPg1I3AmmendNo' => 'true', $p.'rdoPg1I4ShortPeriodNo' => 'true', $p.'rdoPg1I5Atc' => $r['mcit'] > $r['rcit'] ? 'true' : 'false', $p.'rdoPg1I5AtcOther' => $r['mcit'] > $r['rcit'] ? 'false' : 'true', $p.'drpPg1I5AtcOther' => 'IC010', $p.'txtRDO' => strtoupper($profile['rdo']), $p.'drpPg1Pt1I7RDOCode' => strtoupper($profile['rdo']), $p.'txtZIP' => $profile['zip_code'], $p.'txtPg1Pt1I8Name1' => $this->text($profile['name'], true), $p.'txtPg1Pt1I9Address1' => $this->text($profile['address'], true), $p.'txtPg1Pt1I11Contact' => $this->text($profile['contact_number']), $p.'txtPg1Pt1I12Email' => $this->text($profile['contact_email']), $p.'txtPg1Pt1I10' => (new \DateTimeImmutable($context['corporate_input']['incorporation_date']))->format('m/d/Y'), $p.'rdoPg1Pt1I13ItemizedDeduction' => $r['method'] === 'itemized' ? 'true' : 'false', $p.'rdoPg1Pt1I13OptionalStandard' => $r['method'] === 'osd' ? 'true' : 'false'];
        foreach (range(1, 4) as $page) {
            $prefix = $page === 1 ? 'txtPg1Pt1I6TIN' : 'txtPg'.$page.'TIN';
            foreach (range(1, 3) as $part) {
                $fields[$p.$prefix.$part] = substr($profile['tin'], ($part - 1) * 3, 3);
            }
            $fields[$p.$prefix.'4'] = $profile['branch_code'];
            $fields[$page === 1 ? 'BranchMaskP1' : 'txtBranchMaskP'.$page] = $profile['branch_code'];
            if ($page > 1) {
                $fields[$p.'txtPg'.$page.'RegisteredName'] = $this->text($profile['name']);
            }
        }
        $put = function (string $key, int $amount) use (&$fields, $p): void {
            $fields[$p.$key] = number_format($amount, 0, '.', ',');
        };
        $tax = intdiv($r['tax'], 100);
        $credits = intdiv($r['credits'], 100);
        foreach (['I14IncomeTax' => $tax, 'I15TotalTaxCredits' => $credits, 'I16NetTax' => $r['net'], 'I17Surcharge' => $r['surcharge'], 'I18Interest' => $r['interest'], 'I19Compromise' => $r['compromise'], 'I20TotalPenalties' => $r['penalties'], 'I21TotalAmount' => $r['payable']] as $key => $amount) {
            $put('txtPg1Pt2'.$key, $amount);
        }
        foreach (['I27Sales' => $v['sales'], 'I28LessSales' => $v['returns'], 'I29NetSales' => $v['sales'] - $v['returns'], 'I30LessCost' => $v['cost'], 'I31GrossIncome' => $v['sales'] - $v['returns'] - $v['cost'], 'I32AddOtherTaxable' => $v['other_income'], 'I33TotalGross' => $r['gross'], 'I34OrdinaryAllowable' => $r['method'] === 'itemized' ? $r['ordinary'] : 0, 'I35SpecialAllowable' => 0, 'I36Nolco' => 0, 'I37TotalItemized' => $r['method'] === 'itemized' ? $r['ordinary'] : 0, 'I38OptionalStandard' => $r['method'] === 'osd' ? $r['deductions'] : 0, 'I39NetTaxable' => $r['taxable'], 'I41IncomeTaxDue' => $r['rcit'], 'I42MinimumCorporate' => $r['mcit'], 'I43TotalIncomeTax' => $tax, 'I44ExcessCredits' => $v['credit_prior_year'], 'I45IncomeTaxPaymentUnderMCIT' => $v['credit_mcit_previous'], 'I46IncomeTaxUnderRegular' => $v['credit_regular_previous'], 'I47ExcessMCIT' => 0, 'I48CreditableTaxWithheldFromPrevious' => $v['credit_cwt_previous'], 'I49CreditableTaxWithheldFor4thQuarter' => $v['credit_cwt_current'], 'I50ForeignTaxCredits' => 0, 'I51TaxPaidInReturn' => 0, 'I55TotalTaxCredits' => $credits, 'I56NetTax' => $r['net']] as $key => $amount) {
            $put('txtPg2Pt4'.$key, $amount);
        }
        $fields[$p.'Pg2Pt4I40IncomeTaxRate'] = (string) $r['rate'];
        foreach ($worksheet->inputs($this->form()) as $key => $definition) {
            if (isset($definition['xml'])) {
                $fields[$definition['xml']] = number_format($v[$key], 0, '.', ',');
                if (str_starts_with($key, 'expense_other')) {
                    $fields[str_replace('C2', 'C1', $definition['xml'])] = $this->text($v['description_'.$key], true);
                }
            }
        }
        $put('txtPg3Sc1I17iSubtotal', $v['expense_other6']);
        $put('txtPg3Sc1I18TotalOrdinaryAllowable', $r['ordinary']);
        $put('txtPg4Sc3I1GrossIncome', $r['gross']);
        $put('txtPg4Sc3I2TotalDeductions', $r['ordinary']);
        $put('txtPg4Sc3I3NetOperatingLoss', $r['gross'] - $r['ordinary']);
        $loss = $r['method'] === 'itemized' ? max(0, $r['ordinary'] - $r['gross']) : 0;
        if ($loss > 0) {
            $fields[$p.'txtPg4Sc3AI7C1'] = (string) $period['year'];
            foreach (['txtPg4Sc3AI7C2', 'txtPg4Sc3AI7C6', 'txtPg4Sc3AI7C2Subtotal', 'txtPg4Sc3AI7C6Subtotal'] as $key) {
                $put($key, $loss);
            }
        }
        $put('txtPg4Sc5I1NetIncome', $v['book_net']);
        foreach (['addback1' => '2', 'addback2' => '3', 'recon_less1' => '5', 'recon_less2' => '6', 'recon_less3' => '7', 'recon_less4' => '8'] as $key => $item) {
            $fields[$p.'txtPg4Sc5I'.$item.'C1'] = $this->text($v['description_'.$key], true);
            $put('txtPg4Sc5I'.$item.'C2', $v[$key]);
        }
        $put('txtPg4Sc5I4Total', $v['book_net'] + $v['addback1'] + $v['addback2']);
        $put('txtPg4Sc5I9Total', $v['recon_less1'] + $v['recon_less2'] + $v['recon_less3'] + $v['recon_less4']);
        $put('txtPg4Sc5I10NetTaxableIncome', $r['taxable']);
        foreach (['3', '6', '8'] as $item) {
            $key = 'txtPg4Sc5I'.$item.'C2';
            $put('txtPg4Sc5I'.$item.'Subtotal', (int) str_replace(',', '', $fields[$p.$key]));
        }
        if ($r['payable'] < 0) {
            $choice = ['refund' => 'Refunded', 'certificate' => 'Issued', 'carry_over' => 'Carried'][$context['options']['overpayment_action']];
            $fields[$p.'rdoPg1Pt2I21Overpayment'.$choice] = 'true';
        }

        return $fields;
    }
}
