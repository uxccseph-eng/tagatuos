<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Sales and Purchases record layouts adapted from cpa-coder/vat-relief, DatFileGenerator.cs.
 * https://github.com/cpa-coder/vat-relief
 *
 * MIT License
 * Copyright (c) 2022 DebitExpress Business Solutions
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
class BirReliefService
{
    private const AMOUNTS = ['exempt_amount', 'zero_rated_amount', 'taxable_amount', 'output_vat_amount'];

    public function cleanText(?string $value): string
    {
        return BirDatText::clean($value);
    }

    public function tin(?string $value, string $field, bool $allowMissing = false): string
    {
        $digits = str_replace('-', '', trim($value ?? ''));
        if (($digits === '' || preg_match('/^0+$/D', $digits)) && $allowMissing) {
            return '';
        }
        if (! preg_match('/^(?:[0-9]{9}|[0-9]{12,14})$/D', $digits) || ! preg_match('/[1-9]/', substr($digits, 0, 9))) {
            throw ValidationException::withMessages([$field => 'Enter a valid nine-digit taxpayer TIN, optionally followed by the branch code.']);
        }

        return substr($digits, 0, 9);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function files(array $data, string $kind = 'S'): array
    {
        $purchases = $kind === 'P';
        $recordsKey = $purchases ? 'purchase_records' : 'sales_records';
        $partyKey = $purchases ? 'supplier' : 'customer';
        $amountCount = $purchases ? 6 : 4;
        $companyTin = $this->tin($data['company_tin'], 'company_tin');
        $corporation = $data['non_individual'];
        $name = $this->cleanText($data['company_name'] ?? '');
        $last = $this->cleanText($data['last_name'] ?? '');
        $first = $this->cleanText($data['first_name'] ?? '');
        $middle = $this->cleanText($data['middle_name'] ?? '');
        if (($corporation && $name === '') || (! $corporation && ($last === '' || $first === ''))) {
            throw ValidationException::withMessages(['company_name' => 'Supply the registered business name, or the individual taxpayer first and last names.']);
        }
        $months = [];
        foreach ($data[$recordsKey] as $index => $record) {
            $month = substr($record['date'], 0, 7);
            $partyName = $this->cleanText($record[$partyKey.'_name']);
            $partyLast = $this->cleanText($record['last_name'] ?? '');
            $partyFirst = $this->cleanText($record['first_name'] ?? '');
            $partyMiddle = $this->cleanText($record['middle_name'] ?? '');
            if ($partyName === '' && ($partyLast === '' || $partyFirst === '')) {
                throw ValidationException::withMessages([$recordsKey.".{$index}.".$partyKey.'_name' => 'Supplier or customer identity is missing.']);
            }
            $tin = $this->tin($record[$partyKey.'_tin'], $recordsKey.".{$index}.".$partyKey.'_tin', ! $purchases);
            if ($purchases && $tin === $companyTin) {
                throw ValidationException::withMessages([$recordsKey.".{$index}.supplier_tin" => 'Supplier TIN cannot be the same as the company TIN.']);
            }
            $key = $tin !== '' ? 'tin:'.$tin : ($partyName !== '' ? 'name:'.$partyName : 'person:'.$partyLast.'|'.$partyFirst.'|'.$partyMiddle);
            $amounts = $purchases ? [] : array_map(fn (string $field): int => (int) round((float) $record[$field] * 100, 0, PHP_ROUND_HALF_EVEN), self::AMOUNTS);
            $nonCreditable = 0;
            if ($purchases) {
                $taxable = (int) round((float) $record['taxable_amount'] * 100, 0, PHP_ROUND_HALF_EVEN);
                $category = $record['relief_category'] ?? '';
                $categories = ['services' => 2, 'capital_goods' => 3, 'goods' => 4];
                if ($taxable !== 0 && ! isset($categories[$category])) {
                    throw ValidationException::withMessages([$recordsKey.".{$index}.relief_category" => 'Select Services, Capital goods or Other goods for taxable purchases.']);
                }
                $amounts = [
                    (int) round((float) $record['exempt_amount'] * 100, 0, PHP_ROUND_HALF_EVEN),
                    (int) round((float) $record['zero_rated_amount'] * 100, 0, PHP_ROUND_HALF_EVEN),
                    0, 0, 0,
                    (int) round((float) $record['input_vat_amount'] * 100, 0, PHP_ROUND_HALF_EVEN),
                ];
                if ($taxable !== 0) {
                    $amounts[$categories[$category]] = $taxable;
                }
                $nonCreditable = (int) round((float) $record['non_creditable_vat_amount'] * 100, 0, PHP_ROUND_HALF_EVEN);
                if ($nonCreditable < min(0, $amounts[5]) || $nonCreditable > max(0, $amounts[5])) {
                    throw ValidationException::withMessages([$recordsKey.".{$index}.non_creditable_vat_amount" => 'Non-creditable VAT must be within the input VAT amount.']);
                }
            }
            $gross = (int) round((float) $record['gross_amount'] * 100, 0, PHP_ROUND_HALF_EVEN);
            if (array_sum($amounts) !== $gross) {
                throw ValidationException::withMessages([$recordsKey.".{$index}.gross_amount" => 'Exempt, zero-rated and taxable amounts plus VAT must equal the gross amount.']);
            }
            if (! isset($months[$month][$key])) {
                $months[$month][$key] = [
                    'tin' => $tin, 'name' => $partyName, 'last' => $partyLast,
                    'first' => $partyFirst, 'middle' => $partyMiddle,
                    'street' => $this->cleanText($record[$partyKey.'_street'] ?? $record[$partyKey.'_address'] ?? ''),
                    'city' => $this->cleanText($record[$partyKey.'_city'] ?? ''),
                    'amounts' => array_fill(0, $amountCount, 0), 'non_creditable' => 0,
                ];
            }
            $months[$month][$key]['non_creditable'] += $nonCreditable;
            foreach ($amounts as $column => $amount) {
                $months[$month][$key]['amounts'][$column] += $amount;
            }
        }
        ksort($months);
        $files = [];
        foreach ($months as $month => $groups) {
            $date = CarbonImmutable::createFromFormat('!Y-m', $month)->endOfMonth();
            $ending = $date->format('m/d/Y');
            $totals = array_fill(0, $amountCount, 0);
            $nonCreditableTotal = 0;
            foreach ($groups as $group) {
                $nonCreditableTotal += $group['non_creditable'];
                foreach ($group['amounts'] as $column => $amount) {
                    $totals[$column] += $amount;
                }
            }
            $headerTotals = $purchases ? [...$totals, $totals[5] - $nonCreditableTotal, $nonCreditableTotal] : $totals;
            $header = [
                'H', $kind, $this->quoted($companyTin),
                $this->quoted($corporation ? $name : ''),
                $this->quoted($corporation ? '' : $last),
                $this->quoted($corporation ? '' : $first),
                $this->quoted($corporation ? '' : $middle),
                $this->quoted($this->cleanText($data['trade_name'] ?? '')),
                $this->quoted($this->cleanText($data['street'])),
                $this->quoted($this->cleanText($data['city'])),
                ...array_map(fn (int $amount): string => number_format($amount / 100, 2, '.', ''), $headerTotals),
                $data['rdo'], $ending, (string) $data['period_end'],
            ];
            $content = implode(',', $header)."\r\n";
            usort($groups, fn (array $a, array $b): int => $purchases ? ([$a['name'], $a['last']] <=> [$b['name'], $b['last']]) : ([$a['tin'], $a['name'], $a['last']] <=> [$b['tin'], $b['name'], $b['last']]));
            foreach ($groups as $group) {
                $fields = [
                    'D', $kind, $this->quoted($group['tin']), $this->quoted($group['name']),
                    $this->quoted($group['last']), $this->quoted($group['first']),
                    $this->quoted($group['middle']), $this->quoted($group['street']),
                    $this->quoted($group['city']),
                    ...array_map(fn (int $amount): string => rtrim(rtrim(number_format($amount / 100, 2, '.', ''), '0'), '.'), $group['amounts']),
                    $companyTin, $ending,
                ];
                $content .= implode(',', $fields)."\r\n";
            }
            $files[$companyTin.$kind.$date->format('mY').'.DAT'] = $content;
        }

        return $files;
    }

    private function quoted(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
