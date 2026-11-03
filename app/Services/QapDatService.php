<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Validation\ValidationException;

class QapDatService
{
    /** @param array<string, mixed> $company
     * @param  array<string, mixed>  $data
     * @return array{filename: string, files: list<array<string, mixed>>, count: int}
     */
    public function prepare(array $company, array $data): array
    {
        return $this->preparePeriod($company, $data, false);
    }

    /** @param array<string, mixed> $company
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareAnnual(array $company, array $data): array
    {
        return $this->preparePeriod($company, $data, true);
    }

    /** @param array<string, mixed> $company
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preparePeriod(array $company, array $data, bool $annual): array
    {
        $payorValue = (string) ($company['tin'] ?? '');
        if (strlen(preg_replace('/\D/', '', $payorValue)) === 9) {
            $payorValue .= str_pad((string) ($company['branch_code'] ?? '0'), 5, '0', STR_PAD_LEFT);
        }
        [$payorTin, $payorBranch] = $this->identity($payorValue, 'company.tin');
        $start = CarbonImmutable::create((int) $data['year'], $annual ? 1 : ((int) $data['quarter'] - 1) * 3 + 1, 1)->startOfDay();
        $end = $start->addMonths($annual ? 11 : 2)->endOfMonth();

        $groups = [];
        foreach ($data['records'] as $index => $record) {
            $field = 'records.'.$index;
            $date = CarbonImmutable::parse($record['date']);
            if ($date->lt($start) || $date->gt($end)) {
                throw ValidationException::withMessages([$field.'.date' => $annual ? 'Every certificate must fall within the selected calendar year.' : 'Every certificate must fall within the selected calendar quarter.']);
            }
            [$tin, $branch] = $this->identity($record['tin'], $field.'.tin');
            $names = $record['entity_type'] === 'individual'
                ? ['', $this->name($record['last_name'] ?? '', 30, $field.'.last_name'), $this->name($record['first_name'] ?? '', 30, $field.'.first_name'), $this->name($record['middle_name'] ?? '', 30, $field.'.middle_name', true)]
                : [$this->name($record['registered_name'] ?? '', 50, $field.'.registered_name'), '', '', ''];
            $base = $this->cents($record['income_payment']);
            $withheld = $this->cents($record['amount_withheld']);
            $rate = $this->cents($record['tax_rate']);
            if ($withheld > $base || abs(intdiv($base * $rate + 5000, 10000) - $withheld) > 1) {
                throw ValidationException::withMessages([$field.'.amount_withheld' => 'Tax base, rate, and withheld amount must reconcile within one centavo.']);
            }
            $payeeKey = empty($record['payee_id']) ? $tin.'|'.$branch : 'party:'.$record['payee_id'];
            $key = ($annual ? '' : $date->format('m/Y').'|').$payeeKey.'|'.$record['atc'];
            $identity = [$tin, $branch, ...$names];
            if (isset($groups[$key]) && ($groups[$key]['identity'] !== $identity || $groups[$key]['rate'] !== $rate)) {
                throw ValidationException::withMessages([$field.'.atc' => 'The same payee and ATC have conflicting names or tax rates. Correct the source certificates before exporting.']);
            }
            $groups[$key] ??= ['period' => $date->format('m/Y'), 'identity' => $identity, 'atc' => $record['atc'], 'rate' => $rate, 'base' => 0, 'withheld' => 0];
            $groups[$key]['base'] += $base;
            $groups[$key]['withheld'] += $withheld;
            if ($groups[$key]['base'] > 9999999999999 || $groups[$key]['withheld'] > 9999999999999) {
                throw ValidationException::withMessages(['records' => 'A payee total exceeds the supported numeric field width.']);
            }
        }
        ksort($groups);

        if ($annual) {
            return ['filename' => $payorTin.$payorBranch.'1604E12'.$data['year'].'.DAT', 'header' => ['H', '1604E', $payorTin, $payorBranch, '12/'.$data['year']], 'details' => array_values($groups), 'count' => count($groups)];
        }

        $files = [];
        for ($offset = 0; $offset < 3; $offset++) {
            $month = $start->addMonths($offset);
            $period = $month->format('m/Y');
            $details = array_values(array_filter($groups, fn (array $group): bool => $group['period'] === $period));
            if ($details === []) {
                continue;
            }
            $files[] = ['filename' => $payorTin.$payorBranch.'1601EQ'.$month->format('mY').'.DAT', 'header' => ['H', '1601EQ', $payorTin, $payorBranch, $period], 'details' => $details, 'count' => count($details)];
        }

        return ['filename' => 'QAP-EWT-Q'.$data['quarter'].'-'.$data['year'].'.zip', 'files' => $files, 'count' => count($groups)];
    }

    /** @param array<string, mixed> $prepared
     * @return Generator<int, string>
     */
    public function lines(array $prepared): Generator
    {
        yield $this->csv($prepared['header'], [2, 3]);
        foreach ($prepared['details'] as $record) {
            yield $this->csv(['D', $prepared['header'][1], $prepared['header'][2], $prepared['header'][3], ...$record['identity'], $record['atc'], $this->decimal($record['base']), $this->decimal($record['rate']), $this->decimal($record['withheld']), $prepared['header'][4]], [2, 3, 4, 5, 6, 7, 8, 9, 10, 14]);
        }
    }

    /**
     * Stream an uncompressed ZIP32 archive without writing DAT files or an archive to disk.
     * File names are generated internally; the validated request is limited to 50,000 rows.
     *
     * @param  array<string, mixed>  $prepared
     * @return Generator<int, string>
     */
    public function archiveLines(array $prepared): Generator
    {
        $directory = '';
        $offset = 0;
        foreach ($prepared['files'] as $file) {
            $hash = hash_init('crc32b');
            $size = 0;
            foreach ($this->lines($file) as $line) {
                hash_update($hash, $line);
                $size += strlen($line);
            }
            $checksum = (int) hexdec(hash_final($hash));
            $name = $file['filename'];
            $length = strlen($name);
            $header = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 33, $checksum, $size, $size, $length, 0).$name;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 33, $checksum, $size, $size, $length, 0, 0, 0, 0, 0, $offset).$name;
            yield $header;
            yield from $this->lines($file);
            $offset += strlen($header) + $size;
        }
        yield $directory;
        $count = count($prepared['files']);
        yield pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), $offset, 0);
    }

    /** @return array{string, string} */
    private function identity(string $value, string $field): array
    {
        $digits = preg_replace('/\D/', '', $value);
        if (! in_array(strlen($digits), [9, 12, 13, 14], true) || ! preg_match('/[1-9]/', substr($digits, 0, 9))) {
            throw ValidationException::withMessages([$field => 'Enter a real nine-digit TIN and its branch code.']);
        }

        return [substr($digits, 0, 9), str_pad(substr($digits, 9), 5, '0', STR_PAD_LEFT)];
    }

    private function name(string $value, int $limit, string $field, bool $allowEmpty = false): string
    {
        $value = BirDatText::clean($value);
        if ((! $allowEmpty && $value === '') || strlen($value) > $limit) {
            throw ValidationException::withMessages([$field => 'Enter an exportable name of at most '.$limit.' characters.']);
        }

        return $value;
    }

    private function cents(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function decimal(int $value): string
    {
        return intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param list<string> $values
     * @param  list<int>  $quoted
     */
    private function csv(array $values, array $quoted): string
    {
        return implode(',', array_map(fn (string $value, int $index): string => in_array($index, $quoted, true) ? '"'.str_replace('"', '""', $value).'"' : $value, $values, array_keys($values)))."\r\n";
    }
}
