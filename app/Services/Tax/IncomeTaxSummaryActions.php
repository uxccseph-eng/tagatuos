<?php

namespace App\Services\Tax;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\ClientDatabase;
use App\Services\CompanyLedgerService;
use App\TeamPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncomeTaxSummaryActions
{
    public function __construct(private IncomeTaxSummaryService $summary, private TaxReturnWorkspace $workspace, private CompanyLedgerService $ledger) {}

    /** @param array<string, mixed> $input

     * @return array<string, mixed>
     */
    public function preview(Company $company, User $actor, array $input): array
    {
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        $summary = $this->summary->build($company, $profile, (int) $input['year']);
        $action = $input['action'];
        $year = (int) $input['year'];
        $quarter = (int) ($input['quarter'] ?? 0);
        $amount = IncomeTaxSummaryService::cents((string) ($input['amount'] ?? '0'));
        $data = $input;
        unset($data['preview_hash'], $data['confirmed'], $data['request_id']);
        $slot = null;
        $lines = [];
        $warnings = [];
        abort_if($action !== 'adjustment' && $amount < 0, 422, 'Use a positive amount; reductions are recorded as reversals.');
        if ($action === 'settings') {
            $member = $company->users()->where('users.id', $actor->id)->firstOrFail();
            abort_unless(in_array('settings', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403, 'Tax rate settings require Settings access.');
            abort_unless($summary['supported'], 422, 'This automatic calculation supports regular domestic corporations from 2024. Use Tax Returns for this taxpayer’s reviewed computation.');
            abort_if($input['rate'] === '20' && ! ($input['small_eligible'] ?? false), 422, 'Confirm eligibility for the 20% corporate rate, including the asset and taxable-income limits.');
            $start = $summary['election']['settings']['mcit_start_year'] ?? null;
            abort_if($start !== null && ($input['mcit'] === 'applicable') !== ($year >= (int) $start), 422, 'MCIT applicability disagrees with the starting year saved in Tax Returns.');
            $data['small_eligible'] = (bool) ($input['small_eligible'] ?? false);
            $data['election_revision'] = $summary['election']['revision'];
            $warnings[] = 'Records reviewed rate and MCIT choices. Deduction method remains the year’s election in Tax Returns. No journal or filing is created.';
        } elseif ($action === 'credit') {
            abort_if((int) $input['origin_year'] >= $year, 422, 'An opening credit must arise before this reporting year. Current-year 2307s are read from posted CWT certificates.');
            $slot = 'credit:'.hash('sha256', json_encode([$year, $input['credit_type'], (int) $input['origin_year'], $amount, trim($input['remarks'] ?? '')], JSON_THROW_ON_ERROR));
            $warnings[] = 'Adds a reviewed opening credit. Check that this balance excludes the current-year 2307s and tagged payments already read by the summary.';
        } elseif ($action === 'nolco') {
            $origin = (int) $input['origin_year'];
            abort_if($amount <= 0 || $origin > $year, 422, 'Enter a positive loss incurred no later than the selected reporting year.');
            abort_if(collect($summary['register'])->contains(fn (array $row): bool => $row['action'] === 'nolco' && (int) $row['origin_year'] === $origin && $row['original'] - $row['reversed'] > 0), 409, 'This loss year is already registered. Reverse an incorrect unused balance before recording a replacement.');
            $data['expires'] = $origin + (in_array($origin, [2020, 2021], true) ? 5 : 3);
            $warnings[] = 'Registers the reviewed tax loss, not an automatically inferred book loss. Verify eligibility and supporting returns. Last application year: '.$data['expires'].'.';
        } elseif ($action === 'apply_nolco' || $action === 'reverse') {
            $target = DB::table('income_tax_summary_events')->where('company_id', $company->id)->where('id', $input['credit_id'])->whereIn('action', ['credit', 'nolco', 'apply_nolco'])->firstOrFail();
            $targetData = json_decode($target->data, true, 512, JSON_THROW_ON_ERROR);
            $registered = collect($summary['register'])->firstWhere('id', $target->id);
            abort_if($amount <= 0 || $amount > $registered['remaining'], 422, 'The amount exceeds the available unreversed balance.');
            if ($action === 'apply_nolco') {
                abort_unless($target->action === 'nolco' && (int) $targetData['origin_year'] < $year && (int) $targetData['expires'] >= $year, 422, 'Choose an unexpired loss from an earlier taxable year.');
                abort_unless(($summary['election']['settings']['deduction_method'] ?? '') === 'itemized', 422, 'NOLCO applications require an itemized-deduction computation.');
                abort_if($amount > max(0, $summary['columns'][$quarter]['amounts']['taxable']), 422, 'NOLCO allocation cannot exceed positive taxable income available in this period.');
                $warnings[] = 'Allocates an additional NOLCO amount to this reporting period. Later cumulative periods include it once. This does not file or amend a return.';
            } else {
                $year = (int) $target->year;
                $quarter = (int) $target->quarter;
                $data['reverses_action'] = $target->action;
                $amount = -$amount;
                $warnings[] = 'Appends a compensating entry. Saved and filed returns remain unchanged and must be reviewed separately.';
            }
        } elseif ($action === 'adjustment') {
            abort_if($amount === 0 || mb_strlen(trim($input['remarks'] ?? '')) < 3, 422, 'Enter a nonzero adjustment and explain its tax basis.');
            $warnings[] = 'Adds a tax-working-paper adjustment without changing the books. Q1–Q3 adjustments carry into later cumulative periods; Annual adds a full-year adjustment.';
        } elseif ($action === 'payment') {
            abort_if($amount <= 0, 422, 'Enter a positive payment amount.');
            $penalty = IncomeTaxSummaryService::cents((string) ($input['penalty'] ?? '0'));
            abort_if($penalty > $amount, 422, 'Penalties cannot exceed the payment.');
            abort_if($input['date'] < $summary['columns'][$quarter]['period']['from'], 422, 'Payment cannot precede the selected reporting period.');
            abort_if(($input['subtype'] ?? 'regular') === 'mcit' && ! $summary['supported'], 422, 'MCIT payment subtype is restricted to regular corporate income tax.');
            $data['penalty_cents'] = $penalty;
            $data['form'] = $summary['columns'][$quarter]['form'];
            $net = $this->account($company, $input['net_account'] ?? '2160', ['Liability'], '2160');
            $prepaid = $this->account($company, $input['prepaid_account'] ?? '1160', ['Asset'], '1160');
            if ($input['post_now'] ?? false) {
                $this->postingPermission($company, $actor);
                abort_if(! empty($input['linked_source']), 422, 'Link an existing entry or post a new payment, not both.');
                $bank = $this->account($company, $input['bank'] ?? '', ['Asset']);
                abort_unless($bank->is_cash_bank && $prepaid->code !== $bank->code && ! $prepaid->is_cash_bank, 422, 'Use distinct prepaid income-tax and cash/bank accounts.');
                $balance = 0;
                if ($net->exists) {
                    $balance = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('l.company_id', $company->id)->where('j.company_id', $company->id)->where('j.status', 'posted')->where('j.transaction_date', '<=', $input['date'])->where('l.chart_account_id', $net->id)->get(['l.debit', 'l.credit'])->sum(fn (object $line): int => IncomeTaxSummaryService::cents((string) $line->credit) - IncomeTaxSummaryService::cents((string) $line->debit));
                }
                $accrued = min($amount - $penalty, max(0, $balance));
                if ($accrued > 0) {
                    $lines[] = $this->line($net, $accrued, 0);
                }
                if ($amount - $penalty - $accrued > 0) {
                    $lines[] = $this->line($prepaid, $amount - $penalty - $accrued, 0);
                }
                if ($penalty > 0) {
                    $lines[] = $this->line($this->account($company, $input['penalty_account'] ?? '7290', ['Expense'], '7290'), $penalty, 0);
                }
                $lines[] = $this->line($bank, 0, $amount);
                $warnings[] = 'Debits accrued Income Tax Payable up to its posted balance; the unaccrued part goes to Prepaid Income Tax. Penalties remain separate.';
            } elseif (! empty($input['linked_source'])) {
                foreach (['income_tax_summary_events', 'vat_summary_events', 'ewt_summary_events', 'compensation_summary_events'] as $table) {
                    abort_if(DB::table($table)->where('company_id', $company->id)->get()->contains(fn (object $event): bool => (json_decode($event->data, true)['linked_source'] ?? null) === $input['linked_source']), 409, 'This journal is already linked to a tax action.');
                }
                $entry = DB::table('journal_entries')->where('company_id', $company->id)->where('source_key', $input['linked_source'])->where('sheet', 'journalEntries')->where('status', 'posted')->firstOrFail();
                abort_if($entry->transaction_date !== $input['date'], 422, 'Payment and linked journal dates must match.');
                $rows = DB::table('journal_entry_lines')->where('company_id', $company->id)->where('journal_entry_id', $entry->id)->get();
                $debit = fn (array $ids): int => $rows->whereIn('chart_account_id', $ids)->sum(fn (object $line): int => IncomeTaxSummaryService::cents((string) $line->debit) - IncomeTaxSummaryService::cents((string) $line->credit));
                $banks = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('is_cash_bank', true)->pluck('id')->all();
                abort_unless($debit(array_filter([$net->id, $prepaid->id])) === $amount - $penalty && -$debit($banks) === $amount, 422, 'The linked entry must match the income-tax debit and cash/bank payment.');
                if ($penalty > 0) {
                    $expense = $this->account($company, $input['penalty_account'] ?? '7290', ['Expense'], '7290');
                    abort_unless($debit([$expense->id]) === $penalty, 422, 'The linked penalty debit must match the tagged penalty.');
                }
                $slot = 'payment-link:'.hash('sha256', $entry->source_key);
                $warnings[] = 'Links the existing posted entry. No new journal is posted.';
            } else {
                $warnings[] = 'Tags this payment only. No journal is posted; reconcile the remittance with your books separately.';
            }
            $slot ??= 'payment:'.hash('sha256', json_encode([$year, $quarter, $input['date'], $amount, trim($input['reference'] ?? '')], JSON_THROW_ON_ERROR));
        }
        if ($lines !== []) {
            abort_if(app(ClientDatabase::class)->central()->table('monthly_closings')->where('company_id', $company->id)->where('month', substr($input['date'], 0, 7))->where('status', 'completed')->exists(), 422, 'Reopen the posting month before recording the journal.');
        }
        abort_if($slot !== null && DB::table('income_tax_summary_events')->where('company_id', $company->id)->where('slot', $slot)->exists(), 409, 'This income tax action is already recorded.');
        $hash = hash('sha256', json_encode([$data, $profile, $summary['ledger_revision'], $summary['last_event'], $summary['columns'], $lines, $amount, $year], JSON_THROW_ON_ERROR));

        return ['preview_hash' => $hash, 'action' => $action, 'year' => $year, 'quarter' => $quarter ?: null, 'amount' => $amount, 'lines' => $lines, 'warnings' => $warnings, 'slot' => $slot, 'data' => $data];
    }

    /** @param array<string, mixed> $input

     * @return array<string, mixed>
     */
    public function store(Request $request, Company $company, array $input): array
    {

        return $this->workspace->mutate($request, $company, function () use ($request, $company, $input): array {

            $payload = $input;

            unset($payload['preview_hash'], $payload['confirmed']);

            ksort($payload);

            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            $existing = DB::table('income_tax_summary_events')->where('company_id', $company->id)->where('request_id', $input['request_id'])->first();

            if ($existing) {

                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This request identifier was already used for different income tax data.');

                return ['id' => $existing->id, 'replayed' => true];

            }

            $preview = $this->preview($company, $request->user(), $input);

            abort_unless(hash_equals($preview['preview_hash'], $input['preview_hash'] ?? ''), 409, 'The books or income tax records changed. Review a fresh preview before confirming.');

            $data = $preview['data'];

            if ($preview['lines'] !== []) {

                foreach ($preview['lines'] as $line) {

                    $this->ensureDefaultAccount($company, $line['account']);

                }

                $journalId = 'itax-'.$input['request_id'];

                $record = ['id' => $journalId, 'number' => '', 'status' => 'posted', 'date' => $input['date'], 'type' => 'General', 'origin' => 'income-tax-'.$input['action'], 'reference' => 'Income tax payment '.($input['reference'] ?? ''), 'lines' => array_map(fn (array $line): array => array_diff_key($line, ['name' => true]), $preview['lines'])];

                $this->ledger->save($company, $request->user(), ['revision' => 0, 'request_id' => $input['request_id'], 'operations' => [['sheet' => 'journalEntries', 'id' => $journalId, 'expected_version' => 0, 'record' => $record]]]);

                $data['linked_source'] = 'journalEntries:'.$journalId;

            }

            $data['preview'] = $preview['lines'];

            $id = DB::table('income_tax_summary_events')->insertGetId(['company_id' => $company->id, 'request_id' => $input['request_id'], 'payload_hash' => $payloadHash, 'action' => $preview['action'], 'year' => $preview['year'], 'quarter' => $preview['quarter'], 'amount' => $preview['amount'], 'actor_id' => $request->user()->id, 'slot' => $preview['slot'], 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['id' => $id, 'replayed' => false];

        });

    }

    private function postingPermission(Company $company, User $actor): void
    {

        $member = $company->users()->where('users.id', $actor->id)->firstOrFail();

        abort_unless(in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403, 'Posting a tax journal requires Sheets access as well as Tax Reports access.');

    }

    private function account(Company $company, string $code, array $types, ?string $virtual = null): ChartAccount
    {

        $account = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->where('archived', false)->first();

        if (! $account && $code === $virtual && ! ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->exists()) {

            $account = new ChartAccount(['code' => $code, ...$this->defaultAccount($code)]);

        }

        abort_unless($account && in_array($account->type, $types, true), 422, 'Select an active '.implode(' / ', $types).' account from this company’s Masters.');

        return $account;

    }

    private function ensureDefaultAccount(Company $company, string $code): void
    {
        if (! in_array($code, ['2160', '1160', '7290'], true)) {
            return;
        }
        $defaults = $this->defaultAccount($code);
        ChartAccount::query()->firstOrCreate(['workspace_key' => $company->workspaceKey(), 'code' => $code], [...$defaults, 'normal_balance' => $defaults['type'] === 'Liability' ? 'Credit' : 'Debit', 'statement_section' => $defaults['type'] === 'Liability' ? 'Current Liabilities' : ($defaults['type'] === 'Asset' ? 'Current Assets' : 'Operating Expenses')]);
    }

    private function defaultAccount(string $code): array
    {
        return match ($code) {
            '2160' => ['name' => 'Income Tax Payable', 'type' => 'Liability'],
            '1160' => ['name' => 'Prepaid Income Tax', 'type' => 'Asset'],
            default => ['name' => 'Penalties & Surcharges', 'type' => 'Expense'],
        };
    }

    private function line(ChartAccount $account, int $debit, int $credit): array
    {

        return ['account' => $account->code, 'name' => $account->name, 'debit' => $debit, 'credit' => $credit];

    }
}
