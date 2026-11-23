<?php

namespace App\Services\Tax;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EwtSummaryService
{
    public function __construct(private TaxReturnService $returns, private TaxReturnCatalog $catalog, private TaxReturnWorkspace $workspace) {}

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function build(Company $company, array $profile, int $year): array
    {
        $events = DB::table('ewt_summary_events')->where('company_id', $company->id)->where('year', $year)->orderBy('id')->get();
        foreach ($events as $event) {
            $event->data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
        }
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $quarters = [];
        $carry = 0;
        foreach (range(1, 4) as $quarter) {
            $period = $this->catalog->period('1601-EQ', $year, $quarter, $profile);
            $issues = [];
            $current = null;
            try {
                $current = $this->returns->preview($company, ['form' => '1601-EQ', 'year' => $year, 'period' => $quarter]);
                $tax = $current['tax_before_credits'];
            } catch (ValidationException $exception) {
                $tax = null;
                $issues = array_merge(...array_values($exception->errors()));
            }
            if (! $ledger) {
                $tax = null;
                $issues[] = 'Initialize and review the company books before treating this as a nil EWT computation.';
            }
            $payments = $events->where('action', 'payment')->filter(fn (object $event): bool => (int) $event->quarter === $quarter);
            $monthly = [0, 0];
            $quarterly = 0;
            $paidPenalty = 0;
            foreach ($payments as $payment) {
                $penalty = (int) ($payment->data['penalty_cents'] ?? 0);
                $paidPenalty += $penalty;
                $principal = (int) $payment->amount - $penalty;
                if ($payment->data['form'] === '0619-E') {
                    $monthly[((int) $payment->data['period'] - 1) % 3] += $principal;
                } else {
                    $quarterly += $principal;
                }
            }
            $record = $this->workspace->preparations($company)->where('form', '1601-EQ')->where('period_from', $period['from'])->where('period_to', $period['to'])->latest('id')->first();
            $filing = DB::table('tax_return_filing_records')->where('company_id', $company->id)->where('form', '1601-EQ')->where('period_from', $period['from'])->where('period_to', $period['to'])->whereNotNull('filed_on')->exists();
            $snapshot = $record ? json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
            $calculation = $snapshot['calculation'] ?? [];
            $penalties = $record ? (int) ($calculation['surcharge'] ?? 0) + (int) ($calculation['interest'] ?? 0) + (int) ($calculation['compromise'] ?? 0) : 0;
            $net = $tax === null || $carry === null ? null : $tax - array_sum($monthly) - $carry;
            $openTax = $net === null ? null : $net - $quarterly;
            $reconciliation = ! $record ? 'No saved return' : ($current && hash_equals($record->source_hash, $current['source_hash']) ? 'Matches saved sources' : 'Sources changed');
            if ($record && $net !== null && (int) ($calculation['credits'] ?? 0) !== array_sum($monthly) + $carry) {
                $issues[] = 'Saved return credits differ from the tagged remittances and calculated same-year carryover. Review the return before filing.';
            }
            $quarters[$quarter] = ['period' => $period, 'record' => $record, 'filed' => ($filing && ! ($record?->amended ?? false)) || $record?->status === 'filed', 'reconciliation' => $reconciliation, 'issues' => $issues, 'sources' => $current['sources'] ?? [], 'source_hash' => $current['source_hash'] ?? null, 'saved_payable' => $record ? ($calculation['payable'] ?? null) : null, 'amounts' => ['tax' => $tax, 'month1' => $monthly[0], 'month2' => $monthly[1], 'prior' => $carry, 'net' => $net, 'penalties' => $penalties, 'total' => $net === null ? null : $net + $penalties, 'paid' => array_sum($monthly) + $quarterly, 'quarterly' => $quarterly, 'paid_penalties' => $paidPenalty, 'open' => $openTax === null ? null : max(0, $openTax) + max(0, $penalties - $paidPenalty), 'excess' => $openTax === null ? null : max(0, -$openTax)]];
            $fields = $snapshot['bir_xml']['fields'] ?? [];
            $reserved = ($fields['frm1601EQ:ifRefund'] ?? 'false') === 'true' || ($fields['frm1601EQ:ifIssueCert'] ?? 'false') === 'true';
            $quarters[$quarter]['reserved'] = $reserved;
            $carry = $openTax === null ? null : ($reserved ? 0 : max(0, -$openTax));
        }
        $annual = [];
        foreach (['tax', 'month1', 'month2', 'penalties', 'paid', 'quarterly', 'paid_penalties'] as $key) {
            $values = array_column(array_column($quarters, 'amounts'), $key);
            $annual[$key] = in_array(null, $values, true) ? null : array_sum($values);
        }
        $annual['prior'] = null;
        $annual['net'] = $annual['tax'] === null ? null : $annual['tax'] - $annual['paid'];
        $annual['total'] = $annual['net'] === null ? null : $annual['net'] + max(0, $annual['penalties'] - $annual['paid_penalties']);
        $annual['open'] = in_array(null, array_column(array_column($quarters, 'amounts'), 'open'), true) ? null : array_sum(array_column(array_column($quarters, 'amounts'), 'open'));
        $annual['excess'] = $quarters[4]['amounts']['excess'];

        return ['year' => $year, 'quarters' => $quarters, 'annual' => $annual, 'events' => $events, 'revision' => (int) ($ledger?->revision ?? 0), 'accounts' => ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->orderBy('code')->get(['code', 'name', 'type', 'is_cash_bank'])->toArray(), 'journals' => DB::table('journal_entries')->where('company_id', $company->id)->where('sheet', 'journalEntries')->where('status', 'posted')->orderByDesc('transaction_date')->get(['source_key', 'number', 'transaction_date'])];
    }
}
