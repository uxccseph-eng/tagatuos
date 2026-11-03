<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\CompanySetting;
use App\Models\FinancialStatement;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialStatementCalculator
{
    /**
     * Generate one annual snapshot from the saved, company-scoped ledger, never browser totals.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function generate(Company $company, int $auditPackId, int $year, int $revision, array $inputs = []): FinancialStatement
    {
        return $company->run(function () use ($company, $auditPackId, $year, $revision, $inputs): FinancialStatement {
            return DB::transaction(function () use ($company, $auditPackId, $year, $revision, $inputs): FinancialStatement {
                $ledger = CompanyLedger::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($auditPackId);
                abort_unless($ledger->revision === $revision, 409, 'The ledger changed. Reload before generating financial statements.');
                $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
                $matrices = $this->calculate(
                    $this->trialBalance($company->id, $year),
                    $this->trialBalance($company->id, $year - 1),
                    $this->trialBalance($company->id, $year - 2),
                    ['current' => $this->cashActivity($company->id, $year), 'previous' => $this->cashActivity($company->id, $year - 1)],
                );

                $previousStatement = FinancialStatement::query()->where('reporting_year', $year)->first();
                $savedInputs = $previousStatement?->package['inputs'] ?? [];
                if ($previousStatement && ($previousStatement->ledger_revision !== $revision || ($previousStatement->package['profile'] ?? []) !== $profile)) {
                    $savedInputs['management_confirmed'] = false;
                }
                $package = app(FinancialStatementPackage::class)->build($company->id, $year, $profile, $matrices, array_replace_recursive($savedInputs, $inputs));

                return FinancialStatement::query()->updateOrCreate(
                    ['company_id' => $company->id, 'reporting_year' => $year],
                    ['company_ledger_id' => $ledger->id, 'company_name' => $profile['company_name'], 'tin' => $profile['tin'] ?? '', 'ledger_revision' => $revision, 'package' => $package, ...$matrices],
                );
            }, 3);
        });
    }

    /** @return list<array<string, mixed>> */
    private function trialBalance(int $companyId, int $year): array
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', function (JoinClause $join) use ($companyId): void {
                $join->on('j.id', '=', 'l.journal_entry_id')->where('j.company_id', $companyId);
            })
            ->join('chart_accounts as a', function (JoinClause $join) use ($companyId): void {
                $join->on('a.id', '=', 'l.chart_account_id')->where('a.company_id', $companyId);
            })
            ->where('l.company_id', $companyId)->where('j.status', 'posted')->where('j.transaction_date', '<=', $year.'-12-31')
            ->select(['a.id', 'a.name', 'a.type', 'a.statement_section', 'a.is_cash_bank', 'a.cash_flow_section'])
            ->selectRaw('SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->selectRaw('SUM(CASE WHEN j.transaction_date >= ? THEN l.debit ELSE 0 END) as period_debit', [$year.'-01-01'])
            ->selectRaw('SUM(CASE WHEN j.transaction_date >= ? THEN l.credit ELSE 0 END) as period_credit', [$year.'-01-01'])
            ->groupBy('a.id', 'a.name', 'a.type', 'a.statement_section', 'a.is_cash_bank', 'a.cash_flow_section')->orderBy('a.id')
            ->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /**
     * Cash investing/financing amounts come from cash-bearing journals. Non-cash acquisitions
     * are not assumed to be payments. Mixed cash/non-cash settlements require review.
     *
     * @return array{investing: int, financing: int, noncash_working_capital?: int, warnings: list<string>}
     */
    private function cashActivity(int $companyId, int $year): array
    {
        $result = ['investing' => 0, 'financing' => 0, 'noncash_working_capital' => 0, 'warnings' => []];
        $entry = null;
        $lines = [];
        $flush = function () use (&$result, &$lines, &$entry): void {
            if ($lines === []) {
                return;
            }
            $cash = 0;
            $workingCapital = 0;
            $groups = [];
            foreach ($lines as $row) {
                $amount = $this->minor($row['debit']) - $this->minor($row['credit']);
                if ($this->group($row) === 'cash') {
                    $cash += $amount;
                } else {
                    if (in_array($this->group($row), ['current_assets', 'current_liabilities']) && $this->cashCategory($row) === 'operating') {
                        $workingCapital += $amount;
                    }
                    $category = $this->cashCategory($row);
                    $groups[$category] = ($groups[$category] ?? 0) - $amount;
                }
            }
            $groups = array_filter($groups, fn (int $amount): bool => $amount !== 0);
            if ($cash === 0 && (isset($groups['investing']) || isset($groups['financing']))) {
                $result['noncash_working_capital'] += $workingCapital;
            }
            if ($cash !== 0) {
                if (count($groups) > 1) {
                    $result['warnings'][] = 'Journal '.$entry.' mixes cash-flow categories; review its non-cash components.';
                } elseif ($groups !== []) {
                    $category = array_key_first($groups);
                    if (isset($result[$category])) {
                        $result[$category] += $cash;
                    }
                }
            }
            $lines = [];
        };
        $query = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('chart_accounts as a', 'a.id', '=', 'l.chart_account_id')
            ->where('l.company_id', $companyId)->where('j.company_id', $companyId)->where('a.company_id', $companyId)
            ->where('j.status', 'posted')->whereBetween('j.transaction_date', [$year.'-01-01', $year.'-12-31'])
            ->select(['j.id as journal_id', 'l.debit', 'l.credit', 'a.name', 'a.type', 'a.statement_section', 'a.is_cash_bank', 'a.cash_flow_section'])->orderBy('j.id')->orderBy('l.id');
        foreach ($query->cursor() as $line) {
            if ($entry !== $line->journal_id) {
                $flush();
                $entry = $line->journal_id;
            }
            $lines[] = (array) $line;
        }
        $flush();
        $result['warnings'] = array_values(array_unique($result['warnings']));

        return $result;
    }

    /**
     * Rows contain name, optional type/statement_section, debit/credit closing TB totals,
     * and period_debit/period_credit for annual P&L (defaults to debit/credit for a first year).
     * All calculations use integer centavos. Missing prior years mean zero opening balances.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $previous
     * @param  list<array<string, mixed>>  $opening
     * @param  array<string, array{investing: int, financing: int, noncash_working_capital?: int, warnings: list<string>}>  $cash
     * @return array<string, array<string, mixed>>
     */
    public function calculate(array $current, array $previous = [], array $opening = [], array $cash = []): array
    {
        $now = $this->period($current);
        $before = $this->period($previous);
        $start = $this->period($opening);
        $row = fn (string $label, int $value, int $prior, ?int $note = null, string $style = ''): array => ['label' => $label, 'note' => $note, 'current' => $value / 100, 'previous' => $prior / 100, 'style' => $style];
        $balance = [
            $row('Assets', 0, 0, null, 'heading'),
            $row('Current Assets', 0, 0, null, 'heading'),
            $row('Cash and Cash Equivalents', $now['cash'], $before['cash'], 4),
            $row('Prepaid and Other Current Assets', $now['current_assets'], $before['current_assets'], 7),
        ];
        if ($now['noncurrent_assets'] !== 0 || $before['noncurrent_assets'] !== 0) {
            $balance[] = $row('Non-Current Assets', $now['noncurrent_assets'], $before['noncurrent_assets']);
        }
        $balance[] = $row('Total Assets', $now['assets'], $before['assets'], null, 'total');
        $balance[] = $row('Liabilities and Equity', 0, 0, null, 'heading');
        $balance[] = $row('Current Liabilities', 0, 0, null, 'heading');
        $balance[] = $row('Trade and Other Payables', $now['current_liabilities'], $before['current_liabilities'], 9);
        if ($now['noncurrent_liabilities'] !== 0 || $before['noncurrent_liabilities'] !== 0) {
            $balance[] = $row('Non-Current Liabilities', $now['noncurrent_liabilities'], $before['noncurrent_liabilities']);
        }
        $balance[] = $row('Total Liabilities', $now['liabilities'], $before['liabilities'], null, 'total');
        $balance[] = $row('Equity', $now['equity'], $before['equity']);
        $balance[] = $row('Total Equity', $now['equity'], $before['equity'], null, 'total');
        $balance[] = $row('Total Liabilities and Equity', $now['liabilities'] + $now['equity'], $before['liabilities'] + $before['equity'], null, 'grand');
        $income = [];
        foreach (['revenue' => ['Revenue', 13], 'cost' => ['Less: Cost of Sales', 14], 'gross' => ['Gross Profit', null], 'expenses' => ['Less: Operating Expenses', 15], 'pretax' => ['Net Income Before Income Tax', null], 'tax' => ['Income Tax Expense', 17], 'profit' => ['Net Income', null]] as $key => [$label, $note]) {
            if ($key === 'tax' && $now[$key] === 0 && $before[$key] === 0) {
                continue;
            }
            $sign = in_array($key, ['cost', 'expenses', 'tax']) ? -1 : 1;
            $income[] = $row($label, $now[$key] * $sign, $before[$key] * $sign, $note, in_array($key, ['gross', 'pretax', 'profit']) ? 'total' : '');
        }
        $schedules = [];
        foreach (array_unique([...array_keys($now['details']), ...array_keys($before['details'])]) as $section) {
            foreach (array_unique([...array_keys($now['details'][$section] ?? []), ...array_keys($before['details'][$section] ?? [])]) as $label) {
                $schedules[$section][] = $row($label, $now['details'][$section][$label] ?? 0, $before['details'][$section][$label] ?? 0);
            }
        }
        $flow = $this->flow($now, $before, $cash['current'] ?? null);
        $priorFlow = $this->flow($before, $start, $cash['previous'] ?? null);
        $flows = [];
        foreach ($priorFlow['rows'] as $key => $data) {
            if (! isset($flow['rows'][$key])) {
                $position = array_search('operating', array_keys($flow['rows']), true);
                $flow['rows'] = array_slice($flow['rows'], 0, $position, true) + [$key => [...$data, 'value' => 0]] + array_slice($flow['rows'], $position, null, true);
            }
        }
        foreach ($flow['rows'] as $key => $data) {
            $flows[] = $row($data['label'], $data['value'], $priorFlow['rows'][$key]['value'] ?? 0, null, $data['style']);
        }

        return [
            'balance_sheet' => ['rows' => $balance, 'schedules' => $schedules, 'difference' => ($now['assets'] - $now['liabilities'] - $now['equity']) / 100, 'previous_difference' => ($before['assets'] - $before['liabilities'] - $before['equity']) / 100],
            'income_statement' => ['rows' => $income],
            'equity' => ['current' => $this->equity($now, $before), 'previous' => $this->equity($before, $start)],
            'cash_flows' => ['rows' => $flows, 'difference' => $flow['difference'] / 100, 'previous_difference' => $priorFlow['difference'] / 100, 'warnings' => array_values(array_unique([...$flow['warnings'], ...$priorFlow['warnings']]))],
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function period(array $rows): array
    {
        $result = array_fill_keys(['cash', 'current_assets', 'noncurrent_assets', 'current_liabilities', 'noncurrent_liabilities', 'capital', 'retained', 'unclosed', 'revenue', 'cost', 'expenses', 'tax', 'depreciation'], 0);
        $result['details'] = [];
        $result['working_capital'] = [];
        $debits = $credits = 0;
        foreach ($rows as $row) {
            $debit = $this->minor($row['debit'] ?? 0);
            $credit = $this->minor($row['credit'] ?? 0);
            $debits += $debit;
            $credits += $credit;
            $group = $this->group($row);
            $net = $debit - $credit;
            $movement = $this->minor($row['period_debit'] ?? $row['debit'] ?? 0) - $this->minor($row['period_credit'] ?? $row['credit'] ?? 0);
            $pnl = in_array($group, ['revenue', 'cost', 'expenses', 'tax']);
            $value = $pnl ? $movement : $net;
            if (in_array($group, ['current_liabilities', 'noncurrent_liabilities', 'capital', 'retained', 'revenue'])) {
                $value *= -1;
            }
            $result[$group] += $value;
            if ($pnl) {
                $result['unclosed'] -= $net;
            }
            $name = (string) ($row['name'] ?? $row['account'] ?? '');
            if (preg_match('/depreciation|amortization/i', $name) && $pnl) {
                $result['depreciation'] += $movement;
            }
            $label = $this->label($name);
            $result['details'][$group][$label] = ($result['details'][$group][$label] ?? 0) + $value;
            if (in_array($group, ['current_assets', 'current_liabilities']) && $this->cashCategory($row) === 'operating') {
                $result['working_capital'][$group][$label] = ($result['working_capital'][$group][$label] ?? 0) + $value;
            }
        }
        if ($debits !== $credits) {
            throw ValidationException::withMessages(['trial_balance' => 'Trial balance does not balance. Difference: '.number_format(($debits - $credits) / 100, 2, '.', '').'. Correct the posted journals before generating.']);
        }
        $result['gross'] = $result['revenue'] - $result['cost'];
        $result['pretax'] = $result['gross'] - $result['expenses'];
        $result['profit'] = $result['pretax'] - $result['tax'];
        $result['assets'] = $result['cash'] + $result['current_assets'] + $result['noncurrent_assets'];
        $result['liabilities'] = $result['current_liabilities'] + $result['noncurrent_liabilities'];
        $result['retained'] += $result['unclosed'];
        $result['equity'] = $result['capital'] + $result['retained'];

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function group(array $row): string
    {
        $name = strtolower((string) ($row['name'] ?? $row['account'] ?? ''));
        $section = strtolower((string) ($row['statement_section'] ?? ''));
        $type = strtolower((string) ($row['type'] ?? ''));
        if (preg_match('/input vat|prepaid cwt|cwt receivable|creditable withholding/', $name)) {
            return 'current_assets';
        }
        if ($type === 'asset' || ($type === '' && preg_match('/cash|receivable|inventory|prepaid|equipment/', $name))) {
            return ($row['is_cash_bank'] ?? false) || preg_match('/^cash|bank account/', $name) ? 'cash' : (str_contains($section, 'non-current') || str_contains($section, 'noncurrent') || preg_match('/equipment|accumulated depreciation|intangible/', $name) ? 'noncurrent_assets' : 'current_assets');
        }
        if ($type === 'liability' || ($type === '' && preg_match('/payable|output vat/', $name))) {
            return str_contains($section, 'non-current') || str_contains($section, 'noncurrent') ? 'noncurrent_liabilities' : 'current_liabilities';
        }
        if ($type === 'equity' || preg_match('/retained earnings|share capital|owner.?s? capital/', $name)) {
            return str_contains($section, 'retained') || str_contains($name, 'retained') ? 'retained' : 'capital';
        }
        if (in_array($type, ['income', 'revenue']) || ($type === '' && preg_match('/sales|revenue|income/', $name) && ! str_contains($name, 'cost') && ! str_contains($name, 'tax'))) {
            return 'revenue';
        }
        if ($section === 'income tax expense' || preg_match('/income tax.*(expense|provision)|provision.*income tax/', $name)) {
            return 'tax';
        }
        if (preg_match('/advertising|handling|marketing|outsource|representation|cost of (sales|goods)/', $name) || preg_match('/cost of (goods|sales)/', $section)) {
            return 'cost';
        }
        if ($type === 'expense' || preg_match('/salaries|wages|rent expense/', $name)) {
            return 'expenses';
        }
        throw ValidationException::withMessages(['accounts' => 'Assign an account type and financial statement section in Masters for: '.($row['name'] ?? $row['account'] ?? 'unnamed account')]);
    }

    /** @param array<string, mixed> $row */
    private function cashCategory(array $row): string
    {
        $explicit = strtolower((string) ($row['cash_flow_section'] ?? ''));
        if (str_contains($explicit, 'invest')) {
            return 'investing';
        }
        if (str_contains($explicit, 'financ')) {
            return 'financing';
        }

        return match ($this->group($row)) {
            'noncurrent_assets' => 'investing',
            'capital', 'retained', 'noncurrent_liabilities' => 'financing',
            default => 'operating',
        };
    }

    private function label(string $name): string
    {
        foreach (['Advertising', 'Handling Fees', 'Marketing', 'Outsource (Influencers)', 'Outsource (Photographers)', 'Outsource (Videographers)'] as $label) {
            if (str_contains(preg_replace('/[^a-z]/', '', strtolower($name)), preg_replace('/[^a-z]/', '', strtolower(str_replace(' Fees', '', $label))))) {
                return 'Direct Charges - '.$label;
            }
        }
        if (str_contains(strtolower($name), 'representation')) {
            return 'Representation and Entertainment';
        }
        if (preg_match('/salaries.*wages/i', $name)) {
            return 'Salaries and Wages';
        }

        return $name;
    }

    /** @param array<string, mixed> $now @param array<string, mixed> $before @return list<array<string, mixed>> */
    private function equity(array $now, array $before): array
    {
        $rows = [
            ['Balance at beginning of year', $before['capital'], $before['retained']],
            ['Capital contributions / (withdrawals)', $now['capital'] - $before['capital'], 0],
            ['Net income for the year', 0, $now['profit']],
            ['Other retained earnings movements / (distributions)', 0, $now['retained'] - $before['retained'] - $now['profit']],
            ['Balance at end of year', $now['capital'], $now['retained']],
        ];

        return array_map(fn (array $row): array => ['label' => $row[0], 'capital' => $row[1] / 100, 'retained' => $row[2] / 100, 'total' => ($row[1] + $row[2]) / 100], $rows);
    }

    /** @param array<string, mixed> $now @param array<string, mixed> $before @param array<string, mixed>|null $cash @return array<string, mixed> */
    private function flow(array $now, array $before, ?array $cash): array
    {
        $rows = [];
        $add = function (string $key, string $label, int $value = 0, string $style = '') use (&$rows): void {
            $rows[$key] = compact('label', 'value', 'style');
        };
        $add('operating_heading', 'Cash Flow from Operating Activities', 0, 'heading');
        $add('profit', 'Net Income', $now['profit']);
        $add('noncash_heading', 'Adjustments for non-cash items:', 0, 'heading');
        $add('depreciation', 'Depreciation and amortization', $now['depreciation']);
        $operating = $now['profit'] + $now['depreciation'];
        $add('working_heading', 'Changes in working capital:', 0, 'heading');
        foreach (['current_assets' => -1, 'current_liabilities' => 1] as $group => $sign) {
            foreach (array_unique([...array_keys($now['working_capital'][$group] ?? []), ...array_keys($before['working_capital'][$group] ?? [])]) as $label) {
                $change = (($now['working_capital'][$group][$label] ?? 0) - ($before['working_capital'][$group][$label] ?? 0)) * $sign;
                $add($group.':'.$label, 'Change in '.$label, $change);
                $operating += $change;
            }
        }
        $noncash = $cash['noncash_working_capital'] ?? 0;
        $add('noncash_working_capital', 'Non-cash investing / financing movements in working capital', $noncash);
        $operating += $noncash;
        $investing = $cash['investing'] ?? 0;
        $financing = $cash['financing'] ?? 0;
        $add('operating', 'Net cash from operating activities', $operating, 'total');
        $add('investing_heading', 'Cash Flow from Investing Activities', 0, 'heading');
        $add('investing_activity', 'Cash investments / (asset purchases)', $investing);
        $add('investing', 'Net cash from investing activities', $investing, 'total');
        $add('financing_heading', 'Cash Flow from Financing Activities', 0, 'heading');
        $add('financing_activity', 'Cash capital, borrowings / (repayments and distributions)', $financing);
        $add('financing', 'Net cash from financing activities', $financing, 'total');
        $net = $operating + $investing + $financing;
        $add('net', 'Net increase (decrease) in cash', $net, 'total');
        $add('opening', 'Cash, beginning of period', $before['cash']);
        $add('closing', 'Cash, end of period', $before['cash'] + $net, 'grand');
        $warnings = $cash['warnings'] ?? ['Investing and financing cash journals were not supplied. Cash flows require review.'];
        if ($now['cash'] !== $before['cash'] + $net) {
            $warnings[] = 'Cash flows do not reconcile to the balance sheet. Review non-cash transactions, cash-flow classifications and opening balances.';
        }

        return ['rows' => $rows, 'difference' => $now['cash'] - $before['cash'] - $net, 'warnings' => $warnings];
    }

    private function minor(string|int|float $amount): int
    {
        if (! is_numeric($amount) || ! is_finite((float) $amount) || abs((float) $amount) > 999999999999) {
            throw ValidationException::withMessages(['trial_balance' => 'Trial balance amounts must be finite numbers within the supported monetary range.']);
        }

        return (int) round((float) $amount * 100);
    }
}
