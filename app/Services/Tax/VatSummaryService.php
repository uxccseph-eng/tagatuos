<?php

namespace App\Services\Tax;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Support\Facades\DB;

class VatSummaryService
{
    public function __construct(private BirVatLedgerMapper $mapper, private TaxReturnCatalog $catalog, private TaxReturnWorkspace $workspace) {}

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function build(Company $company, array $profile, int $year): array
    {
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $state = $ledger?->state ?? [];
        $quarters = [];
        foreach (range(1, 4) as $quarter) {
            $period = $this->catalog->period('2550Q', $year, $quarter, $profile);
            $mapped = $this->mapper->map($state['sales'] ?? [], $state['purchases'] ?? [], $period);
            $values = $mapped['values'];
            $record = $this->workspace->preparations($company)->where('form', '2550Q')->where('period_from', $period['from'])->where('period_to', $period['to'])->latest('id')->first();
            $snapshot = $record ? json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
            $carryover = $this->fieldAmount($snapshot['bir_xml']['fields'] ?? [], 'inputTaxCarried');
            $base = $values['outputVatSales'] - $values['domesticInputTax'] - $values['serviceInputTax'] - $values['importInputTax'] + $values['non_creditable'];
            $credits = $record ? ($snapshot['calculation']['credits'] ?? null) : null;
            $penalties = $record ? array_sum(array_intersect_key($snapshot['calculation'] ?? [], array_flip(['surcharge', 'interest', 'compromise']))) : null;
            $additional = $snapshot['calculation']['additional_tax'] ?? 0;
            $net = $carryover !== null && $mapped['issues'] === [] ? $base - $carryover : null;
            $balance = $net !== null && is_int($credits) ? $net + $additional - $credits : null;
            $amounts = [
                'sales' => $values['vatableSales'], 'zero' => $values['zeroRatedSales'], 'exempt' => $values['exemptSales'],
                'total_sales' => $values['vatableSales'] + $values['zeroRatedSales'] + $values['exemptSales'],
                'output' => $values['outputVatSales'], 'purchases' => $values['domesticPurchase'] + $values['servicesPurchase'] + $values['importPurchase'],
                'nonvat' => $values['domesticPurchaseNoTax'] + $values['vatExemptImports'],
                'input' => $values['domesticInputTax'] + $values['serviceInputTax'] + $values['importInputTax'],
                'noncreditable' => $values['non_creditable'], 'base' => $base, 'carryover' => $carryover,
                'net' => $net, 'additional' => $record ? $additional : null, 'credits' => is_int($credits) ? $credits : null, 'balance' => $balance,
                'penalties' => $penalties, 'payable' => $balance !== null ? max(0, $balance + $penalties) : null,
                'excess_input' => $net !== null ? max(0, -$net) : null,
            ];
            $filing = DB::table('tax_return_filing_records')->where('company_id', $company->id)->where('form', '2550Q')->where('period_from', $period['from'])->where('period_to', $period['to'])->first();
            $savedValues = $snapshot['xml_vat_ledger']['values'] ?? null;
            $matches = is_array($savedValues) && $savedValues === $values && ($snapshot['xml_vat_ledger']['sources'] ?? []) === $mapped['sources'] && ($snapshot['xml_vat_ledger']['issues'] ?? []) === $mapped['issues'];
            $quarters[$quarter] = [
                'period' => $period, 'amounts' => $amounts, 'sources' => $mapped['sources'], 'issues' => $mapped['issues'],
                'record' => $record, 'filed' => $record?->status === 'filed' || (! ($record?->amended ?? false) && $filing?->filed_on !== null),
                'filing_reference' => $record?->filing_reference ?: $filing?->reference,
                'reconciliation' => ! $record ? 'Not generated' : ($savedValues === null ? 'Worksheet only — review mapping' : ($matches ? 'Matches saved sources' : 'Sources changed — review return')),
                'saved_payable' => $snapshot['calculation']['payable'] ?? null,
            ];
        }
        $annual = [];
        foreach (array_keys($quarters[1]['amounts']) as $key) {
            $numbers = array_column(array_column($quarters, 'amounts'), $key);
            $annual[$key] = in_array($key, ['carryover', 'net', 'balance', 'excess_input'], true) || in_array(null, $numbers, true) ? null : array_sum($numbers);
        }

        $workspace = $this->workspaceData($company, $profile, $year, $quarters, $state);

        return ['workspace' => $workspace, 'quarters' => $quarters, 'annual' => $annual, 'year' => $year, 'revision' => $ledger?->revision ?? 0, 'initialized' => $ledger !== null, 'applicable' => $quarters[1]['period']['applicable']];
    }

    /**
     * Operational balances remain separate from immutable saved return snapshots.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<int, array<string, mixed>>  $quarters
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function workspaceData(Company $company, array $profile, int $year, array $quarters, array $state): array
    {
        $events = DB::table('vat_summary_events')->where('company_id', $company->id)->orderBy('id')->get();
        foreach ($events as $event) {
            $event->data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
        }
        $openingEvents = $events->where('year', $year)->whereIn('action', ['credit', 'carryover', 'reverse']);
        $carry = $openingEvents->isEmpty() ? null : (int) $openingEvents->sum('amount');
        $opening = $carry;
        $movement = ['beginning' => $carry, 'addition' => 0, 'applied' => $carry === null ? null : 0, 'adjustment' => $carry === null ? null : 0, 'ending' => null];
        $otherCarry = 0;
        $totals = ['due' => 0, 'paid' => 0, 'open' => 0, 'input' => null, 'overpayment' => 0];
        $operating = [];
        foreach ($quarters as $q => $quarter) {
            $a = $quarter['amounts'];
            $previousCarry = $carry;
            $savedCarry = $a['carryover'];
            $usedCarry = $savedCarry ?? $carry;
            $withheld = 0;
            foreach ($state['cwtCertificates'] ?? [] as $certificate) {
                if (($certificate['status'] ?? '') === 'posted' && ($certificate['date'] ?? '') >= $quarter['period']['from'] && ($certificate['date'] ?? '') <= $quarter['period']['to'] && preg_match('/^W[VB][0-9]{3}/i', (string) ($certificate['atc'] ?? $certificate['atcCode'] ?? ''))) {
                    $withheld += is_int($certificate['amount'] ?? null) ? $certificate['amount'] : 0;
                }
            }
            $credits = $a['credits'] ?? ($withheld + $otherCarry);
            $penalties = $a['penalties'] ?? 0;
            $net = $usedCarry !== null && $quarter['issues'] === [] ? $a['base'] - $usedCarry : null;
            $due = $net === null ? null : max(0, $net - $credits + ($a['additional'] ?? 0)) + $penalties;
            $paid = $events->where('year', $year)->where('quarter', $q)->where('action', 'payment')->sum('amount');
            $taxPaid = $events->where('year', $year)->where('quarter', $q)->where('action', 'payment')->sum(fn (object $event): int => $event->amount - ($event->data['penalty_cents'] ?? 0));
            $penaltyPaid = (int) $paid - $taxPaid;
            $open = $due === null ? null : max(0, $due - $penalties - $taxPaid) + max(0, $penalties - $penaltyPaid);
            $carry = $net === null ? null : max(0, -$net);
            $movement['addition'] += $a['input'] - $a['noncreditable'];
            if ($net === null || $movement['applied'] === null) {
                $movement['applied'] = $movement['adjustment'] = null;
            } else {
                $movement['applied'] += $usedCarry + $a['input'] - $a['noncreditable'] - $carry;
                $movement['adjustment'] += $usedCarry - $previousCarry;
            }
            $movement['ending'] = $carry;
            $overpayment = $net === null ? null : max(0, $credits + $taxPaid - max(0, $net + ($a['additional'] ?? 0)));
            $otherCarry = $overpayment ?? 0;
            $close = $events->where('year', $year)->where('quarter', $q)->where('action', 'close')->first();
            $closeChanged = $close && isset($close->data['_close_movement']) && $this->movements($company, $quarter['period'], array_keys($close->data['_close_movement'])) !== $close->data['_close_movement'];
            $operating[$q] = ['carryover' => $usedCarry, 'net' => $net, 'balance' => $net === null ? null : $net + ($a['additional'] ?? 0) - $credits, 'credits' => $credits, 'withheld' => $withheld, 'due' => $due, 'paid' => (int) $paid, 'open' => $open, 'excess_input' => $carry, 'overpayment' => $overpayment, 'close' => $close, 'close_changed' => (bool) $closeChanged];
            if ($due === null) {
                $totals['due'] = $totals['open'] = null;
            } else {
                $totals['due'] = $totals['due'] === null ? null : $totals['due'] + $due;
                $totals['open'] = $totals['open'] === null ? null : $totals['open'] + $open;
            }
            $totals['paid'] += $paid;
            $totals['input'] = $carry;
            $totals['overpayment'] = $overpayment;
        }
        $credits = [];
        foreach ($events->whereIn('action', ['credit', 'carryover']) as $event) {
            $reversed = -$events->where('action', 'reverse')->filter(fn (object $row): bool => (int) ($row->data['credit_id'] ?? 0) === $event->id)->sum('amount');
            $credits[] = ['id' => $event->id, 'year' => $event->year, 'amount' => $event->amount, 'remaining' => $event->amount - $reversed];
        }
        $accounts = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->orderBy('code')->get(['code', 'name', 'type', 'is_cash_bank'])->toArray();
        $linked = $events->where('action', 'payment')->pluck('data.linked_source')->filter()->all();
        $journals = DB::table('journal_entries')->where('company_id', $company->id)->where('status', 'posted')->where('sheet', 'journalEntries')->orderByDesc('transaction_date')->get(['source_key', 'number', 'transaction_date'])->filter(fn (object $row): bool => ! in_array($row->source_key, $linked, true) && ! str_starts_with($row->source_key, 'journalEntries:vat-'))->values();

        return ['movement' => $movement, 'quarters' => $operating, 'opening' => $opening, 'totals' => $totals, 'events' => $events->where('year', $year)->values(), 'credits' => $credits, 'accounts' => $accounts, 'journals' => $journals, 'last_event' => (int) ($events->last()?->id ?? 0)];
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function sources(Company $company, array $profile, int $year, int $quarter, string $key): array
    {
        $period = $this->catalog->period('2550Q', $year, $quarter, $profile);
        $state = CompanyLedger::query()->where('company_id', $company->id)->value('state') ?? [];
        $sheet = in_array($key, ['sales', 'zero', 'exempt', 'total_sales', 'output'], true) ? 'sales' : 'purchases';
        $keys = ['sales' => ['vatableSales'], 'zero' => ['zeroRatedSales'], 'exempt' => ['exemptSales'], 'total_sales' => ['vatableSales', 'zeroRatedSales', 'exemptSales'], 'output' => ['outputVatSales'], 'purchases' => ['domesticPurchase', 'servicesPurchase', 'importPurchase'], 'nonvat' => ['domesticPurchaseNoTax', 'vatExemptImports'], 'input' => ['domesticInputTax', 'serviceInputTax', 'importInputTax'], 'noncreditable' => ['non_creditable']];
        $rows = [];
        $seen = [];
        foreach ($state[$sheet] ?? [] as $row) {
            if (($row['status'] ?? '') !== 'posted' || ($row['date'] ?? '') < $period['from'] || ($row['date'] ?? '') > $period['to']) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $mapped = $this->mapper->map($sheet === 'sales' ? [$row] : [], $sheet === 'purchases' ? [$row] : [], $period);
            $amount = array_sum(array_intersect_key($mapped['values'], array_flip($keys[$key])));
            if ($amount !== 0) {
                $rows[] = ['id' => (string) $row['id'], 'number' => $row['number'], 'date' => $row['date'], 'party' => $row['customer'] ?? $row['supplier'] ?? '', 'amount' => $amount, 'gross' => $row['gross'], 'url' => route('sheets.'.$sheet, ['company_id' => $company->id, 'from' => $period['from'], 'to' => $period['to'], 'focus' => $row['id']])];
            }
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount')), 'period' => $period];
    }

    /** @param array{from: string, to: string} $period
     * @param  list<string>  $codes
     * @return array<string, int>
     */
    public function movements(Company $company, array $period, array $codes): array
    {
        $excluded = DB::table('vat_summary_events')->where('company_id', $company->id)->where('action', 'close')->get()->map(fn (object $event): string => json_decode($event->data, true)['linked_source'] ?? '')->all();
        $accounts = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->whereIn('code', $codes)->pluck('code', 'id');
        $balances = array_fill_keys($codes, 0);
        $rows = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('l.company_id', $company->id)->where('j.company_id', $company->id)->where('j.status', 'posted')->whereBetween('j.transaction_date', [$period['from'], $period['to']])->whereIn('l.chart_account_id', $accounts->keys())->whereNotIn('j.source_key', $excluded)->get(['l.chart_account_id', 'l.debit', 'l.credit']);
        foreach ($rows as $row) {
            $amount = function (string $value): int {
                [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

                return (int) $whole * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
            };
            $balances[$accounts[$row->chart_account_id]] += $amount((string) $row->debit) - $amount((string) $row->credit);
        }

        return $balances;
    }

    /** @param array<string, mixed> $fields */
    private function fieldAmount(array $fields, string $key): ?int
    {
        $value = str_replace(',', '', (string) ($fields['frm2550qv2024:'.$key] ?? ''));
        if (! preg_match('/^([0-9]{1,12})\.([0-9]{2})$/D', $value, $parts)) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) $parts[2];
    }
}
