<?php

namespace App\Services\Tax;

use Illuminate\Database\Eloquent\Model;

class BirVatLedgerMapper
{
    /**
     * The application's Sales/Purchases rows store VAT-inclusive gross in integer centavos
     * and a historical tax label. Model collections can supply those same attributes.
     *
     * @param  iterable<array<string, mixed>|Model>  $sales
     * @param  iterable<array<string, mixed>|Model>  $purchases
     * @param  array{from: string, to: string}  $period
     * @return array{values: array<string, int>, sources: list<array<string, mixed>>, issues: list<string>}
     */
    public function map(iterable $sales, iterable $purchases, array $period): array
    {
        $values = array_fill_keys(['vatableSales', 'outputVatSales', 'zeroRatedSales', 'exemptSales', 'domesticPurchase', 'domesticInputTax', 'servicesPurchase', 'serviceInputTax', 'importPurchase', 'importInputTax', 'domesticPurchaseNoTax', 'vatExemptImports', 'non_creditable'], 0);
        $sources = $issues = [];
        foreach (['sales' => $sales, 'purchases' => $purchases] as $sheet => $rows) {
            $seen = [];
            foreach ($rows as $record) {
                $record = $record instanceof Model ? $record->toArray() : $record;
                if (($record['status'] ?? '') !== 'posted' || ($record['date'] ?? '') < $period['from'] || ($record['date'] ?? '') > $period['to']) {
                    continue;
                }
                $id = (string) ($record['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    $issues[] = 'A '.$sheet.' source is missing its unique ID or appears more than once.';

                    continue;
                }
                $seen[$id] = true;
                $sources[] = ['id' => $sheet.':'.$id, 'number' => $record['number'] ?? $id, 'date' => $record['date']];
                $label = $sheet.' '.($record['number'] ?? $id);
                $gross = $record['gross'] ?? null;
                if (! is_int($gross) || $gross < 0 || $gross > 99999999999) {
                    $issues[] = $label.': gross must be saved in integer centavos.';

                    continue;
                }
                $tax = strtoupper(trim((string) ($record['tax'] ?? '')));
                $parts = explode('|', $tax);
                $code = $parts[0] === 'TAX' ? ($parts[1] ?? '') : $tax;
                if (in_array($code, ['NR', 'NA', 'NO RECEIPT'], true)) {
                    if ($sheet === 'sales') {
                        $issues[] = $label.': classify the sale as taxable, zero-rated or exempt before XML export.';
                    }

                    continue;
                }
                if (! empty($record['isImportation']) || ! empty($record['importation']) || ! empty($record['customs']) || str_contains($code, 'IMPORT') || ! empty($record['nonResidentService'])) {
                    $issues[] = $label.': importation / non-resident VAT needs reviewed customs or remittance figures; it cannot be inferred from domestic gross.';

                    continue;
                }
                $rate = null;
                $kind = '';
                if (in_array($tax, ['12% VAT', 'VAT (12%)'], true)) {
                    $kind = 'VAT';
                    $rate = 120000;
                } elseif ($parts[0] === 'TAX' && count($parts) === 4 && preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,4}))?$/D', $parts[3], $match)) {
                    $kind = $parts[2];
                    $rate = (int) $match[1] * 10000 + (int) str_pad($match[2] ?? '', 4, '0');
                } elseif (in_array($tax, ['ZERO-RATED (0%)', 'ZERO-RATED', 'ZERO'], true)) {
                    $kind = 'ZERO RATED';
                    $rate = 0;
                } elseif (in_array($tax, ['NON-VAT', 'NONVAT', 'VAT EXEMPT', 'EXEMPT'], true)) {
                    $kind = 'EXEMPT';
                    $rate = 0;
                }
                if ($rate === null || $rate > 1000000 || ! in_array($kind, ['VAT', 'EXEMPT', 'ZERO RATED'], true)) {
                    $issues[] = $label.': the saved tax classification is unsupported.';

                    continue;
                }
                if ($kind === 'VAT' && $rate !== 120000) {
                    $issues[] = $label.': a non-standard VAT rate requires a separately verified special-rate mapping.';

                    continue;
                }
                $net = $kind === 'VAT' ? intdiv($gross * 1000000 + intdiv(1000000 + $rate, 2), 1000000 + $rate) : $gross;
                $vat = $gross - $net;
                if ($sheet === 'sales') {
                    if ($kind === 'VAT') {
                        $values['vatableSales'] += $net;
                        $values['outputVatSales'] += $vat;
                    } elseif ($kind === 'ZERO RATED') {
                        $values['zeroRatedSales'] += $net;
                    } else {
                        $values['exemptSales'] += $net;
                    }
                } elseif ($kind === 'VAT') {
                    // Domestic services belong in Domestic Purchases; form item 45 is for NON-RESIDENT services.
                    $values['domesticPurchase'] += $net;
                    $values['domesticInputTax'] += $vat;
                    $nonCredit = (string) ($record['non_creditable_vat_amount'] ?? $record['nonCreditableVatAmount'] ?? '0');
                    if (! preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/D', $nonCredit, $match)) {
                        $issues[] = $label.': review the non-creditable VAT amount.';

                        continue;
                    }
                    $nonCreditCents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
                    if ($nonCreditCents > $vat) {
                        $issues[] = $label.': non-creditable VAT exceeds input VAT.';
                    }
                    $values['non_creditable'] += $nonCreditCents;
                } else {
                    $values['domesticPurchaseNoTax'] += $net;
                }
            }
        }

        return ['values' => $values, 'sources' => $sources, 'issues' => $issues];
    }
}
