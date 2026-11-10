<?php

namespace App\Services\Tax;

use Carbon\CarbonImmutable;

class Bir2550QPrintData
{
    public const AMOUNTS = [
        '15' => 'excessInputTax', '16' => 'creditableVat', '17' => 'advVatPayment', '18' => 'vatPaidReturn', '19' => 'otherCreditsNo19', '20' => 'totalTaxCredits', '21' => 'excessCredits', '22' => 'surcharge', '23' => 'interest', '24' => 'compromise', '25' => 'penalties', '26' => 'totalPayable',
        '31A' => 'vatableSales', '31B' => 'outputVatSales', '32A' => 'zeroRatedSales', '33A' => 'exemptSales', '34A' => 'totalSales', '34B' => 'outputTaxDue', '35' => 'lessOutputVat', '36' => 'addOutputVat', '37' => 'totalAdjOutput', '38' => 'inputTaxCarried', '39' => 'inputTaxDeferred', '40' => 'transitionalInputTax', '41' => 'presumptiveInputTax', '42' => 'otherSpecify42', '43' => 'total43', '44A' => 'domesticPurchase', '44B' => 'domesticInputTax', '45A' => 'servicesPurchase', '45B' => 'serviceInputTax', '46A' => 'importPurchase', '46B' => 'importInputTax', '47A' => 'otherSpecify47', '47B' => 'otherSpecify47B', '48A' => 'domesticPurchaseNoTax', '49A' => 'vatExemptImports', '50A' => 'totalCurPurchase', '50B' => 'totalCurInputTax', '51' => 'totalAvailInputTax', '52' => 'importCapitalInputTax', '53' => 'inputTaxAttr', '54' => 'vatRefund', '55' => 'inputVatUnpaid', '56' => 'otherSpecify56', '57' => 'totalDeductions', '58' => 'addInputVat', '59' => 'adjDeductions', '60' => 'totalAllowInputTax', '61' => 'netVatPayable',
    ];

    public function __construct(private Bir0619EPrintData $profileData) {}

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function build(object $record, array $snapshot): array
    {
        $data = [...$this->profileData->build($record, $snapshot), 'mapped' => false, 'reason' => 'This preparation has no complete, reconciled April 2024 2550Q XML mapping. Generate an original return with reviewed XML before using the supplied form layout.'];
        if (($snapshot['bir_xml']['schema'] ?? '') !== '2550q-v2024' || ! empty($snapshot['amended'])) {
            return $data;
        }
        $fields = $snapshot['bir_xml']['fields'] ?? [];
        $prefix = 'frm2550qv2024:';
        $data = [...$data, 'name' => mb_strtoupper(rawurldecode((string) ($fields[$prefix.'taxpayerName'] ?? ''))), 'address' => mb_strtoupper(rawurldecode((string) ($fields[$prefix.'taxpayerAddress'] ?? ''))), 'tin' => implode('', array_map(fn (int $part): string => (string) ($fields[$prefix.'txtTIN'.$part] ?? ''), [1, 2, 3])), 'branch' => (string) ($fields[$prefix.'branchCode'] ?? ''), 'rdo' => (string) ($fields[$prefix.'txtRDOCode'] ?? ''), 'zip' => (string) ($fields[$prefix.'taxpayerZip'] ?? ''), 'contact' => (string) ($fields[$prefix.'taxpayerContactNumber'] ?? ''), 'email' => mb_strtoupper((string) ($fields[$prefix.'taxpayerEmailAddress'] ?? ''))];
        if (! preg_match('/^[0-9]{9}$/D', $data['tin']) || ! preg_match('/^[0-9]{5}$/D', $data['branch']) || $data['name'] === '' || $data['address'] === '') {
            return $data;
        }
        $amounts = [];
        foreach (self::AMOUNTS as $item => $key) {
            $value = $this->money($fields[$key === 'otherSpecify47B' ? $key : $prefix.$key] ?? null);
            if ($value === null || ($value < 0 && ! in_array((string) $item, ['15', '21', '26', '61'], true))) {
                return $data;
            }
            $amounts[$item] = $value;
        }
        $expected = [
            '20' => $amounts[16] + $amounts[17] + $amounts[18] + $amounts[19], '21' => $amounts[15] - $amounts[20], '25' => $amounts[22] + $amounts[23] + $amounts[24], '26' => $amounts[21] + $amounts[25],
            '34A' => $amounts['31A'] + $amounts['32A'] + $amounts['33A'], '34B' => $amounts['31B'], '37' => $amounts['34B'] - $amounts[35] + $amounts[36], '43' => $amounts[38] + $amounts[39] + $amounts[40] + $amounts[41] + $amounts[42],
            '50A' => $amounts['44A'] + $amounts['45A'] + $amounts['46A'] + $amounts['47A'] + $amounts['48A'] + $amounts['49A'], '50B' => $amounts['44B'] + $amounts['45B'] + $amounts['46B'] + $amounts['47B'], '51' => $amounts[43] + $amounts['50B'], '57' => $amounts[52] + $amounts[53] + $amounts[54] + $amounts[55] + $amounts[56], '59' => $amounts[57] + $amounts[58], '60' => $amounts[51] - $amounts[59], '61' => $amounts[37] - $amounts[60], '15' => $amounts[61],
        ];
        foreach ($expected as $item => $value) {
            if ($amounts[$item] !== $value) {
                return $data;
            }
        }
        $calculation = $snapshot['calculation'];
        if ($amounts[15] !== (int) $calculation['tax'] || $amounts[20] !== (int) $calculation['credits'] || $amounts[22] !== (int) $calculation['surcharge'] || $amounts[23] !== (int) $calculation['interest'] || $amounts[24] !== (int) $calculation['compromise'] || max(0, $amounts[26]) !== (int) $calculation['payable'] || max(0, -$amounts[26]) !== (int) $calculation['credit_balance'] || (int) ($calculation['additional_tax'] ?? 0) !== 0) {
            return $data;
        }
        $schedules = [];
        foreach (['sched1TotalBalPrev', 'sched1TotalBalNext', 'sched3TotalIncome', 'sched3TotalTax', 'sched4AmountPaid', 'sched2InputTaxDirect', 'sched2VatExemptSale', 'sched2AmountInputTax', 'sched2TotalSales', 'sched2TotalRatable', 'sched2TotalAttr'] as $key) {
            $value = $this->money($fields[str_starts_with($key, 'sched2') ? $prefix.$key : $key] ?? null);
            if ($value === null || $value < 0) {
                return $data;
            }
            $schedules[$key] = $value;
        }
        foreach (['16', '17', '18', '35', '36', '39', '40', '41', '42', '47A', '47B', '52', '53', '54', '55', '58'] as $item) {
            if ($amounts[$item] !== 0) {
                return [...$data, 'reason' => 'This saved return includes additional VAT adjustments or schedules that are not yet mapped to this print layout. The complete archived worksheet remains available.'];
            }
        }
        foreach ($schedules as $key => $value) {
            if (($key === 'sched2TotalSales' && $value !== $amounts['34A']) || ($key !== 'sched2TotalSales' && $value !== 0)) {
                return $data;
            }
        }
        foreach (['txtDatePurchase10', 'txtDatePurchase11', 'txtNameWithHoldingAgent30', 'txtNameWithHoldingAgent31', 'txtNameOfMiller40', 'txtNameOfMiller41'] as $key) {
            if (trim((string) ($fields[$key] ?? '')) !== '') {
                return $data;
            }
        }
        $descriptions = [];
        foreach (['19', '42', '47', '56'] as $item) {
            $text = (string) ($fields[$prefix.'addSpecifyNo'.$item] ?? '');
            if (mb_strlen($text) > ($item === '47' ? 20 : 46)) {
                return [...$data, 'reason' => 'A VAT adjustment description is too long for the supplied form box. Use the complete archived worksheet.'];
            }
            $descriptions[$item] = $text;
        }
        $quarter = (int) ($snapshot['period']['quarter'] ?? 0);
        $year = (string) ($fields[$prefix.'txtYearNo2'] ?? '');
        $month = (string) ($fields[$prefix.'selectedMonthNo2'] ?? '');
        $sizes = [];
        foreach (range(1, 4) as $index) {
            if (($fields[$prefix.'taxPayerClassification'.$index] ?? '') === 'true') {
                $sizes[] = $index;
            }
        }
        $fiscal = ($fields[$prefix.'fiscalNo1'] ?? '') === 'true';
        if ($quarter < 1 || $quarter > 4 || ! preg_match('/^[0-9]{4}$/D', $year) || ! preg_match('/^(0[1-9]|1[0-2])$/D', $month) || count($sizes) !== 1 || ($fields[$prefix.'OptQuarter'.$quarter] ?? '') !== 'true' || ($fields[$prefix.'amendedReturnNo5'] ?? '') !== 'true' || ($fields[$prefix.'OptShortPrd2'] ?? '') !== 'true' || ($fields[$prefix.'specialRateYn'] ?? '') !== 'true') {
            return $data;
        }
        if (($fields[$prefix.'RtnPeriodFromNo4'] ?? '') !== CarbonImmutable::parse($record->period_from)->format('n/d/Y') || ($fields[$prefix.'RtnPeriodToNo4'] ?? '') !== CarbonImmutable::parse($record->period_to)->format('n/d/Y')) {
            return $data;
        }
        if (mb_strlen($data['name']) > 80 || mb_strlen($data['address']) > 180 || mb_strlen($data['email']) > 80) {
            return [...$data, 'reason' => 'The saved name, address or email exceeds the available space on this print layout. The worksheet retains the full registered profile.'];
        }

        return [...$data, 'mapped' => true, 'amounts' => $amounts, 'schedules' => $schedules, 'descriptions' => $descriptions, 'quarter' => $quarter, 'year_end' => $month.$year, 'from' => CarbonImmutable::parse($record->period_from)->format('mdY'), 'to' => CarbonImmutable::parse($record->period_to)->format('mdY'), 'fiscal' => $fiscal, 'size' => $sizes[0]];
    }

    private function money(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(-?)([0-9]{1,11})\.([0-9]{2})$/D', str_replace(',', '', $value), $parts)) {
            return null;
        }

        return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 100 + (int) $parts[3]);
    }
}
