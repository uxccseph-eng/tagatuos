<?php

namespace App\Services\Tax\Forms;

use App\Services\Tax\BaseBirExporter;
use Carbon\CarbonImmutable;

class Bir2550QExporter extends BaseBirExporter
{
    public function form(): string
    {
        return '2550Q';
    }

    protected function schema(): string
    {
        return '2550q-v2024';
    }

    protected function filename(array $context, array $profile, array $period): string
    {
        return $profile['tin'].$profile['branch_code'].'-2550Qv2024-'.str_pad((string) $period['year_end_month'], 2, '0', STR_PAD_LEFT).$period['year'].'Q'.$period['quarter'].'.xml';
    }

    protected function fields(array $context, array $profile, array $period): array
    {
        $vat = $context['vat'] ?? [];
        $options = $context['options'] ?? [];
        $calculation = $context['calculation'] ?? [];
        if (($options['vat_reviewed'] ?? false) !== true) {
            $this->fail('review', 'Confirm VAT eligibility, carryovers and the absence of unsupported special adjustments before XML export.');
        }
        if (! in_array($profile['taxpayer_size'] ?? '', ['micro', 'small', 'medium', 'large'], true)) {
            $this->fail('taxpayer.taxpayer_size', 'Select the taxpayer size in BIR settings.');
        }
        if ($this->cents($calculation, 'additional_tax') !== 0) {
            $this->fail('additional_tax', 'Map additional VAT into the source computation before exporting; an unallocated adjustment cannot be exported.');
        }
        $values = [];
        foreach (['vatableSales', 'outputVatSales', 'zeroRatedSales', 'exemptSales', 'domesticPurchase', 'domesticInputTax', 'servicesPurchase', 'serviceInputTax', 'importPurchase', 'importInputTax', 'domesticPurchaseNoTax', 'vatExemptImports'] as $key) {
            $values[$key] = $this->cents($vat, $key);
        }
        $values['inputTaxCarried'] = $this->cents($options, 'carryover_input');
        $values['totalSales'] = $values['vatableSales'] + $values['zeroRatedSales'] + $values['exemptSales'];
        $values['outputTaxDue'] = $values['totalAdjOutput'] = $values['outputVatSales'];
        $values['total43'] = $values['inputTaxCarried'];
        $values['totalCurPurchase'] = $values['domesticPurchase'] + $values['servicesPurchase'] + $values['importPurchase'] + $values['domesticPurchaseNoTax'] + $values['vatExemptImports'];
        $values['totalCurInputTax'] = $values['domesticInputTax'] + $values['serviceInputTax'] + $values['importInputTax'];
        $values['totalAvailInputTax'] = $values['total43'] + $values['totalCurInputTax'];
        $values['otherSpecify56'] = $this->cents($vat, 'non_creditable');
        $values['totalDeductions'] = $values['adjDeductions'] = $values['otherSpecify56'];
        $values['totalAllowInputTax'] = $values['totalAvailInputTax'] - $values['adjDeductions'];
        if ($values['totalAllowInputTax'] < 0) {
            $this->fail('vat', 'Non-creditable VAT exceeds available input VAT.');
        }
        $values['netVatPayable'] = $values['excessInputTax'] = $values['totalAdjOutput'] - $values['totalAllowInputTax'];
        if (isset($calculation['tax']) && $calculation['tax'] !== $values['netVatPayable']) {
            $this->fail('reviewed_tax', 'The reviewed VAT before credits must equal '.$this->amount($values['netVatPayable']).' from the saved source figures and carryover.');
        }
        $values['otherCreditsNo19'] = $values['totalTaxCredits'] = $this->cents($calculation, 'credits');
        $values['excessCredits'] = $values['netVatPayable'] - $values['totalTaxCredits'];
        foreach (['surcharge', 'interest', 'compromise'] as $key) {
            $values[$key] = $this->cents($calculation, $key);
        }
        $values['penalties'] = $values['surcharge'] + $values['interest'] + $values['compromise'];
        $values['totalPayable'] = $values['excessCredits'] + $values['penalties'];
        $prefix = 'frm2550qv2024:';
        $fields = [];
        foreach ($values as $key => $value) {
            $fields[$prefix.$key] = $this->amount($value);
        }
        foreach ([1, 2, 3] as $part) {
            $fields[$prefix.'txtTIN'.$part] = substr($profile['tin'], ($part - 1) * 3, 3);
            $fields[$prefix.'txtPg2TIN'.$part] = substr($profile['tin'], ($part - 1) * 3, 3);
        }
        $fields += [
            $prefix.'calendarNo1' => $period['fiscal'] ? 'false' : 'true', $prefix.'fiscalNo1' => $period['fiscal'] ? 'true' : 'false',
            $prefix.'selectedMonthNo2' => str_pad((string) $period['year_end_month'], 2, '0', STR_PAD_LEFT), $prefix.'txtYearNo2' => (string) $period['year'],
            $prefix.'RtnPeriodFromNo4' => CarbonImmutable::parse($period['from'])->format('n/d/Y'), $prefix.'RtnPeriodToNo4' => CarbonImmutable::parse($period['to'])->format('n/d/Y'),
            $prefix.'amendedReturnYesNo5' => 'false', $prefix.'amendedReturnNo5' => 'true', $prefix.'OptShortPrd1' => 'false', $prefix.'OptShortPrd2' => 'true',
            $prefix.'branchCode' => $profile['branch_code'], $prefix.'txtPg2BranchCode' => $profile['branch_code'], $prefix.'txtRDOCode' => strtoupper($profile['rdo']),
            $prefix.'taxpayerName' => $this->text($profile['name'], true), $prefix.'Pg2TaxPayer' => $this->text($profile['name']),
            $prefix.'taxpayerAddress' => $this->text($profile['address'], true), $prefix.'taxpayerZip' => $profile['zip_code'],
            $prefix.'taxpayerContactNumber' => $this->text($profile['contact_number']), $prefix.'taxpayerEmailAddress' => $this->text($profile['contact_email']),
            $prefix.'internationalTreatyYn' => 'false', $prefix.'specialRateYn' => 'true',
            $prefix.'addSpecifyNo19' => $values['otherCreditsNo19'] > 0 ? 'REVIEWED CREDITS - SEE WORKSHEET' : '',
            $prefix.'addSpecifyNo56' => $values['otherSpecify56'] > 0 ? 'NON CREDITABLE INPUT VAT' : '',
            $prefix.'sched2TotalSales' => $this->amount($values['totalSales']),
            'resultOtherCreditsNo19' => $this->amount($values['otherCreditsNo19']),
            'resultOtherCreditsNo56' => $this->amount($values['otherSpecify56']),
            'dateFiled' => isset($context['prepared_at']) ? CarbonImmutable::parse($context['prepared_at'])->format('Y/m/d') : '',
        ];
        foreach (range(1, 4) as $quarter) {
            $fields[$prefix.'OptQuarter'.$quarter] = $quarter === (int) $period['quarter'] ? 'true' : 'false';
            $fields[$prefix.'taxPayerClassification'.$quarter] = array_search($profile['taxpayer_size'], ['micro', 'small', 'medium', 'large'], true) + 1 === $quarter ? 'true' : 'false';
        }

        return $fields;
    }
}
