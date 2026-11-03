<?php

namespace App\Services\Tax;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\TaxCode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanySawtSource
{
    /** @param array{form_type: string, tax_kind: string, from: string, to: string} $selection
     * @return array{settings: array<string, mixed>, records: list<array<string, mixed>>, revision: int}
     */
    public function read(Company $company, array $selection): array
    {
        return $company->run(function () use ($company, $selection): array {
            $workspace = $company->workspaceKey();
            // Taxpayer identity comes from the selected client's saved settings, never the download payload.
            $profile = CompanySetting::query()->where('company_id', $company->id)->where('workspace_key', $workspace)->firstOrFail()->profile;
            $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
            // This app persists received Form 2307s in company_ledgers.state.cwtCertificates.
            $certificates = $ledger?->state['cwtCertificates'] ?? [];
            $parties = Party::query()->where('company_id', $company->id)->where('workspace_key', $workspace)
                ->whereIn('classification', ['Customer', 'Both'])->get();
            $byId = $parties->keyBy(fn (Party $party): string => (string) $party->id);
            $byName = $parties->groupBy(fn (Party $party): string => mb_strtolower(trim($party->name)));
            $catalog = array_fill_keys(array_column(TaxCode::atcCatalog($workspace), 'code'), true);
            $records = [];
            foreach ($certificates as $index => $certificate) {
                $field = 'certificates.'.$index;
                if (($certificate['status'] ?? '') !== 'posted') {
                    continue;
                }
                $date = $certificate['date'] ?? null;
                Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
                if ($date < $selection['from'] || $date > $selection['to']) {
                    continue;
                }
                $rawAtc = trim((string) ($certificate['atc'] ?? ''));
                if (! preg_match('/^(W[ICVB][0-9]{3})\b/i', $rawAtc, $match) || ! isset($catalog[strtoupper($match[1])])) {
                    throw ValidationException::withMessages([$field.'.atc' => 'Correct the ATC on saved certificate '.($certificate['number'] ?? $index).'.']);
                }
                $atc = strtoupper($match[1]);
                if (($selection['tax_kind'] === 'vat') !== (bool) preg_match('/^W[VB]/', $atc)) {
                    continue;
                }
                $name = (string) ($certificate['customer'] ?? '');
                $matches = $byName->get(mb_strtolower(trim($name)));
                $party = ! empty($certificate['partyId']) ? $byId->get((string) $certificate['partyId']) : ($matches?->count() === 1 ? $matches->first() : null);
                // Masters supplies missing payor identity only; saved historical TIN/name values take precedence.
                $tin = trim((string) ($certificate['customerTin'] ?? ''));
                if ($tin === '') {
                    $tin = trim((string) ($certificate['tin'] ?? ''));
                }
                if ($tin === '') {
                    $tin = (string) ($party?->tin ?? '');
                }
                // Ledger taxBase and amount are integer centavos; the generator contract uses peso decimals.
                $base = $this->centavos($certificate['taxBase'] ?? null, $field.'.taxBase');
                $withheld = $this->centavos($certificate['amount'] ?? null, $field.'.amount');
                $rate = $certificate['rate'] ?? $certificate['withholdingRate'] ?? null;
                if ($rate === null && preg_match('/([0-9]+(?:\.[0-9]+)?)%/', $rawAtc, $rateMatch)) {
                    $rate = $rateMatch[1];
                }
                $rate ??= $this->decimal(intdiv($withheld * 10000 + intdiv($base, 2), $base));
                $records[] = [
                    'source_id' => (string) ($certificate['id'] ?? $certificate['number'] ?? ''),
                    'date' => $date, 'status' => 'posted',
                    'payor_tin' => $tin,
                    'payor_branch_code' => $certificate['payor_branch_code'] ?? null,
                    'payor_name' => $name !== '' ? $name : ($party?->name ?? ''),
                    'payor_entity_type' => $certificate['entity_type'] ?? $party?->entity_type ?? 'corporation',
                    'payor_last_name' => $certificate['last_name'] ?? $party?->last_name ?? '',
                    'payor_first_name' => $certificate['first_name'] ?? $party?->first_name ?? '',
                    'payor_middle_name' => $certificate['middle_name'] ?? $party?->middle_name ?? '',
                    'nature_income' => $certificate['nature_income'] ?? '',
                    'atc_code' => $atc, 'income_payment' => $this->decimal($base),
                    'tax_rate' => $rate, 'tax_withheld' => $this->decimal($withheld),
                ];
            }

            return ['settings' => [...$profile, ...$selection], 'records' => $records, 'revision' => (int) ($ledger?->revision ?? 0)];
        });
    }

    private function centavos(mixed $value, string $field): int
    {
        if (! is_int($value) || $value <= 0 || $value > 9999999999999) {
            throw ValidationException::withMessages([$field => 'Correct the saved certificate amounts before exporting.']);
        }

        return $value;
    }

    private function decimal(int $value): string
    {
        return intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }
}
