<?php

namespace App\Services\Tax;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\TaxCode;
use App\TeamPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxReturnService
{
    public function __construct(private readonly TaxReturnCatalog $catalog, private readonly BirVatLedgerMapper $vatMapper, private readonly BirExporterFactory $exporters) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(Company $company, array $input): array
    {
        return $company->run(function () use ($company, $input): array {
            $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
            $definition = TaxReturnCatalog::forms()[$input['form']];
            $period = $this->catalog->period($input['form'], (int) $input['year'], (int) $input['period'], $profile);
            abort_unless($period['applicable'], 422, $period['reason']);
            $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
            $sources = [];
            $gross = $withheld = 0;
            $details = [];
            $withholdingRows = [];
            $vatLedger = $input['form'] === '2550Q' ? $this->vatMapper->map($ledger?->state['sales'] ?? [], $ledger?->state['purchases'] ?? [], $period) : null;
            $records = match ($definition['source']) {
                'payroll' => $ledger?->payroll ?? [],
                'expanded' => $ledger?->state['wtpCertificates'] ?? [],
                default => [],
            };
            $seen = [];
            $atcs = $definition['source'] === 'expanded' ? array_fill_keys(array_column(TaxCode::atcCatalog($company->workspaceKey()), 'code'), true) : [];
            foreach ($records as $record) {
                if (($record['status'] ?? '') !== 'posted' || ($record['date'] ?? '') < $period['from'] || ($record['date'] ?? '') > $period['to']) {
                    continue;
                }
                $id = (string) ($record['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    throw ValidationException::withMessages(['sources' => 'A saved source is missing its unique ID or appears more than once. Review Sheets.']);
                }
                $seen[$id] = true;
                if ($definition['source'] === 'payroll') {
                    if (empty($record['employees'])) {
                        throw ValidationException::withMessages(['sources' => 'A posted payroll source is missing its employee details.']);
                    }
                    foreach ($record['employees'] ?? [] as $employee) {
                        foreach (['basic', 'taxableAllowance', 'deMinimis', 'benefits'] as $field) {
                            $value = $this->money($employee[$field] ?? '0');
                            $gross += $value;
                            $details[$field] = ($details[$field] ?? 0) + $value;
                        }
                        $withheld += $this->money($employee['withholding'] ?? null);
                    }
                } else {
                    $code = strtoupper(trim((string) ($record['ewtCode'] ?? '')));
                    if (! preg_match('/^W[IC][0-9]{3}\b/', $code)) {
                        if ((float) ($record['ewt'] ?? 0) > 0) {
                            throw ValidationException::withMessages(['sources' => 'Correct the EWT ATC on '.($record['number'] ?? $id).'.']);
                        }

                        continue;
                    }
                    $base = $record['taxBase'] ?? null;
                    if (! isset($atcs[substr($code, 0, 5)])) {
                        throw ValidationException::withMessages(['sources' => 'An issued certificate uses an unknown expanded withholding ATC. Review Masters.']);
                    }
                    if (! is_int($base) || $base <= 0 || $base > 99999999999) {
                        throw ValidationException::withMessages(['sources' => 'Correct the saved withholding certificate tax base.']);
                    }
                    $rate = $this->rate($record['ewt'] ?? null);
                    if ($rate <= 0 || $rate > 1000000) {
                        throw ValidationException::withMessages(['sources' => 'Correct the saved expanded withholding rate.']);
                    }
                    $tax = intdiv($base * $rate + 500000, 1000000);
                    $withholdingRows[] = ['atc' => substr($code, 0, 5), 'base' => $base, 'rate' => $rate, 'withheld' => $tax];
                    $gross += $base;
                    $withheld += $tax;
                    $atc = substr($code, 0, 5);
                    $details[$atc] ??= ['base' => 0, 'withheld' => 0];
                    $details[$atc]['base'] += $base;
                    $details[$atc]['withheld'] += $tax;
                }
                $sources[] = ['id' => $id, 'number' => $record['number'] ?? '', 'date' => $record['date'], 'record' => $record];
            }
            $issues = [];
            $tin = preg_replace('/\D/', '', (string) ($profile['tin'] ?? ''));
            if (! preg_match('/^(?:[0-9]{9}|[0-9]{12,14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3,5})?)$/D', (string) ($profile['tin'] ?? '')) || substr($tin, 0, 9) === '000000000') {
                $issues[] = 'Enter a real taxpayer TIN in company settings.';
            }
            if (strlen($tin) === 9 && ! preg_match('/^[0-9]{5}$/D', (string) ($profile['branch_code'] ?? ''))) {
                $issues[] = 'Enter the five-digit taxpayer branch code in company settings.';
            }
            if (trim((string) ($profile['company_name'] ?? '')) === '' || ! preg_match('/^(?:[0-9]{3}|[0-9]{2}[A-Z])$/iD', (string) ($profile['rdo'] ?? '')) || ($profile['rdo'] ?? '') === '000') {
                $issues[] = 'Complete the registered name and RDO in company settings.';
            }
            $manual = $definition['source'] === 'manual';
            $warnings = ['Confirm the form and filing obligations against the company Certificate of Registration.', 'This is a preparation worksheet. No eBIRForms XML validation, electronic submission or payment has occurred.'];
            if ($manual) {
                $warnings[] = 'Automatic tax computation is unavailable for this form. Enter the tax due from a separately reviewed computation; it will not be inferred as zero.';
            } elseif ($sources === []) {
                $warnings[] = 'No posted source records found. Confirm a nil return is appropriate before saving.';
            }
            if ($definition['source'] === 'expanded') {
                $warnings[] = 'Uses posted issued 2307 certificates only. Reconcile purchases, payments and Journal adjustments to ensure all withholding is captured.';
            }
            if ($definition['source'] === 'payroll') {
                $warnings[] = 'Review non-taxable compensation, benefit caps and minimum-wage-earner classifications separately; this summary uses actual saved payroll withholding.';
            }
            $info = $definition['category'] === 'Information return';
            $hash = hash('sha256', json_encode([$input['form'], $period, $profile, $sources, $definition, $gross, $withheld, $details, $vatLedger, $withholdingRows, 'preparation-2026-11-xml-v1'], JSON_THROW_ON_ERROR));

            return [
                'form' => $input['form'], 'definition' => $definition, 'period' => [...$period, 'year' => (int) $input['year'], 'quarter' => (int) $input['period']],
                'taxpayer' => array_intersect_key($profile, array_flip(['company_name', 'registered_name', 'tin', 'branch_code', 'rdo', 'entity_type', 'building', 'street', 'barangay', 'city', 'province', 'zip_code', 'contact_number', 'contact_email', 'agent_category', 'taxpayer_size', 'accounting_period', 'fiscal_end_month'])), 'ledger_revision' => (int) ($ledger?->revision ?? 0),
                'source_hash' => $hash, 'source_count' => count($sources),
                'sources' => array_map(fn (array $row): array => array_diff_key($row, ['record' => true]), $sources),
                'source_totals' => ['gross' => $gross, 'withheld' => $withheld, 'details' => $details],
                'tax_before_credits' => $manual ? null : ($info ? 0 : $withheld),
                'manual' => $manual, 'information_return' => $info, 'issues' => $issues, 'warnings' => $warnings,
                'rules_version' => 'preparation-2026-11-xml-v1', 'xml_available' => false,
                'xml_capable' => in_array($input['form'], ['2550Q', '1601-EQ'], true), 'xml_vat_ledger' => $vatLedger, 'withholding_rows' => $withholdingRows,
            ];
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepare(Company $company, int $actorId, array $input): array
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($company, $actorId, $input): array {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $member = $company->users()->where('users.id', $actorId)->where('registration_status', 'approved')->wherePivot('is_suspended', false)->first();
            abort_unless($member && in_array('tax-reports', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403, 'Your current role does not allow tax preparation.');

            return $company->run(function () use ($company, $actorId, $input): array {
                return DB::transaction(function () use ($company, $actorId, $input): array {
                    $payloadHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
                    $existing = DB::table('tax_return_preparations')->where('company_id', $company->id)->where('request_id', $input['request_id'])->first();
                    if ($existing) {
                        abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This save request was already used with different values.');

                        return ['id' => $existing->id, 'replayed' => true];
                    }
                    $snapshot = $this->preview($company, $input);
                    abort_unless($snapshot['ledger_revision'] === (int) $input['ledger_revision'] && hash_equals($snapshot['source_hash'], $input['source_hash']), 409, 'Source records or company settings changed. Review the latest calculation before saving.');
                    if ($snapshot['issues'] !== []) {
                        throw ValidationException::withMessages(['settings' => $snapshot['issues']]);
                    }
                    if ($snapshot['manual'] && ($input['reviewed_tax'] ?? null) === null) {
                        throw ValidationException::withMessages(['reviewed_tax' => 'Enter the tax due from the separately reviewed computation, including an explicit 0.00 when appropriate.']);
                    }
                    $adjustments = [];
                    foreach (['additional_tax', 'credits', 'surcharge', 'interest', 'compromise'] as $field) {
                        $adjustments[$field] = $this->money($input[$field] ?? '0');
                    }
                    if ($snapshot['information_return'] && array_sum($adjustments) !== 0) {
                        throw ValidationException::withMessages(['additional_tax' => 'Information returns do not create a tax payment. Remove tax adjustments.']);
                    }
                    $reviewedTax = (string) ($input['reviewed_tax'] ?? '');
                    $negativeVat = $input['form'] === '2550Q' && str_starts_with($reviewedTax, '-');
                    $tax = $snapshot['manual'] ? ($negativeVat ? -$this->money(substr($reviewedTax, 1)) : $this->money($reviewedTax)) : $snapshot['tax_before_credits'];
                    $balance = $tax + $adjustments['additional_tax'] - $adjustments['credits'];
                    $net = $balance + $adjustments['surcharge'] + $adjustments['interest'] + $adjustments['compromise'];
                    $snapshot['calculation'] = ['tax' => $tax, ...$adjustments, 'credit_balance' => max(0, -$net), 'payable' => max(0, $net)];
                    $snapshot['review_note'] = $input['review_note'];
                    if ($input['xml_requested'] ?? false) {
                        if ($snapshot['xml_vat_ledger']['issues'] ?? []) {
                            throw ValidationException::withMessages(['bir_xml.sources' => $snapshot['xml_vat_ledger']['issues']]);
                        }
                        $options = ['vat_reviewed' => (bool) ($input['xml_vat_reviewed'] ?? false), 'overpayment_action' => $input['xml_overpayment_action'] ?? ''];
                        foreach (['carryover_input', 'remittance_month1', 'remittance_month2', 'prior_overremittance'] as $key) {
                            $options[$key] = $this->money($input['xml_'.$key] ?? '0');
                        }
                        $snapshot['bir_xml'] = $this->exporters->make($input['form'])->prepare([
                            'taxpayer' => $snapshot['taxpayer'], 'period' => $snapshot['period'], 'vat' => $snapshot['xml_vat_ledger']['values'] ?? [],
                            'withholding' => $snapshot['withholding_rows'], 'options' => $options, 'calculation' => $snapshot['calculation'], 'prepared_at' => now('Asia/Manila')->toDateString(),
                        ]);
                        $snapshot['xml_available'] = true;
                    }
                    $id = DB::table('tax_return_preparations')->insertGetId([
                        'company_id' => $company->id, 'request_id' => $input['request_id'], 'prepared_by' => $actorId,
                        'form' => $input['form'], 'period_from' => $snapshot['period']['from'], 'period_to' => $snapshot['period']['to'],
                        'ledger_revision' => $snapshot['ledger_revision'], 'source_hash' => $snapshot['source_hash'], 'payload_hash' => $payloadHash,
                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now(),
                    ]);

                    return ['id' => $id, 'replayed' => false];
                });
            });
        });
    }

    public function money(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a non-negative peso amount with at most two decimals; do not include commas.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function rate(mixed $value): int
    {
        if (! is_scalar($value) || ! preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,4})?$/D', (string) $value)) {
            throw ValidationException::withMessages(['sources' => 'Use a saved withholding rate with at most four decimals.']);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');

        return (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
    }
}
