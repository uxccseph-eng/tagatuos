<?php

namespace App\Services\Tax;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use Illuminate\Support\Facades\DB;

class IncomeTaxSummaryService
{
    public function __construct(private TaxReturnCatalog $catalog, private TaxReturnWorkspace $workspace) {}

    public static function cents(string $value): int
    {
        abort_unless(preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,2})(?:00)?)?$/D', $value, $parts), 422, 'Enter a valid amount with up to two decimal places.');

        return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0'));
    }

    public static function pesos(int $cents): int
    {
        return ($cents < 0 ? -1 : 1) * intdiv(abs($cents) + 50, 100);
    }

    /** @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function build(Company $company, array $profile, int $year): array
    {
        $election = $this->workspace->yearSettings($company, $year);
        $individual = ($profile['entity_type'] ?? '') === 'individual';
        $quarterForm = $individual ? '1701Q' : '1702Q';
        $annualForm = $individual ? '1701' : match ($election['settings']['regime'] ?? 'regular') {
            'special' => '1702-MX', 'exempt' => '1702-EX', default => '1702-RT'
        };
        $annual = $this->catalog->period($annualForm, $year, 1, $profile);
        $events = DB::table('income_tax_summary_events')->where('company_id', $company->id)->orderBy('id')->get();
        foreach ($events as $event) {
            $event->data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
        }
        $settingsEvent = $events->where('year', $year)->where('action', 'settings')->last();
        $settings = $settingsEvent?->data;
        $settingsValid = $settings && ($settings['election_revision'] ?? -1) === $election['revision'];
        $supported = ($profile['entity_type'] ?? 'corporation') === 'corporation' && ($election['settings']['regime'] ?? 'regular') === 'regular' && $year >= 2024;
        $lines = $this->bookLines($company, $annual);
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $certificates = $ledger?->state['cwtCertificates'] ?? [];
        $opening = $events->where('year', $year)->filter(fn (object $event): bool => $event->action === 'credit' || ($event->action === 'reverse' && ($event->data['reverses_action'] ?? '') === 'credit'))->sum('amount');
        $register = [];
        foreach ($events->whereIn('action', ['nolco', 'credit', 'apply_nolco']) as $event) {
            $reversed = -$events->where('action', 'reverse')->filter(fn (object $row): bool => (int) ($row->data['credit_id'] ?? 0) === $event->id)->sum('amount');
            $applied = 0;
            if ($event->action === 'nolco') {
                foreach ($events->where('action', 'apply_nolco')->filter(fn (object $row): bool => (int) ($row->data['credit_id'] ?? 0) === $event->id) as $use) {
                    $applied += $use->amount + $events->where('action', 'reverse')->filter(fn (object $row): bool => (int) ($row->data['credit_id'] ?? 0) === $use->id)->sum('amount');
                }
            }
            $register[] = ['id' => $event->id, 'action' => $event->action, 'year' => $event->year, 'origin_year' => $event->data['origin_year'] ?? $event->year, 'expires' => $event->data['expires'] ?? null, 'original' => $event->amount, 'applied' => $applied, 'reversed' => $reversed, 'remaining' => $event->amount - $reversed - $applied];
        }
        $columns = [];
        $previous = $grossPrevious = $previousNolco = 0;
        foreach ([1, 2, 3, 4] as $q) {
            $period = $q === 4 ? $annual : $this->catalog->period($quarterForm, $year, $q, $profile);
            $rows = array_values(array_filter($lines, fn (array $row): bool => $row['date'] >= $period['from'] && $row['date'] <= $period['to']));
            $sums = array_fill_keys(['sales', 'other', 'cost', 'expense', 'nondeductible'], 0);
            foreach ($rows as $row) {
                $sums[$row['bucket']] += $row['amount'];
            }
            $sums = array_map(self::pesos(...), $sums);
            $gross = $sums['sales'] + $sums['other'] - $sums['cost'];
            $method = $election['settings']['deduction_method'] ?? 'itemized';
            $deductions = $method === 'osd' ? intdiv(max(0, $gross) * 40 + 50, 100) : $sums['expense'];
            $adjustments = $events->where('year', $year)->where('action', 'adjustment')->filter(fn (object $event): bool => ($event->data['adjustment_target'] ?? 'taxable') !== 'mcit_gross' && ($q === 4 || (int) $event->quarter === $q))->sum('amount');
            $adjustment = self::pesos((int) $adjustments);
            $nolco = $events->where('year', $year)->filter(fn (object $event): bool => ($event->action === 'apply_nolco' || ($event->action === 'reverse' && ($event->data['reverses_action'] ?? '') === 'apply_nolco')) && ($q === 4 || (int) $event->quarter <= $q))->sum('amount');
            $nolco = self::pesos((int) $nolco);
            $beforeLoss = $gross - $deductions + $adjustment + ($q === 4 ? 0 : $previous);
            $taxable = $beforeLoss - $nolco;
            $cwt = $cwtPrevious = 0;
            foreach ($certificates as $certificate) {
                $date = $certificate['date'] ?? '';
                if (($certificate['status'] ?? '') !== 'posted' || $date < $annual['from'] || $date > $period['to'] || ! preg_match('/^W[IC][0-9]{3}/i', (string) ($certificate['atc'] ?? $certificate['atcCode'] ?? '')) || ! is_int($certificate['amount'] ?? null)) {
                    continue;
                }
                $cwt += $certificate['amount'];
                if ($q === 4 ? $date <= $columns[3]['period']['to'] : $date < $period['from']) {
                    $cwtPrevious += $certificate['amount'];
                }
            }
            $priorPayments = $events->where('year', $year)->where('action', 'payment')->filter(fn (object $event): bool => (int) $event->quarter < $q)->sum(fn (object $event): int => $event->amount - ($event->data['penalty_cents'] ?? 0));
            $paymentParts = [];
            foreach (['regular', 'mcit'] as $subtype) {
                $paymentParts[$subtype] = $events->where('year', $year)->where('action', 'payment')->filter(fn (object $event): bool => (int) $event->quarter < $q && ($event->data['subtype'] ?? 'regular') === $subtype)->sum(fn (object $event): int => $event->amount - ($event->data['penalty_cents'] ?? 0));
            }
            $credits = self::pesos((int) $opening) + self::pesos($cwtPrevious) + self::pesos($cwt - $cwtPrevious) + self::pesos((int) $paymentParts['regular']) + self::pesos((int) $paymentParts['mcit']);
            $issues = [];
            if ($ledger === null) {
                $issues[] = 'No saved ledger is available. Initialize and review the company books before treating this as a nil income tax computation.';
            }
            if (! $settingsValid && $supported) {
                $issues[] = 'Review the income tax rate and MCIT applicability for this year.';
            }
            if ($nolco > max(0, $beforeLoss) || ($method === 'osd' && $nolco > 0)) {
                $issues[] = 'NOLCO allocation exceeds the available income or conflicts with OSD. Reverse the excess allocation before relying on this calculation.';
            }
            if ($supported && $settingsValid && $settings['rate'] === '20' && $taxable > 5000000) {
                $issues[] = 'Taxable income exceeds the 20% rate threshold. Review the applicable corporate rate.';
            }
            $mcitAdjustment = self::pesos((int) $events->where('year', $year)->where('action', 'adjustment')->filter(fn (object $event): bool => in_array($event->data['adjustment_target'] ?? 'taxable', ['mcit_gross', 'both'], true) && ($q === 4 || (int) $event->quarter <= $q))->sum('amount'));
            $grossToDate = $gross + ($q === 4 ? 0 : $grossPrevious) + $mcitAdjustment;
            $rcit = $supported && $settingsValid ? intdiv(max(0, $taxable) * (int) $settings['rate'] + 50, 100) : null;
            $mcit = $supported && $settingsValid ? ($settings['mcit'] === 'applicable' ? intdiv(max(0, $grossToDate) * 2 + 50, 100) : 0) : null;
            $tax = $issues === [] && $rcit !== null ? max($rcit, $mcit) : null;
            $form = $q === 4 ? $annualForm : $quarterForm;
            $record = $this->workspace->preparations($company)->where('form', $form)->where('period_from', $period['from'])->where('period_to', $period['to'])->latest('id')->first();
            $saved = $record ? json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR) : [];
            if (! $supported) {
                $issues[] = 'This taxpayer type or special/exempt regime requires a separately reviewed computation in Tax Returns. Book figures remain available below.';
            }
            $penalties = array_sum(array_intersect_key($saved['calculation'] ?? [], array_flip(['surcharge', 'interest', 'compromise'])));
            $net = $tax === null ? null : $tax * 100 - $credits * 100;
            $paidEvents = $events->where('year', $year)->where('quarter', $q)->where('action', 'payment');
            $paidTax = $paidEvents->sum(fn (object $event): int => $event->amount - ($event->data['penalty_cents'] ?? 0));
            $paidPenalty = $paidEvents->sum(fn (object $event): int => $event->data['penalty_cents'] ?? 0);
            $sourceHash = hash('sha256', json_encode([$rows, $election, $settings, $opening, $credits, $adjustment, $mcitAdjustment, $nolco], JSON_THROW_ON_ERROR));
            $filing = DB::table('tax_return_filing_records')->where('company_id', $company->id)->where('form', $form)->where('period_from', $period['from'])->where('period_to', $period['to'])->first();
            $columns[$q] = ['form' => $form, 'period' => $period, 'record' => $record, 'filed' => $record?->status === 'filed' || (! ($record?->amended ?? false) && $filing?->filed_on !== null), 'saved_payable' => $saved['calculation']['payable'] ?? null, 'source_hash' => $sourceHash, 'reconciliation' => ! $record ? 'Not generated' : (($saved['income_summary_hash'] ?? null) === $sourceHash ? 'Book sources unchanged · compare reviewed tax inputs' : 'Review book sources / tax inputs'), 'issues' => $issues,
                'amounts' => ['sales' => $sums['sales'] * 100, 'other' => $sums['other'] * 100, 'cost' => $sums['cost'] * 100, 'gross' => $gross * 100, 'deductions' => $deductions * 100, 'adjustment' => $adjustment * 100, 'mcit_adjustment' => $mcitAdjustment * 100, 'previous' => ($q === 4 ? 0 : $previous) * 100, 'nolco' => $nolco * 100, 'taxable' => $taxable * 100, 'rcit' => $rcit === null ? null : $rcit * 100, 'mcit' => $mcit === null ? null : $mcit * 100, 'tax' => $tax === null ? null : $tax * 100, 'credits' => $credits * 100, 'net' => $net, 'penalties' => $penalties, 'total' => $net === null ? null : $net + $penalties, 'paid' => $paidEvents->sum('amount'), 'open' => $net === null ? null : max(0, $net - $paidTax) + max(0, $penalties - $paidPenalty)],
                'sources' => $rows, 'credit_breakdown' => ['opening' => (int) $opening, 'cwt' => $cwt, 'cwt_previous' => $cwtPrevious, 'payments' => (int) $priorPayments]];
            if ($q < 4) {
                $previous = $beforeLoss;
                $grossPrevious += $gross;
            }
        }
        $accounts = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('archived', false)->orderBy('code')->get(['code', 'name', 'type', 'is_cash_bank'])->toArray();
        $linked = $events->where('action', 'payment')->pluck('data.linked_source')->filter()->all();
        $journals = DB::table('journal_entries')->where('company_id', $company->id)->where('status', 'posted')->where('sheet', 'journalEntries')->orderByDesc('transaction_date')->get(['source_key', 'number', 'transaction_date'])->filter(fn (object $entry): bool => ! in_array($entry->source_key, $linked, true))->values();

        return ['year' => $year, 'profile' => $profile, 'election' => $election, 'supported' => $supported, 'settings' => $settings, 'settings_valid' => $settingsValid, 'columns' => $columns, 'annual_form' => $annualForm, 'quarter_form' => $quarterForm, 'events' => $events->where('year', $year)->values(), 'register' => $register, 'opening' => (int) $opening, 'accounts' => $accounts, 'journals' => $journals, 'last_event' => (int) ($events->last()?->id ?? 0), 'ledger_revision' => (int) ($ledger?->revision ?? 0)];
    }

    /** @param array<string, mixed> $summary
     * @return array<string, mixed>|null
     */
    public function quarterlySuggestion(array $summary, int $quarter): ?array
    {
        $column = $summary['columns'][$quarter];
        if ($quarter > 3 || ! $summary['supported'] || ! $summary['settings_valid'] || $column['issues'] !== [] || $column['amounts']['adjustment'] !== 0 || $column['amounts']['mcit_adjustment'] !== 0 || $column['amounts']['nolco'] !== 0) {
            return null;
        }
        $input = [];
        foreach (app(CorporateIncomeWorksheet::class)->inputs('1702Q') as $key => $definition) {
            $input[$key] = '0';
        }
        $input['method'] = $summary['election']['settings']['deduction_method'];
        $input['rate'] = $summary['settings']['rate'];
        $input['mcit'] = $summary['settings']['mcit'];
        $input['small_eligible'] = $summary['settings']['small_eligible'] ?? false;
        foreach (['sales' => 'sales', 'cost' => 'cost', 'other_income' => 'other'] as $target => $source) {
            if ($column['amounts'][$source] < 0) {
                return null;
            }
            $input[$target] = (string) intdiv($column['amounts'][$source], 100);
        }
        $input['deductions'] = $input['method'] === 'osd' ? '0' : (string) intdiv($column['amounts']['deductions'], 100);
        $input['previous_taxable'] = (string) intdiv($column['amounts']['previous'], 100);
        if ($quarter > 1) {
            $input['gross_q1'] = (string) intdiv($summary['columns'][1]['amounts']['gross'], 100);
        }
        if ($quarter > 2) {
            $input['gross_q2'] = (string) intdiv($summary['columns'][2]['amounts']['gross'], 100);
        }
        $input['credit_prior_year'] = (string) self::pesos($column['credit_breakdown']['opening']);
        $input['credit_cwt_previous'] = (string) self::pesos($column['credit_breakdown']['cwt_previous']);
        $input['credit_cwt_current'] = (string) self::pesos($column['credit_breakdown']['cwt'] - $column['credit_breakdown']['cwt_previous']);
        foreach (['regular', 'mcit'] as $type) {
            $payments = $summary['events']->where('action', 'payment')->filter(fn (object $event): bool => (int) $event->quarter < $quarter && ($event->data['subtype'] ?? 'regular') === $type)->sum(fn (object $event): int => $event->amount - ($event->data['penalty_cents'] ?? 0));
            $input['credit_'.$type.'_previous'] = (string) self::pesos((int) $payments);
        }

        return $input;
    }

    /** @param array{from: string, to: string} $period
     * @return list<array<string, mixed>>
     */
    private function bookLines(Company $company, array $period): array
    {
        $rows = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('chart_accounts as c', 'c.id', '=', 'l.chart_account_id')->where('j.company_id', $company->id)->where('l.company_id', $company->id)->where('c.company_id', $company->id)->where('j.status', 'posted')->whereBetween('j.transaction_date', [$period['from'], $period['to']])->whereIn('c.type', ['Income', 'Expense'])->orderBy('l.id')->get(['j.source_key', 'j.number', 'j.sheet', 'j.transaction_date', 'c.code', 'c.name', 'c.type', 'c.statement_section', 'l.debit', 'l.credit']);
        $penaltySources = [];
        foreach (['income_tax_summary_events', 'vat_summary_events', 'ewt_summary_events', 'compensation_summary_events'] as $table) {
            foreach (DB::table($table)->where('company_id', $company->id)->where('action', 'payment')->get() as $event) {
                $data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
                if (($data['penalty_cents'] ?? 0) > 0 && ! empty($data['linked_source'])) {
                    $penaltySources[$data['linked_source'].'|'.($data['penalty_account'] ?? '7290')] = true;
                }
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $amount = self::cents((string) $row->debit) - self::cents((string) $row->credit);
            $section = strtolower($row->statement_section ?? '');
            $bucket = $row->type === 'Income' ? (str_contains($section, 'other') ? 'other' : 'sales') : (preg_match('/cost[\s_-]*of[\s_-]*(?:goods|sales|services|revenue)/', $section) || ($section === '' && $row->code === '5000') ? 'cost' : 'expense');
            if ($row->type === 'Income') {
                $amount = -$amount;
            }
            if (isset($penaltySources[$row->source_key.'|'.$row->code])) {
                $bucket = 'nondeductible';
            }
            $sheet = match ($row->sheet) {
                'journalEntries' => 'journal', 'cwtCertificates' => 'cwt', 'wtpCertificates' => 'wtp', default => $row->sheet
            };
            $result[] = ['source' => $row->source_key, 'number' => $row->number, 'date' => $row->transaction_date, 'bucket' => $bucket, 'account' => $row->code.' · '.$row->name, 'amount' => $amount, 'url' => route('sheets.'.$sheet, ['company_id' => $company->id, 'from' => $row->transaction_date, 'to' => $row->transaction_date])];
        }

        return $result;
    }
}
