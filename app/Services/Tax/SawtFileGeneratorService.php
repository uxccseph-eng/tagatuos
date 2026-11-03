<?php

namespace App\Services\Tax;

use App\Services\SawtDatService;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SawtFileGeneratorService
{
    public function __construct(private readonly SawtDatService $formatter) {}

    /**
     * Amounts in this public contract are peso decimals, never ledger centavos.
     * Date/status may be omitted only when the caller already selected posted certificates.
     *
     * @param  iterable<array<string, mixed>|Model>  $certificates
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function prepare(iterable $certificates, array $settings): array
    {
        $settings['tax_kind'] ??= str_starts_with((string) ($settings['form_type'] ?? ''), '25') ? 'vat' : 'income';
        $selection = Validator::make($settings, [
            'form_type' => SawtDatService::validationRules()['form_type'],
            'tax_kind' => SawtDatService::validationRules()['tax_kind'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $taxpayer = [
            ...$settings,
            'tin' => $this->identity($settings['tin'] ?? null, $settings['branch_code'] ?? null, 'taxpayer.tin'),
            'registered_name' => trim((string) ($settings['registered_name'] ?? '')) !== '' ? $settings['registered_name'] : ($settings['company_name'] ?? ''),
            'entity_type' => $settings['entity_type'] ?? 'corporation',
        ];
        $records = [];
        $seen = 0;
        foreach ($certificates as $certificate) {
            if (++$seen > 50000) {
                throw ValidationException::withMessages(['records' => 'Export at most 50,000 certificates at a time.']);
            }
            $get = fn (string $key, mixed $default = null): mixed => data_get($certificate, $key, $default);
            $status = $get('status', 'posted');
            if (in_array($status, ['draft', 'void', 'voided', 'cancelled'], true)) {
                continue;
            }
            $date = $get('date', $selection['to']);
            Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
            if ($date < $selection['from'] || $date > $selection['to']) {
                continue;
            }
            $field = 'records.'.count($records);
            // Form2307 model attributes and array keys share this adapter; no model/table is assumed.
            $records[] = [
                'source_id' => (string) $get('source_id', $get('id', 'certificate-'.$seen)),
                'date' => $date, 'status' => $status,
                'tin' => $this->identity($get('payor_tin'), $get('payor_branch_code'), $field.'.tin'),
                'entity_type' => $get('payor_entity_type', $get('entity_type', 'corporation')),
                'registered_name' => $get('payor_name', ''),
                'last_name' => $get('payor_last_name', $get('last_name', '')),
                'first_name' => $get('payor_first_name', $get('first_name', '')),
                'middle_name' => $get('payor_middle_name', $get('middle_name', '')),
                'nature_income' => $get('nature_income', ''),
                'atc' => strtoupper(trim((string) $get('atc_code', ''))),
                'income_payment' => $get('income_payment'),
                'tax_rate' => $get('tax_rate'),
                'amount_withheld' => $get('tax_withheld'),
            ];
        }
        $data = Validator::make([...$selection, 'taxpayer' => $taxpayer, 'records' => $records], SawtDatService::validationRules(), [
            'records.required' => 'No posted received Form 2307 certificates were found for the selected period.',
        ])->validate();
        $vat = $selection['tax_kind'] === 'vat';
        if ($vat !== str_starts_with($selection['form_type'], '25')) {
            throw ValidationException::withMessages(['form_type' => 'Select a form matching the Income Tax or VAT schedule.']);
        }
        foreach ($records as $index => $record) {
            if ($vat !== (bool) preg_match('/^W[VB]/', $record['atc'])) {
                throw ValidationException::withMessages(['records.'.$index.'.atc' => 'The ATC does not match the selected tax schedule.']);
            }
        }
        if (in_array($selection['form_type'], ['1701', '1702'], true)) {
            $end = CarbonImmutable::parse($selection['to']);
            $endMonth = ($settings['accounting_period'] ?? 'calendar') === 'fiscal' ? (int) ($settings['fiscal_end_month'] ?? 12) : 12;
            if ($end->month !== $endMonth || $end->toDateString() !== $end->endOfMonth()->toDateString() || $selection['from'] !== $end->subMonthsNoOverflow(11)->startOfMonth()->toDateString()) {
                throw ValidationException::withMessages(['from' => 'Annual SAWT requires the complete twelve-month tax year from company settings.']);
            }
            if ($taxpayer['entity_type'] !== ($selection['form_type'] === '1701' ? 'individual' : 'corporation')) {
                throw ValidationException::withMessages(['taxpayer.entity_type' => 'Use Form 1701 for individuals or Form 1702 for corporations.']);
            }
        }

        return $this->formatter->prepare($data);
    }

    /** @param array<string, mixed> $prepared
     * @return Generator<int, string>
     */
    public function lines(array $prepared): Generator
    {
        yield from $this->formatter->lines($prepared);
    }

    private function identity(mixed $tin, mixed $branch, string $field): string
    {
        if (! is_string($tin)) {
            throw ValidationException::withMessages([$field => 'TINs must be text to preserve leading zeroes.']);
        }
        $identity = $this->formatter->identity($tin, $field);
        $embedded = strlen(preg_replace('/\D/', '', $tin)) > 9;
        if ($branch === null || $branch === '') {
            if (! $embedded) {
                throw ValidationException::withMessages([$field => 'Enter the branch code, including 0000 for a head office.']);
            }
        } else {
            if (! is_scalar($branch) || ! preg_match('/^[0-9]{1,5}$/D', (string) $branch) || (int) $branch > 9999) {
                throw ValidationException::withMessages([$field => 'The branch must fit four digits; no digits will be truncated.']);
            }
            $normalized = str_pad((string) (int) $branch, 4, '0', STR_PAD_LEFT);
            if ($embedded && $normalized !== $identity['branch']) {
                throw ValidationException::withMessages([$field => 'The separate branch code disagrees with the branch in the TIN.']);
            }
            $identity['branch'] = $normalized;
        }

        return $identity['tin'].$identity['branch'];
    }
}
