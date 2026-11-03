<?php

namespace App\Services\Tax;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class BaseBirExporter
{
    abstract public function form(): string;

    abstract protected function schema(): string;

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $period
     * @return array<string, string>
     */
    abstract protected function fields(array $context, array $profile, array $period): array;

    /** @param array<string, mixed> $context */
    abstract protected function filename(array $context, array $profile, array $period): string;

    /** @param array<string, mixed> $context
     * @return array{schema: string, filename: string, fields: array<string, string>}
     */
    final public function prepare(array $context): array
    {
        $profile = $this->profile($context['taxpayer'] ?? []);
        $period = $this->period($context['period'] ?? [], $profile);
        $layout = $this->layout();
        $values = $this->fields($context, $profile, $period);
        if (array_diff_key($values, $layout['fields']) !== []) {
            throw new \LogicException('The exporter attempted to write a field outside its versioned layout.');
        }

        return ['schema' => $this->schema(), 'filename' => $this->filename($context, $profile, $period), 'fields' => array_replace($layout['fields'], $values)];
    }

    /**
     * These are eBIRForms save files, not a rooted XML document. The duplicate keys
     * and original form-specific whitespace are deliberate. Never pass through DOMDocument.
     *
     * @param  array{schema: string, filename: string, fields: array<string, string>}  $prepared
     * @return Generator<int, string>
     */
    final public function stream(array $prepared): Generator
    {
        $this->assertPrepared($prepared);
        $layout = $this->layout();
        yield $layout['prefix'];
        $first = true;
        foreach ($prepared['fields'] as $key => $value) {
            if (! $first) {
                yield $layout['separator'];
            }
            yield '<div>'.$key.'='.$value.$key.'=</div>';
            $first = false;
        }
        yield $layout['suffix'];
    }

    /** @param array{schema: string, filename: string, fields: array<string, string>} $prepared */
    final public function assertPrepared(array $prepared): void
    {
        $layout = $this->layout();
        if ($prepared['schema'] !== $this->schema() || array_keys($prepared['fields']) !== array_keys($layout['fields'])) {
            throw new \LogicException('The archived XML layout does not match this exporter version.');
        }
        if (! preg_match('/^[0-9]{14}-(?:2550Qv2024-[0-9]{6}|1601EQ-[0-9]{4})Q[1-4]\.xml$/D', $prepared['filename'])) {
            throw new \LogicException('The archived XML filename is invalid.');
        }
        if (($prepared['fields']['txtFinalFlag'] ?? null) !== '1' || ($prepared['fields']['txtEnroll'] ?? null) !== 'Y' || ($prepared['fields']['ebirOnlineSecret'] ?? '') !== '') {
            throw new \LogicException('The archived file has invalid eBIRForms control fields.');
        }
        foreach ($prepared['fields'] as $key => $value) {
            if (! is_string($value) || preg_match('/[<>=\x00-\x1F\x7F]/', $value)) {
                throw new \LogicException('An unsafe value was found in the archived eBIRForms fields.');
            }
        }
    }

    /** @return array{prefix: string, separator: string, suffix: string, fields: array<string, string>} */
    private function layout(): array
    {
        return json_decode(file_get_contents(resource_path('templates/bir-'.$this->schema().'.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function profile(array $profile): array
    {
        $tin = trim((string) ($profile['tin'] ?? ''));
        if (! preg_match('/^(?:[0-9]{9}|[0-9]{12,14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3,5})?)$/D', $tin)) {
            $this->fail('taxpayer.tin', 'Enter the taxpayer nine-digit TIN and five-digit branch code.');
        }
        $digits = preg_replace('/\D/', '', $tin);
        if (substr($digits, 0, 9) === '000000000') {
            $this->fail('taxpayer.tin', 'An all-zero TIN cannot be exported.');
        }
        $embedded = substr($digits, 9);
        $branch = $profile['branch_code'] ?? $embedded;
        if (! is_scalar($branch) || ! preg_match('/^[0-9]{1,5}$/D', (string) $branch)) {
            $this->fail('taxpayer.branch_code', 'Enter the five-digit branch code.');
        }
        $branch = str_pad((string) $branch, 5, '0', STR_PAD_LEFT);
        if ($embedded !== '' && str_pad($embedded, 5, '0', STR_PAD_LEFT) !== $branch) {
            $this->fail('taxpayer.branch_code', 'The branch code disagrees with the branch embedded in the TIN.');
        }
        $address = $profile['address'] ?? implode(' ', array_filter(array_map(fn (string $key): string => (string) ($profile[$key] ?? ''), ['building', 'street', 'barangay', 'city', 'province'])));
        $name = trim((string) ($profile['registered_name'] ?? '')) !== '' ? $profile['registered_name'] : ($profile['company_name'] ?? '');
        $profile = [...$profile, 'tin' => substr($digits, 0, 9), 'branch_code' => $branch, 'name' => $this->text($name), 'address' => $this->text($address)];
        Validator::make($profile, [
            'name' => ['required', 'string', 'max:150'], 'address' => ['required', 'string', 'max:250'],
            'zip_code' => ['required', 'string', 'regex:/^[0-9]{4}$/D'],
            'rdo' => ['required', 'string', 'regex:/^(?:[0-9]{3}|[0-9]{2}[A-Z])$/iD', 'not_in:000'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/D'],
            'contact_email' => ['required', 'email', 'max:100'],
        ])->validate();

        return $profile;
    }

    /** @param array<string, mixed> $period
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function period(array $period, array $profile): array
    {
        Validator::make($period, ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'year' => ['required', 'integer', 'between:2023,2199'], 'quarter' => ['required', 'integer', 'between:1,4']])->validate();
        $fiscal = $this->form() === '2550Q' && ($profile['accounting_period'] ?? 'calendar') === 'fiscal';
        $endMonth = $fiscal ? (int) ($profile['fiscal_end_month'] ?? 0) : 12;
        if ($endMonth < 1 || $endMonth > 12) {
            $this->fail('taxpayer.fiscal_end_month', 'Confirm the fiscal year ending month.');
        }
        $start = CarbonImmutable::create((int) $period['year'], $endMonth, 1)->subMonthsNoOverflow(11)->startOfMonth()->addMonthsNoOverflow(((int) $period['quarter'] - 1) * 3);
        if ($period['from'] !== $start->toDateString() || $period['to'] !== $start->addMonthsNoOverflow(2)->endOfMonth()->toDateString()) {
            $this->fail('period', 'XML requires a complete quarter matching the saved calendar or fiscal year.');
        }

        return [...$period, 'fiscal' => $fiscal, 'year_end_month' => $endMonth];
    }

    protected function text(mixed $value, bool $encoded = false): string
    {
        if (! is_scalar($value)) {
            $this->fail('text', 'Profile text must be a string.');
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)) ?? '');
        if (strpbrk($value, '<>=') !== false) {
            $this->fail('text', 'Remove markup or equals signs from the taxpayer text fields.');
        }

        return $encoded ? rawurlencode($value) : $value;
    }

    protected function amount(int $cents): string
    {
        if (abs($cents) > 9999999999999) {
            $this->fail('amount', 'An amount exceeds the supported form width.');
        }
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '').number_format(intdiv($absolute, 100), 0, '.', ',').'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    protected function cents(array $values, string $key): int
    {
        $value = $values[$key] ?? 0;
        if (! is_int($value) || $value < 0 || $value > 9999999999999) {
            $this->fail($key, 'The mapped value must be non-negative integer centavos.');
        }

        return $value;
    }

    protected function percentage(int $base, int $rate): int
    {
        return intdiv($base, 1000000) * $rate + intdiv(($base % 1000000) * $rate + 500000, 1000000);
    }

    protected function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages(['bir_xml.'.$key => $message]);
    }
}
