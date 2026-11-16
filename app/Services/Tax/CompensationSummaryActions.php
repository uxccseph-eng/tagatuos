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

class CompensationSummaryActions
{
    public function __construct(private CompensationSummaryService $summary, private TaxReturnWorkspace $workspace, private CompanyLedgerService $ledger, private TaxReturnCatalog $catalog) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(Company $company, User $actor, array $input): array
    {
        $profile = CompanySetting::query()->where('company_id', $company->id)->firstOrFail()->profile;
        $year = (int) $input['year'];
        $period = $this->catalog->period($input['form'], $year, (int) $input['period'], $profile);
        abort_unless($period['applicable'], 422, $period['reason']);
        $month = (int) $input['period'];
        $summary = $this->summary->build($company, $profile, $year);
        $amount = $this->cents($input['amount']);
        $data = $input;
        unset($data['preview_hash'], $data['confirmed'], $data['request_id']);
        $lines = [];
        $warnings = [];
        $slot = null;
        abort_if($amount <= 0, 422, 'Enter a positive payment amount.');

        $penalty = $this->cents($input['penalty'] ?? '0');

        abort_if($penalty > $amount, 422, 'Penalties cannot exceed the payment amount.');

        abort_if($input['date'] < $period['from'], 422, 'The payment date cannot precede the selected reporting month.');

        $data['penalty_cents'] = $penalty;

        if ($input['post_now'] ?? false) {

            $this->postingPermission($company, $actor);
            abort_if(($input['method'] ?? '') === 'Tax Debit Memo', 422, 'Tag a Tax Debit Memo without cash posting; record its noncash settlement separately.');

            abort_if(! empty($input['linked_source']), 422, 'Choose an existing entry or post a new one, not both.');

            $net = $this->account($company, $input['net_account'] ?? '2350', ['Liability'], '2350');

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
            foreach (['vat_summary_events', 'income_tax_summary_events', 'ewt_summary_events'] as $table) {
                abort_if(DB::table($table)->where('company_id', $company->id)->get()->contains(fn (object $event): bool => (json_decode($event->data, true)['linked_source'] ?? null) === $input['linked_source']), 409, 'This journal is already linked to a tax action.');
            }

            abort_if(DB::table('compensation_summary_events')->where('company_id', $company->id)->where('action', 'payment')->get()->contains(fn (object $event): bool => (json_decode($event->data, true)['linked_source'] ?? null) === $input['linked_source']), 409, 'This journal is already linked to an compensation action.');
            $entry = DB::table('journal_entries')->where('company_id', $company->id)->where('source_key', $input['linked_source'])->where('status', 'posted')->where('sheet', 'journalEntries')->firstOrFail();

            abort_if($entry->transaction_date !== $input['date'], 422, 'The payment date must match the linked journal date.');

            $entryLines = DB::table('journal_entry_lines')->where('company_id', $company->id)->where('journal_entry_id', $entry->id)->get();

            $net = $this->account($company, $input['net_account'] ?? '2350', ['Liability'], '2350');

            $taxDebit = $entryLines->where('chart_account_id', $net->id)->sum(fn (object $row): int => $this->cents((string) $row->debit) - $this->cents((string) $row->credit));

            $cashCodes = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('is_cash_bank', true)->where('archived', false)->pluck('id')->all();

            $cashCredit = $entryLines->whereIn('chart_account_id', $cashCodes)->sum(fn (object $row): int => $this->cents((string) $row->credit) - $this->cents((string) $row->debit));

            abort_unless($taxDebit === $amount - $penalty && $cashCredit === $amount, 422, 'The linked entry must debit net compensation payable and credit cash/bank by the tagged payment amounts.');

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

        $slot ??= 'payment:'.hash('sha256', json_encode([$input['form'], $year, $input['period'], $input['date'], $amount, trim($input['reference'] ?? '')], JSON_THROW_ON_ERROR));

        $open = $summary['months'][$month]['amounts']['open'];

        if ($open !== null && $amount > $open) {

            $warnings[] = 'This payment exceeds the currently calculated open balance. Verify its period and amount before confirming.';

        }

        if ($lines !== []) {
            abort_if(app(ClientDatabase::class)->central()->table('monthly_closings')->where('company_id', $company->id)->where('month', substr($input['date'], 0, 7))->where('status', 'completed')->exists(), 422, 'Reopen the completed posting month before recording this entry.');
        }
        abort_if(DB::table('compensation_summary_events')->where('company_id', $company->id)->where('slot', $slot)->exists(), 409, 'This payment is already recorded.');
        $hash = hash('sha256', json_encode([$data, $profile, $summary['revision'], $summary['events'], $summary['months'], $lines, $slot], JSON_THROW_ON_ERROR));

        return ['preview_hash' => $hash, 'action' => 'payment', 'year' => $year, 'month' => $month, 'amount' => $amount, 'lines' => $lines, 'warnings' => $warnings, 'slot' => $slot, 'data' => $data];
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

            $existing = DB::table('compensation_summary_events')->where('company_id', $company->id)->where('request_id', $input['request_id'])->first();

            if ($existing) {

                abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This request identifier was already used for different compensation data.');

                return ['id' => $existing->id, 'replayed' => true];

            }

            $preview = $this->preview($company, $request->user(), $input);

            abort_unless(hash_equals($preview['preview_hash'], $input['preview_hash'] ?? ''), 409, 'The books or compensation records changed. Review a fresh preview before confirming.');

            $data = $preview['data'];

            if ($preview['lines'] !== []) {

                foreach ($preview['lines'] as $line) {

                    $this->ensureDefaultAccount($company, $line['account']);

                }

                $journalId = 'compensation-'.$input['request_id'];

                $record = ['id' => $journalId, 'number' => '', 'status' => 'posted', 'date' => $input['date'], 'type' => 'General', 'origin' => 'compensation-'.$input['action'], 'reference' => 'compensation payment '.$input['form'].' '.($input['reference'] ?? ''), 'lines' => array_map(fn (array $line): array => array_diff_key($line, ['name' => true]), $preview['lines'])];

                $this->ledger->save($company, $request->user(), ['revision' => 0, 'request_id' => $input['request_id'], 'operations' => [['sheet' => 'journalEntries', 'id' => $journalId, 'expected_version' => 0, 'record' => $record]]]);

                $data['linked_source'] = 'journalEntries:'.$journalId;

            }

            $data['preview'] = $preview['lines'];

            $id = DB::table('compensation_summary_events')->insertGetId(['company_id' => $company->id, 'request_id' => $input['request_id'], 'payload_hash' => $payloadHash, 'action' => $preview['action'], 'year' => $preview['year'], 'month' => $preview['month'], 'amount' => $preview['amount'], 'actor_id' => $request->user()->id, 'slot' => $preview['slot'], 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['id' => $id, 'replayed' => false];

        });

    }

    private function postingPermission(Company $company, User $actor): void
    {

        $member = $company->users()->where('users.id', $actor->id)->firstOrFail();

        abort_unless(in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403, 'Posting a compensation journal requires Sheets access as well as Tax Reports access.');

    }

    private function account(Company $company, string $code, array $types, ?string $virtual = null): ChartAccount
    {

        $account = ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->where('archived', false)->first();

        if (! $account && $code === $virtual && ! ChartAccount::query()->where('workspace_key', $company->workspaceKey())->where('code', $code)->exists()) {

            $account = new ChartAccount(['code' => $code, 'name' => $code === '2350' ? 'WHT Payable - Compensation' : 'Penalties & Surcharges', 'type' => $code === '2350' ? 'Liability' : 'Expense']);

        }

        abort_unless($account && in_array($account->type, $types, true), 422, 'Select an active '.implode(' / ', $types).' account from this company’s Masters.');

        return $account;

    }

    private function ensureDefaultAccount(Company $company, string $code): void
    {

        if (! in_array($code, ['2350', '7290'], true)) {

            return;

        }

        ChartAccount::query()->firstOrCreate(['workspace_key' => $company->workspaceKey(), 'code' => $code], ['name' => $code === '2350' ? 'WHT Payable - Compensation' : 'Penalties & Surcharges', 'type' => $code === '2350' ? 'Liability' : 'Expense', 'normal_balance' => $code === '2350' ? 'Credit' : 'Debit', 'statement_section' => $code === '2350' ? 'Current Liabilities' : 'Operating Expenses']);

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
