<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Validation\ValidationException;

class SawtDatService
{
    private const MAX_CENTS = 9999999999999;

    /** @return array{tin: string, branch: string} */
    public function identity(string $value, string $field): array
    {
        $value = trim($value);
        if (! preg_match('/^(?:[0-9]{9}|[0-9]{12,14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3,5})?)$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Enter a valid nine-digit TIN and its branch code.']);
        }
        $digits = preg_replace('/\D/', '', $value);
        $tin = substr($digits, 0, 9);
        if ($tin === '000000000') {
            throw ValidationException::withMessages([$field => 'A real TIN is required for SAWT; an all-zero TIN cannot be exported.']);
        }
        $branch = substr($digits, 9);
        if ((int) $branch > 9999) {
            throw ValidationException::withMessages([$field => 'This branch cannot fit the SAWT four-digit field. Verify the branch code; no digits have been removed.']);
        }

        return ['tin' => $tin, 'branch' => str_pad((string) (int) $branch, 4, '0', STR_PAD_LEFT)];
    }

    /** @param array<string, mixed> $data
     * @return array{filename: string, header: list<string>, details: list<array{identity: list<string>, nature: string, atc: string, rate: int, base: int, withheld: int}>, control: list<string|int>, count: int}
     */
    public function prepare(array $data): array
    {
        $annual = in_array($data['form_type'], ['1701', '1702'], true);
        $period = CarbonImmutable::parse($data['to'])->format('m/Y');
        $identity = $this->identity($data['taxpayer']['tin'], 'taxpayer.tin');
        $names = $this->names($data['taxpayer'], 'taxpayer');
        $header = ['HSAWT', 'H'.$data['form_type'], $identity['tin'], $identity['branch'], ...$names, $period, $data['taxpayer']['rdo']];
        $groups = [];
        $totalBase = 0;
        $totalWithheld = 0;
        foreach ($data['records'] as $index => $record) {
            $field = 'records.'.$index;
            $agent = $this->identity($record['tin'], $field.'.tin');
            $agentNames = $this->names($record, $field);
            $nature = $this->text($record['nature_income'] ?? '', 50, $field.'.nature_income', true);
            $rate = $this->cents($record['tax_rate'], $field.'.tax_rate');
            $base = $this->cents($record['income_payment'], $field.'.income_payment');
            $withheld = $this->cents($record['amount_withheld'], $field.'.amount_withheld');
            if ($withheld > $base) {
                throw ValidationException::withMessages([$field.'.amount_withheld' => 'Tax withheld cannot exceed the income payment.']);
            }
            $expected = intdiv($base * $rate + 5000, 10000);
            if (abs($expected - $withheld) > 1) {
                throw ValidationException::withMessages([$field.'.tax_rate' => 'The income payment, tax rate, and tax withheld do not reconcile within one centavo.']);
            }
            $rowIdentity = [$agent['tin'], $agent['branch'], ...$agentNames];
            $key = $annual ? (string) $index : json_encode([$rowIdentity, $nature, $record['atc'], $rate], JSON_THROW_ON_ERROR);
            $groups[$key] ??= ['identity' => $rowIdentity, 'nature' => $nature, 'atc' => $record['atc'], 'rate' => $rate, 'base' => 0, 'withheld' => 0];
            $groups[$key]['base'] += $base;
            $groups[$key]['withheld'] += $withheld;
            $totalBase += $base;
            $totalWithheld += $withheld;
            if ($totalBase > self::MAX_CENTS || $totalWithheld > self::MAX_CENTS) {
                throw ValidationException::withMessages(['records' => 'The totals exceed the SAWT numeric field width.']);
            }
        }
        $details = array_values($groups);
        if (! $annual) {
            usort($details, fn (array $left, array $right): int => [$left['identity'], $left['atc'], $left['rate'], $left['nature']] <=> [$right['identity'], $right['atc'], $right['rate'], $right['nature']]);
        }
        $count = count($details);
        $control = ['CSAWT', 'C'.$data['form_type'], $identity['tin'], $identity['branch'], $period, $this->decimal($totalBase), $this->decimal($totalWithheld)];

        return ['filename' => $identity['tin'].$identity['branch'].str_replace('/', '', $period).$data['form_type'].'.DAT', 'header' => $header, 'details' => $details, 'control' => $control, 'count' => $count];
    }

    /** @param array<string, mixed> $prepared
     * @return Generator<int, string>
     */
    public function lines(array $prepared): Generator
    {
        yield $this->csv($prepared['header'], [4, 5, 6, 7]);
        $form = substr($prepared['header'][1], 1);
        $period = $prepared['header'][8];
        $annual = in_array($form, ['1701', '1702'], true);
        foreach ($prepared['details'] as $index => $record) {
            yield $this->csv(['DSAWT', 'D'.$form, $index + 1, ...$record['identity'], $period, $record['nature'], $record['atc'], $this->decimal($record['rate']), $this->decimal($record['base']), $this->decimal($record['withheld'])], [...($annual && $record['identity'][2] !== '' ? [] : [6, 7, 8]), ...($record['identity'][2] !== '' ? [5] : []), ...($record['nature'] !== '' ? [10] : [])]);
        }
        yield $this->csv($prepared['control']);
    }

    /** @param array<string, mixed> $record
     * @return list<string>
     */
    private function names(array $record, string $field): array
    {
        if ($record['entity_type'] === 'corporation') {
            return [$this->text($record['registered_name'], 50, $field.'.registered_name'), '', '', ''];
        }

        return ['', $this->text($record['last_name'], 30, $field.'.last_name'), $this->text($record['first_name'], 30, $field.'.first_name'), $this->text($record['middle_name'] ?? '', 30, $field.'.middle_name', true)];
    }

    private function text(string $value, int $limit, string $field, bool $allowEmpty = false): string
    {
        $value = BirDatText::clean($value);
        if ($value === '' && ! $allowEmpty) {
            throw ValidationException::withMessages([$field => 'Enter a name or description that can be exported as text.']);
        }
        if (strlen($value) > $limit) {
            throw ValidationException::withMessages([$field => 'The exported text exceeds '.$limit.' characters.']);
        }

        return $value;
    }

    private function cents(string|int|float $value, string $field): int
    {
        $value = (string) $value;
        if (! preg_match('/^[0-9]{1,11}(?:\.[0-9]{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Use a positive decimal amount with at most two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param list<string|int> $fields
     * @param  list<int>  $quoted
     */
    private function csv(array $fields, array $quoted = []): string
    {
        return implode(',', array_map(fn (string|int $field, int $index): string => in_array($index, $quoted, true) || strpbrk((string) $field, ",\"\r\n") !== false ? '"'.str_replace('"', '""', (string) $field).'"' : (string) $field, $fields, array_keys($fields)))."\r\n";
    }
}
