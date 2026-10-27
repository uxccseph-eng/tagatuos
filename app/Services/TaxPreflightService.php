<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\TaxCode;
use App\TeamPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxPreflightService
{
    public const VERSION = 'preflight-2026-10-v1';

    /** @param array<string, string> $selection
     * @return array<string, mixed>
     */
    public function check(Company $company, array $selection): array
    {
        $snapshot = app(CompanyLedgerService::class)->read($company);
        $profile = CompanySetting::query()->where('workspace_key', $company->workspaceKey())->first()?->profile ?? [];
        $parties = Party::query()->where('workspace_key', $company->workspaceKey())->orderBy('id')->get()->toArray();
        $accounts = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->orderBy('id')->get()->keyBy('code');
        $taxes = TaxCode::query()->where('workspace_key', $company->workspaceKey())->orderBy('id')->get()->keyBy('code');
        $atcs = collect(TaxCode::atcCatalog($company->workspaceKey()))->keyBy('code');
        $journals = collect($snapshot['journals'])->keyBy('id');
        $schedule = $selection['schedule'];
        $sheet = match ($schedule) {
            'relief-sales' => 'sales', 'relief-purchases' => 'purchases', 'sawt' => 'cwtCertificates', default => 'wtpCertificates'
        };
        $target = match ($sheet) {
            'cwtCertificates' => 'cwt', 'wtpCertificates' => 'wtp', default => $sheet
        };
        $issues = [];
        $add = function (string $code, string $message, string $target = 'settings', ?array $record = null, string $severity = 'error') use (&$issues): void {
            $issues[] = ['code' => $code, 'message' => $message, 'target' => $target, 'number' => $record['number'] ?? null, 'id' => isset($record['id']) ? (string) $record['id'] : null, 'date' => $record['date'] ?? null, 'severity' => $severity];
        };
        if (! $this->validTin((string) ($profile['tin'] ?? ''))) {
            $add('company_tin', 'Company TIN is missing, all zero, or has an unsupported length.');
        }
        if (strlen(preg_replace('/\D/', '', (string) ($profile['tin'] ?? ''))) === 9 && ! preg_match('/^[0-9]{5}$/D', (string) ($profile['branch_code'] ?? ''))) {
            $add('company_branch', 'Enter the company five-digit branch code separately from its nine-digit TIN.');
        }
        if (trim((string) ($profile['company_name'] ?? $profile['registered_name'] ?? '')) === '') {
            $add('company_name', 'Company registered name is missing.');
        }
        if (! preg_match('/^(?:[0-9]{3}|[0-9]{2}[A-Z])$/iD', (string) ($profile['rdo'] ?? '')) || ($profile['rdo'] ?? '') === '000') {
            $add('company_rdo', 'Review the company RDO code in BIR settings.');
        }
        if (str_starts_with($schedule, 'relief-') && (trim((string) ($profile['dat_street'] ?? implode(' ', array_filter([$profile['building'] ?? '', $profile['street'] ?? '', $profile['barangay'] ?? ''])))) === '' || trim((string) ($profile['dat_city'] ?? implode(' ', array_filter([$profile['city'] ?? '', $profile['province'] ?? '', $profile['zip_code'] ?? ''])))) === '')) {
            $add('company_address', 'Complete the company registered street and city / province details for RELIEF.');
        }
        $from = $selection['from'];
        $to = $selection['to'];
        $end = CarbonImmutable::parse($to);
        $annual = $schedule === 'sawt' && in_array($selection['form'] ?? '', ['1701', '1702'], true);
        if ($schedule === 'qap-ewt' && ($selection['form'] ?? '') === '1604E' && ($from !== $end->year.'-01-01' || $to !== $end->year.'-12-31')) {
            $add('period', 'Annual 1604E needs January through December of the selected year.');
        }
        if ($annual) {
            $endMonth = ($profile['accounting_period'] ?? '') === 'fiscal' ? (int) ($profile['fiscal_end_month'] ?? 12) : 12;
            if ($end->month !== $endMonth || $to !== $end->endOfMonth()->toDateString() || $from !== $end->subMonthsNoOverflow(11)->startOfMonth()->toDateString()) {
                $add('period', 'Annual SAWT needs the complete company tax year.');
            }
            if (($selection['form'] === '1701') !== (($profile['entity_type'] ?? '') === 'individual')) {
                $add('form', 'Use annual 1701 for an individual and 1702 for a non-individual.');
            }
        } elseif ($schedule === 'qap-ewt' && ($selection['form'] ?? '') !== '1604E' && ($from !== $end->startOfQuarter()->toDateString() || $to !== $end->endOfQuarter()->toDateString())) {
            $add('period', 'QAP must use one complete calendar quarter.');
        } elseif (str_starts_with($schedule, 'relief-') && ($from !== CarbonImmutable::parse($from)->startOfMonth()->toDateString() || $to !== $end->endOfMonth()->toDateString())) {
            $add('period', 'RELIEF needs complete monthly reporting windows.');
        }
        $records = array_values(array_filter($snapshot['state'][$sheet] ?? [], function (array $record) use ($from, $to, $selection, $sheet): bool {
            if (($record['status'] ?? '') !== 'posted' || ($record['date'] ?? '') < $from || ($record['date'] ?? '') > $to) {
                return false;
            }

            return $sheet !== 'cwtCertificates' || (($selection['tax_kind'] ?? 'income') === 'vat') === (bool) preg_match('/^W[VB]/i', (string) ($record['atc'] ?? ''));
        }));
        if ($sheet === 'purchases') {
            $records = array_values(array_filter($records, fn (array $record): bool => empty($record['isImportation']) && empty($record['importation']) && empty($record['customs']) && ! str_contains(strtoupper((string) ($record['tax'] ?? '')), 'IMPORT')));
        }
        if ($sheet === 'wtpCertificates') {
            $records = array_values(array_filter($records, fn (array $record): bool => (float) ($record['ewt'] ?? 0) > 0 || preg_match('/^W[IC][0-9]{3}/i', (string) ($record['ewtCode'] ?? $record['atc'] ?? ''))));
        }
        if (! $snapshot['initialized']) {
            $add('database', 'Finish the company database transfer / recovery in Sheets first.', $target);
        }
        if ($records === []) {
            $add('empty', 'No posted source records. Draft, void, and out-of-period entries are excluded.', $target);
        }
        $seen = [];
        $totalBase = $totalTax = $journalChecks = 0;
        foreach ($records as $record) {
            $name = trim((string) ($record['customer'] ?? $record['supplier'] ?? ''));
            $matches = array_values(array_filter($parties, fn (array $party): bool => ! $party['archived'] && (! empty($record['partyId']) ? (string) $party['id'] === (string) $record['partyId'] : mb_strtolower(trim($party['name'])) === mb_strtolower($name))));
            $party = count($matches) === 1 ? $matches[0] : [];
            if ($name === '' || $party === []) {
                $add('party', 'Match this customer / supplier to one active company Masters record.', 'parties', $record);
            }
            $tin = (string) (($record['customerTin'] ?? $record['supplierTin'] ?? $record['tin'] ?? '') ?: ($party['tin'] ?? ''));
            $various = $schedule === 'relief-sales' && strtoupper($name) === 'VARIOUS CUSTOMERS';
            if (! $various && ! $this->validTin($tin)) {
                $add('party_tin', 'Missing or invalid party TIN. Correct Masters and review the source snapshot.', 'parties', $record);
            }
            if ($schedule === 'sawt' && (int) substr(preg_replace('/\D/', '', $tin), 9) > 9999) {
                $add('branch_width', 'This branch does not fit the published four-digit SAWT field. Verify the branch; never silently remove significant digits.', 'parties', $record);
            }
            $address = ($record['customerAddress'] ?? $record['supplierAddress'] ?? $record['customer_address'] ?? $record['supplier_address'] ?? '') ?: ($party['address'] ?? '');
            if (! $various && trim((string) $address) === '') {
                $add('party_address', 'Registered address is missing.', 'parties', $record);
            }
            if (($party['entity_type'] ?? '') === 'individual' && (empty($party['last_name']) || empty($party['first_name']))) {
                $add('individual_name', 'Individual needs separate last and first names.', 'parties', $record);
            }
            $reference = trim((string) ($record['certificate'] ?? $record['reference'] ?? ''));
            if ($reference !== '') {
                $key = mb_strtolower($name.'|'.$reference);
                if (isset($seen[$key])) {
                    $add('duplicate', 'Possible duplicate reference '.$reference.'; compare with '.$seen[$key].'.', $target, $record);
                } $seen[$key] = $record['number'] ?? '';
            }
            $journalSheet = $sheet;
            $journalId = $record['id'];
            if (in_array($sheet, ['sales', 'purchases'], true)) {
                $account = $accounts->get(explode(' · ', (string) ($record['account'] ?? ''))[0]);
                if (! $account || $account->archived) {
                    $add('account', 'Account is missing or archived.', 'accounts', $record);
                }
                $gross = $record['gross'] ?? null;
                if (! is_int($gross) || $gross <= 0) {
                    $add('amount', 'Gross amount must be positive whole centavos.', $target, $record);

                    continue;
                }
                $parts = explode('|', (string) ($record['tax'] ?? ''));
                $code = count($parts) === 4 ? $taxes->get($parts[1]) : null;
                $legacy = in_array($record['tax'] ?? '', ['12% VAT', 'VAT (12%)'], true);
                $knownLegacy = in_array($record['tax'] ?? '', ['Non-VAT', 'VAT Exempt', 'Exempt', 'Zero-Rated (0%)', 'Zero-Rated'], true);
                if (! $legacy && ! $knownLegacy && (! $code || $code->archived || $code->kind !== ($parts[2] ?? ''))) {
                    $add('tax_code', 'Tax code / classification needs review in Masters.', $target, $record);
                }
                $rate = $legacy ? 120000 : ($code?->kind === 'VAT' ? $this->rate((string) $code->rate, 4) : 0);
                $base = $rate ? intdiv($gross * 1000000 + intdiv(1000000 + $rate, 2), 1000000 + $rate) : $gross;
                $tax = $gross - $base;
                $taxAccount = $sheet === 'sales' ? '2210' : '2110';
                if ($rate && ! in_array($account?->relief_category, $sheet === 'sales' ? ['vatable_sales'] : ['services', 'goods', 'capital_goods'], true)) {
                    $add('category', 'Set the VAT RELIEF category in Chart of Accounts.', 'accounts', $record);
                }
            } else {
                $source = $sheet === 'cwtCertificates' ? collect($snapshot['state']['sales'])->first(fn (array $sale): bool => ! empty($record['saleId']) ? (string) $sale['id'] === (string) $record['saleId'] : ($sale['number'] ?? '') === ($record['invoice'] ?? '')) : collect($snapshot['state']['purchases'])->firstWhere('id', $record['billId'] ?? null);
                $sourceAccount = $accounts->get(explode(' · ', (string) ($source['account'] ?? ''))[0]);
                $rawAtc = collect([$record['atc'] ?? '', $record['ewtCode'] ?? '', $source['ewtCode'] ?? '', $sourceAccount?->ewt_atc_code ?? ''])->first(fn (mixed $value): bool => trim((string) $value) !== '') ?? '';
                preg_match('/^W[ICVB][0-9]{3}/i', trim((string) $rawAtc), $match);
                $atc = $atcs->get(strtoupper($match[0] ?? ''));
                if (! $atc || ($sheet === 'wtpCertificates' && ! preg_match('/^W[IC]/', $match[0] ?? ''))) {
                    $add('atc', 'ATC is missing or does not match Masters and the schedule.', $target, $record);
                }
                $base = $record['taxBase'] ?? 0;
                $rate = $this->rate((string) ($sheet === 'cwtCertificates' ? ($record['rate'] ?? $record['withholdingRate'] ?? $atc['rate'] ?? 0) : (($record['ewt'] ?? 0) ?: (($source['ewt'] ?? 0) ?: ($atc['rate'] ?? 0)))));
                $tax = $sheet === 'cwtCertificates' ? ($record['amount'] ?? 0) : (is_int($base) ? intdiv($base * $rate + 5000, 10000) : 0);
                if (! is_int($base) || ! is_int($tax) || $base <= 0 || $tax <= 0 || $tax > $base) {
                    $add('amount', 'Review certificate base and withholding amounts.', $target, $record);

                    continue;
                }
                if (abs(intdiv($base * $rate + 5000, 10000) - $tax) > 1 || ($atc && $rate !== $this->rate((string) $atc['rate']))) {
                    $add('rate_amount', 'Base, actual tax and Masters ATC rate do not reconcile within one centavo.', $target, $record);
                }
                $taxAccount = $sheet === 'cwtCertificates' ? explode(' · ', (string) ($record['offset'] ?? '1150'))[0] : '2140';
                if ($source && ($source['status'] ?? '') === 'posted') {
                    if ($sheet === 'wtpCertificates') {
                        $journalSheet = 'purchases';
                        $journalId = $source['id'];
                        if (($source['date'] ?? '') < $from || ($source['date'] ?? '') > $to) {
                            $add('cross_period', 'Linked purchase is outside this certificate period. Review the timing difference.', 'purchases', $source, 'warning');
                        }
                    }
                } elseif ($sheet === 'cwtCertificates' || ! empty($record['billId'])) {
                    $add('source', 'Source invoice / purchase is missing or no longer posted.', $target, $record);
                }
            }
            $totalBase += $base;
            $totalTax += $tax;
            $journal = $journals->get($journalSheet.':'.$journalId);
            if (! $journal) {
                $add('journal_missing', 'No posted Books voucher for this source. Review the database transfer.', $target, $record);

                continue;
            }
            $lines = collect($journal['lines']);
            $bookTax = $lines->filter(fn (array $line): bool => str_starts_with($line['account'], $taxAccount.' · '))->sum(fn (array $line): int => in_array($sheet, ['sales', 'wtpCertificates'], true) ? $line['credit'] - $line['debit'] : $line['debit'] - $line['credit']);
            $baseMatches = true;
            if (in_array($sheet, ['sales', 'purchases'], true) && $account) {
                $bookBase = $lines->filter(fn (array $line): bool => str_starts_with($line['account'], $account->code.' · '))->sum(fn (array $line): int => $sheet === 'sales' ? $line['credit'] - $line['debit'] : $line['debit'] - $line['credit']);
                $baseMatches = $bookBase === $base;
            }
            if (! $baseMatches || $lines->sum('debit') !== $lines->sum('credit') || $bookTax !== $tax || ($journalSheet === $sheet && $journal['date'] !== $record['date'])) {
                $add('journal_difference', 'Source base / tax / date differs from Books, or the voucher is unbalanced.', $target, $record);
            } else {
                $journalChecks++;
            }
        }
        if ($schedule === 'qap-ewt') {
            $add('layout_qap', 'Filing blocker: current H/D DAT layout differs from published QAP HQAP, schedule detail and control records.', 'layout');
        }
        if ($schedule === 'sawt') {
            $add('layout_sawt', 'Filing blocker: sample-based control totals need reconciliation with published Annex A control fields.', 'layout');
        }
        $add('external_validation', 'BIR validator acceptance and electronic submission have not been verified by this system.', 'layout', null, 'warning');
        $hash = hash('sha256', json_encode([self::VERSION, $selection, $snapshot, $profile, $parties, $accounts->toArray(), $taxes->toArray(), $atcs->toArray()], JSON_THROW_ON_ERROR));

        return ['schedule' => $schedule, 'from' => $from, 'to' => $to, 'form' => $selection['form'] ?? '', 'rules_version' => self::VERSION, 'snapshot_hash' => $hash, 'revision' => $snapshot['revision'], 'status' => collect($issues)->contains('severity', 'error') ? 'Review needed' : 'Checks passed', 'issues' => $issues, 'source_count' => count($records), 'reconciled_count' => $journalChecks, 'base_centavos' => $totalBase, 'tax_centavos' => $totalTax, 'validator_status' => 'Not verified', 'checked_at' => now()->toIso8601String()];
    }

    private function validTin(string $value): bool
    {
        if (! preg_match('/^(?:[0-9]{9}|[0-9]{12,14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3,5})?)$/D', trim($value))) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $value);

        return in_array(strlen($digits), [9, 12, 13, 14], true) && (bool) preg_match('/[1-9]/', substr($digits, 0, 9));
    }

    /** @param array<string, string> $selection
     * @param  iterable<string>  $bytes
     */
    public function recordExport(Request $request, array $selection, string $filename, iterable $bytes): void
    {
        $company = $request->attributes->get('active_company');
        if (! $company || ! $request->user()) {
            return;
        }
        $digest = hash_init('sha256');
        foreach ($bytes as $chunk) {
            hash_update($digest, $chunk);
        }
        $fileHash = hash_final($digest);
        DB::transaction(function () use ($company, $selection, $request, $filename, $fileHash): void {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $member = $company->users()->where('users.id', $request->user()->id)->where('registration_status', 'approved')->wherePivot('is_suspended', false)->first();
            abort_unless($member && in_array('tax-reports', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403);
            abort_unless($request->header('X-Ledger-Revision') === (string) (CompanyLedger::query()->where('company_id', $company->id)->value('revision') ?? 0), 409, 'Company records changed during export. Reload before generating another file.');
            $result = $this->check($company, ['form' => '', 'tax_kind' => 'income', ...$selection]);
            DB::table('tax_preflight_reviews')->insert([
                'company_id' => $company->id, 'user_id' => $request->user()->id, 'schedule' => $result['schedule'], 'period_from' => $result['from'], 'period_to' => $result['to'], 'form' => $result['form'],
                'snapshot_hash' => $result['snapshot_hash'], 'rules_version' => self::VERSION, 'action' => 'export_generated', 'filename' => $filename, 'file_hash' => $fileHash,
                'summary' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        });
    }

    private function rate(string $value, int $precision = 2): int
    {
        if (! preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,4}))?$/D', $value, $match)) {
            return 0;
        }

        return (int) $match[1] * (10 ** $precision) + (int) str_pad(substr($match[2] ?? '', 0, $precision), $precision, '0');
    }
}
