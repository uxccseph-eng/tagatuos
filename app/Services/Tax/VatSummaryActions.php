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

class VatSummaryActions
{
    public function __construct(private VatSummaryService $summary, private TaxReturnWorkspace $workspace, private CompanyLedgerService $ledger) {}

    /** @param array<string, mixed> $input

     * @return array<string, mixed>
     */
    public function preview(Company $company, User $actor, array $input): array
    {

        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;

        $summary = $this->summary->build($company, $profile, (int) $input['year']);

        abort_unless($summary['applicable'], 422, 'Confirm VAT registration in Settings before recording VAT actions.');

        $action = $input['action'];

        $year = (int) $input['year'];

        $quarter = (int) ($input['quarter'] ?? 0);

        $amount = $this->cents($input['amount'] ?? '0');

        $data = $input;

        unset($data['preview_hash'], $data['confirmed'], $data['request_id']);

        $slot = null;

        $lines = [];

        $warnings = [];

        $targetYear = $year;

        if ($action === 'credit') {

            $remainingCarry = collect($summary['workspace']['credits'])->whereIn('id', $summary['workspace']['events']->where('action', 'carryover')->pluck('id')->all())->sum('remaining');
            abort_if($remainingCarry > 0, 422, 'A carryover already supplies this year’s opening input VAT. Reverse it before adding a replacement opening credit.');

            $warnings[] = 'This is an opening input VAT credit for Q1 '.$year.'. It does not post a journal entry. Zero explicitly confirms a nil opening balance.';

        } elseif ($action === 'reverse') {

            $credit = DB::table('vat_summary_events')->where('company_id', $company->id)->where('id', $input['credit_id'])->whereIn('action', ['credit', 'carryover'])->firstOrFail();

            $reversed = DB::table('vat_summary_events')->where('company_id', $company->id)->where('action', 'reverse')->get()->sum(fn (object $row): int => (int) (json_decode($row->data, true)['credit_id'] ?? 0) === $credit->id ? -$row->amount : 0);

            abort_unless($credit->year === $year || (int) $credit->year === $year, 422, 'Choose a credit from this reporting year.');

            abort_if($amount <= 0 || $amount > $credit->amount - $reversed, 422, 'The reversal must be positive and cannot exceed the unreversed credit.');

            $data['credit_id'] = $credit->id;

            $amount = -$amount;

            $warnings[] = 'Adds a compensating credit entry. Existing returns and subsequent recorded carryovers remain unchanged and need review.';

        } elseif ($action === 'carryover') {

            $end = $summary['quarters'][4]['period']['to'];

            abort_if($end > now('Asia/Manila')->toDateString(), 422, 'Finish the reporting year before recording its year-end carryover.');

            $amount = $summary['workspace']['totals']['input'];

            abort_if($amount === null || $amount <= 0, 422, 'Confirm the opening balance and all source mappings first. There is no confirmed excess input VAT to carry.');

            $targetYear = $year + 1;

            abort_if(DB::table('vat_summary_events')->where('company_id', $company->id)->where('year', $targetYear)->whereIn('action', ['credit', 'carryover'])->exists(), 422, 'The next year already has opening credits. Review them rather than recording a duplicate carryover.');

            $slot = 'carryover:'.$targetYear;

            $data['from_year'] = $year;

            $warnings[] = 'Records opening input VAT in '.$targetYear.'. It does not post a journal entry or change a filed return.';

        } elseif ($action === 'payment') {

            abort_if($amount <= 0, 422, 'Enter a positive payment amount.');

            $penalty = $this->cents($input['penalty'] ?? '0');

            abort_if($penalty > $amount, 422, 'Penalties cannot exceed the payment amount.');

            $period = $summary['quarters'][$quarter]['period'];

            abort_if($input['date'] < $period['from'], 422, 'The payment date cannot precede the selected reporting quarter.');

            $data['penalty_cents'] = $penalty;
            abort_if(in_array($input['net_account'] ?? '2205', ['2210', '2110'], true), 422, 'Use a separate net VAT payable account rather than the output or input VAT account.');

            if ($input['post_now'] ?? false) {

                $this->postingPermission($company, $actor);

                abort_if(! empty($input['linked_source']), 422, 'Choose an existing entry or post a new one, not both.');

                $net = $this->account($company, $input['net_account'] ?? '2205', ['Liability'], '2205');

                $bank = $this->account($company, $input['bank'] ?? '', ['Asset']);

                abort_unless($bank->is_cash_bank, 422, 'Select an active cash or bank account.');

                if ($amount > $penalty) {

                    $lines[] = $this->line($net, $amount - $penalty, 0);

                }

                if ($penalty) {

                    $lines[] = $this->line($this->account($company, $input['penalty_account'] ?? '7290', ['Expense'], '7290'), $penalty, 0);

                }

                $lines[] = $this->line($bank, 0, $amount);

            } elseif (! empty($input['linked_source'])) {
                abort_if(DB::table('ewt_summary_events')->where('company_id', $company->id)->get()->contains(fn (object $event): bool => (json_decode($event->data, true)['linked_source'] ?? null) === $input['linked_source']), 409, 'This journal is already linked to an EWT payment.');

                abort_if(DB::table('vat_summary_events')->where('company_id', $company->id)->whereIn('action', ['payment', 'close'])->get()->contains(fn (object $event): bool => (json_decode($event->data, true)['linked_source'] ?? null) === $input['linked_source']), 409, 'This journal is already linked to a VAT action.');
                $entry = DB::table('journal_entries')->where('company_id', $company->id)->where('source_key', $input['linked_source'])->where('status', 'posted')->where('sheet', 'journalEntries')->firstOrFail();

                abort_if($entry->transaction_date !== $input['date'], 422, 'The payment date must match the linked journal date.');

                $entryLines = DB::table('journal_entry_lines')->where('company_id', $company->id)->where('journal_entry_id', $entry->id)->get();

                $net = $this->account($company, $input['net_account'] ?? '2205', ['Liability'], '2205');

                $taxDebit = $entryLines->where('chart_account_id', $net->id)->sum(fn (object $row): int => $this->cents((string) $row->debit) - $this->cents((string) $row->credit));

                $cashCodes = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('is_cash_bank', true)->where('archived', false)->pluck('id')->all();

                $cashCredit = $entryLines->whereIn('chart_account_id', $cashCodes)->sum(fn (object $row): int => $this->cents((string) $row->credit) - $this->cents((string) $row->debit));

                abort_unless($taxDebit === $amount - $penalty && $cashCredit === $amount, 422, 'The linked entry must debit net VAT payable and credit cash/bank by the tagged payment amounts.');

                if ($penalty > 0) {

                    $expense = $this->account($company, $input['penalty_account'] ?? '7290', ['Expense'], '7290');

                    $penaltyDebit = $entryLines->where('chart_account_id', $expense->id)->sum(fn (object $row): int => $this->cents((string) $row->debit) - $this->cents((string) $row->credit));

                    abort_unless($penaltyDebit === $penalty, 422, 'The linked penalty debit must match the tagged penalty amount.');

                }

                $slot = 'payment-link:'.hash('sha256', $entry->source_key);

                $warnings[] = 'Links the posted entry. No additional journal will be posted.';

            } else {

                $warnings[] = 'Tag only: the payment will appear in Tax Summary but no journal entry will be posted. Record the remittance in your books separately; do not tag it again.';

            }

            $slot ??= 'payment:'.hash('sha256', json_encode([$year, $quarter, $input['date'], $amount, trim($input['reference'] ?? '')], JSON_THROW_ON_ERROR));

            $open = $summary['workspace']['quarters'][$quarter]['open'];

            if ($open !== null && $amount > $open) {

                $warnings[] = 'This payment exceeds the currently calculated open balance. Verify its period and amount before confirming.';

            }

        } elseif ($action === 'close') {

            $this->postingPermission($company, $actor);

            $period = $summary['quarters'][$quarter]['period'];

            abort_if($period['to'] > $input['date'], 422, 'Date the VAT close on or after the quarter’s last day.');

            abort_if($summary['quarters'][$quarter]['issues'] !== [], 422, 'Resolve VAT source mapping issues before closing the quarter.');

            $net = $this->account($company, $input['net_account'] ?? '2205', ['Liability'], '2205');

            $accounts = [$this->account($company, $input['output_account'] ?? '2210', ['Liability']), $this->account($company, $input['input_account'] ?? '2110', ['Asset'])];

            if (! empty($input['vat_credit_account'])) {

                $creditAccount = $this->account($company, $input['vat_credit_account'], ['Asset']);
                abort_if($creditAccount->code === '1150' || $creditAccount->is_cash_bank, 422, 'Select a dedicated VAT-withheld asset account, not income-tax CWT or cash/bank.');
                $accounts[] = $creditAccount;

                $warnings[] = 'The optional VAT-withheld account must contain only VAT credits, not income-tax CWT.';

            }

            abort_if(count(array_unique([$net->code, ...array_column($accounts, 'code')])) !== count($accounts) + 1, 422, 'Choose distinct VAT and net payable accounts.');

            $debit = 0;
            $movements = $this->summary->movements($company, $period, array_map(fn (ChartAccount $account): string => $account->code, $accounts));
            $data['_close_movement'] = $movements;
            foreach ($accounts as $account) {
                $movement = $movements[$account->code];
                if ($movement !== 0) {
                    $lines[] = $this->line($account, max(0, -$movement), max(0, $movement));
                    $debit -= $movement;
                }
            }
            abort_if($lines === [], 422, 'This quarter has no posted VAT account movement to close.');

            if ($debit !== 0) {

                $lines[] = $this->line($net, max(0, -$debit), max(0, $debit));

            }

            $slot = 'close:'.$year.':'.$quarter;

            $warnings[] = 'Posts a balanced journal from actual VAT account movements. This entry is excluded from the sales/purchases VAT return mapping. Review manual journal adjustments separately.';

        }

        if ($lines !== []) {

            abort_if(app(ClientDatabase::class)->central()->table('monthly_closings')->where('company_id', $company->id)->where('month', substr($input['date'], 0, 7))->where('status', 'completed')->exists(), 422, 'Reopen the completed posting month before recording this entry.');

        }

        abort_if($slot !== null && DB::table('vat_summary_events')->where('company_id', $company->id)->where('slot', $slot)->exists(), 409, 'This action is already recorded. Refresh the summary to see it.');

        $hash = hash('sha256', json_encode([$data, $profile, $summary['revision'], $summary['workspace']['last_event'], $summary['quarters'], $lines, $amount, $targetYear], JSON_THROW_ON_ERROR));

        return ['preview_hash' => $hash, 'action' => $action, 'year' => $targetYear, 'quarter' => $quarter ?: null, 'amount' => $amount, 'lines' => $lines, 'warnings' => $warnings, 'slot' => $slot, 'data' => $data];

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

            $existing = DB::table('vat_summary_events')->where('company_id', $company->id)->where('request_id', $input['request_id'])->first();

            if ($existing) {

                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This request identifier was already used for different VAT data.');

                return ['id' => $existing->id, 'replayed' => true];

            }

            $preview = $this->preview($company, $request->user(), $input);

            abort_unless(hash_equals($preview['preview_hash'], $input['preview_hash'] ?? ''), 409, 'The books or VAT records changed. Review a fresh preview before confirming.');

            $data = $preview['data'];

            if ($preview['lines'] !== []) {

                foreach ($preview['lines'] as $line) {

                    $this->ensureDefaultAccount($company, $line['account']);

                }

                $journalId = 'vat-'.$input['request_id'];

                $record = ['id' => $journalId, 'number' => '', 'status' => 'posted', 'date' => $input['date'], 'type' => 'General', 'origin' => 'vat-'.$input['action'], 'reference' => $input['action'] === 'close' ? 'VAT close Q'.$input['quarter'].' '.$input['year'] : 'VAT payment '.($input['reference'] ?? ''), 'lines' => array_map(fn (array $line): array => array_diff_key($line, ['name' => true]), $preview['lines'])];

                $this->ledger->save($company, $request->user(), ['revision' => 0, 'request_id' => $input['request_id'], 'operations' => [['sheet' => 'journalEntries', 'id' => $journalId, 'expected_version' => 0, 'record' => $record]]]);

                $data['linked_source'] = 'journalEntries:'.$journalId;

            }

            $data['preview'] = $preview['lines'];

            $id = DB::table('vat_summary_events')->insertGetId(['company_id' => $company->id, 'request_id' => $input['request_id'], 'payload_hash' => $payloadHash, 'action' => $preview['action'], 'year' => $preview['year'], 'quarter' => $preview['quarter'], 'amount' => $preview['amount'], 'actor_id' => $request->user()->id, 'slot' => $preview['slot'], 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['id' => $id, 'replayed' => false];

        });

    }

    private function postingPermission(Company $company, User $actor): void
    {

        $member = $company->users()->where('users.id', $actor->id)->firstOrFail();

        abort_unless(in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403, 'Posting a VAT journal requires Sheets access as well as Tax Reports access.');

    }

    private function account(Company $company, string $code, array $types, ?string $virtual = null): ChartAccount
    {

        $account = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->where('archived', false)->first();

        if (! $account && $code === $virtual && ! ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->exists()) {

            $account = new ChartAccount(['code' => $code, 'name' => $code === '2205' ? 'VAT Payable - Net of Input Tax' : 'Penalties & Surcharges', 'type' => $code === '2205' ? 'Liability' : 'Expense']);

        }

        abort_unless($account && in_array($account->type, $types, true), 422, 'Select an active '.implode(' / ', $types).' account from this company’s Masters.');

        return $account;

    }

    private function ensureDefaultAccount(Company $company, string $code): void
    {

        if (! in_array($code, ['2205', '7290'], true)) {

            return;

        }

        ChartAccount::query()->firstOrCreate(['workspace_key' => $company->workspaceKey(), 'code' => $code], ['name' => $code === '2205' ? 'VAT Payable - Net of Input Tax' : 'Penalties & Surcharges', 'type' => $code === '2205' ? 'Liability' : 'Expense', 'normal_balance' => $code === '2205' ? 'Credit' : 'Debit', 'statement_section' => $code === '2205' ? 'Current Liabilities' : 'Operating Expenses']);

    }

    private function line(ChartAccount $account, int $debit, int $credit): array
    {

        return ['account' => $account->code, 'name' => $account->name, 'debit' => $debit, 'credit' => $credit];

    }

    private function cents(string $value): int
    {

        abort_unless(preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,2})(?:00)?)?$/D', $value, $parts), 422, 'Enter a valid amount with up to two decimal places.');

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

    }
}
