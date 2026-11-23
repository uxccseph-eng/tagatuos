<?php

namespace App\Services\Tax;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompensationSummaryService
{
    public function __construct(private TaxReturnService $returns, private TaxReturnCatalog $catalog, private TaxReturnWorkspace $workspace, private BirPayrollLedgerMapper $payroll) {}

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function build(Company $company, array $profile, int $year): array
    {
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $events = DB::table('compensation_summary_events')->where('company_id', $company->id)->where('year', $year)->orderBy('id')->get();
        foreach ($events as $event) {
            $event->data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
        }
        $months = [];
        foreach (range(1, 12) as $month) {
            $period = $this->catalog->period('1601-C', $year, $month, $profile);
            $current = null;
            $mapped = $this->payroll->map($ledger?->payroll ?? [], $period);
            $values = $mapped['values'];
            $issues = $mapped['issues'];
            $hasPosted = collect($ledger?->payroll ?? [])->contains(fn (mixed $run): bool => is_array($run) && ($run['status'] ?? '') === 'posted' && ($run['date'] ?? '') >= $period['from'] && ($run['date'] ?? '') <= $period['to']);
            $warnings = $hasPosted ? $mapped['warnings'] : [];
            if ($issues === []) {
                try {
                    $current = $this->returns->preview($company, ['form' => '1601-C', 'year' => $year, 'period' => $month]);
                } catch (ValidationException $exception) {
                    $issues = array_merge(...array_values($exception->errors()));
                }
            }
            if (! $ledger) {
                $issues[] = 'Initialize and review the company books before treating this as a nil payroll month.';
            }
            $record = $this->workspace->preparations($company)->where('form', '1601-C')->where('period_from', $period['from'])->where('period_to', $period['to'])->latest('id')->first();
            $filing = DB::table('tax_return_filing_records')->where('company_id', $company->id)->where('form', '1601-C')->where('period_from', $period['from'])->where('period_to', $period['to'])->whereNotNull('filed_on')->exists();
            $snapshot = $record ? json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
            $calculation = $snapshot['calculation'] ?? [];
            $matches = $current && $record && hash_equals($record->source_hash, $current['source_hash']);
            $gross = $values['total_compensation'] ?? 0;
            $exempt = array_sum(array_intersect_key($values, array_flip(['minimum_wage', 'mwe_premium', 'exempt_benefits', 'de_minimis', 'mandatory_contributions', 'other_exempt'])));
            $notSubject = $matches ? ($snapshot['xml_payroll_review']['values']['no_withholding_compensation'] ?? 0) : 0;
            if ($exempt > $gross || ! is_int($notSubject) || $notSubject < 0 || $notSubject > $gross - $exempt) {
                $issues[] = 'The compensation classifications exceed posted compensation. Review the payroll and saved 1601-C.';
            }
            $adjustment = (int) ($calculation['additional_tax'] ?? 0);
            if (isset($snapshot['bir_xml']['fields']['frm1601c:txtTax26'])) {
                $adjustment = IncomeTaxSummaryService::cents(str_replace(',', '', $snapshot['bir_xml']['fields']['frm1601c:txtTax26']));
            }
            $penalties = (int) ($calculation['surcharge'] ?? 0) + (int) ($calculation['interest'] ?? 0) + (int) ($calculation['compromise'] ?? 0);
            $payments = $events->where('action', 'payment')->filter(fn (object $event): bool => (int) $event->month === $month);
            $paid = $payments->sum(fn (object $event): int => (int) $event->amount - (int) ($event->data['penalty_cents'] ?? 0));
            $paidPenalties = $payments->sum(fn (object $event): int => (int) ($event->data['penalty_cents'] ?? 0));
            $tax = $issues === [] ? ($values['tax_withheld'] ?? 0) : null;
            $due = $tax === null ? null : $tax + $adjustment;
            $net = $due === null ? null : $due - $paid;
            $amounts = ['gross' => $issues === [] ? $gross : null, 'exempt' => $issues === [] ? $exempt : null, 'taxable' => $issues === [] ? $gross - $exempt : null, 'not_subject' => $issues === [] ? $notSubject : null, 'net_taxable' => $issues === [] ? $gross - $exempt - $notSubject : null, 'tax' => $tax, 'adjustment' => $adjustment, 'due' => $due, 'paid' => $paid, 'paid_penalties' => $paidPenalties, 'net' => $net, 'penalties' => $penalties, 'total' => $net === null ? null : $net + max(0, $penalties - $paidPenalties), 'open' => $net === null ? null : max(0, $net) + max(0, $penalties - $paidPenalties), 'excess' => $net === null ? null : max(0, -$net)];
            if ($record && (int) ($calculation['credits'] ?? 0) !== $paid) {
                $warnings[] = 'Saved return credits differ from tagged tax payments. Credits alone are not evidence of payment.';
            }
            $paymentStatus = $due === null ? 'Review sources' : ($amounts['open'] > 0 ? ($paid + $paidPenalties > 0 ? 'Part paid' : 'Unpaid') : ($amounts['excess'] > 0 ? 'Over-remitted' : ($paid + $paidPenalties > 0 ? 'Paid' : 'No liability recorded')));
            $months[$month] = ['period' => $period, 'record' => $record, 'filed' => $record?->status === 'filed' || ($filing && ! ($record?->amended ?? false)), 'reconciliation' => ! $record ? 'Not generated' : ($matches && $issues === [] ? 'Matches saved sources' : 'Sources changed — review return'), 'saved_payable' => $calculation['payable'] ?? null, 'issues' => $issues, 'warnings' => array_values(array_unique($warnings)), 'source_count' => $current['source_count'] ?? 0, 'source_hash' => $current['source_hash'] ?? null, 'payment_status' => $paymentStatus, 'amounts' => $amounts];
        }
        $annual = [];
        foreach (array_keys($months[1]['amounts']) as $key) {
            $values = array_column(array_column($months, 'amounts'), $key);
            $annual[$key] = in_array(null, $values, true) ? null : array_sum($values);
        }

        return ['year' => $year, 'months' => $months, 'annual' => $annual, 'events' => $events, 'revision' => (int) ($ledger?->revision ?? 0), 'accounts' => ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->orderBy('code')->get(['code', 'name', 'type', 'is_cash_bank'])->toArray(), 'journals' => DB::table('journal_entries')->where('company_id', $company->id)->where('sheet', 'journalEntries')->where('status', 'posted')->orderByDesc('transaction_date')->get(['source_key', 'number', 'transaction_date'])];
    }
}
