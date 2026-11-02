<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanyLedgerService
{
    public const SHEETS = ['sales', 'purchases', 'collections', 'payments', 'cwtCertificates', 'wtpCertificates', 'journalEntries'];

    public function __construct(private LedgerPostingService $posting) {}

    public static function emptyState(): array
    {
        return [...array_fill_keys(self::SHEETS, []), 'nextCollectionId' => 1, 'nextCollectionNumber' => 1];
    }

    public function read(Company $company): array
    {
        return $company->run(fn (): array => $this->readCurrent($company));
    }

    private function readCurrent(Company $company): array
    {
        $ledger = CompanyLedger::query()->where('company_id', $company->id)->first();
        $headers = DB::table('journal_entries')->where('company_id', $company->id)->where('status', 'posted')->orderBy('transaction_date')->orderBy('id')->get();
        $lines = DB::table('journal_entry_lines')->where('company_id', $company->id)->whereIn('journal_entry_id', $headers->pluck('id'))->orderBy('id')->get()->groupBy('journal_entry_id');
        $journals = $headers->map(fn ($header): array => [
            'id' => $header->source_key, 'number' => $header->number, 'date' => $header->transaction_date, 'reference' => $header->reference, 'sheet' => match ($header->sheet) {
                'journalEntries' => 'journal', 'cwtCertificates' => 'cwt', 'wtpCertificates' => 'wtp', default => $header->sheet
            }, 'source' => match ($header->sheet) {
                'journalEntries' => 'Journal', 'cwtCertificates' => 'CWT', 'wtpCertificates' => 'WTP', default => ucfirst($header->sheet)
            },
            'lines' => ($lines[$header->id] ?? collect())->map(fn ($line): array => ['account' => $line->account_name, 'debit' => $this->storedCents((string) $line->debit), 'credit' => $this->storedCents((string) $line->credit), 'party' => $line->party, 'remarks' => $line->remarks])->all(),
        ])->all();

        $withoutDraftNumbers = fn (array $record): array => ($record['status'] ?? null) === 'draft' ? [...$record, 'number' => ''] : $record;
        $state = array_replace(self::emptyState(), $ledger?->state ?? []);
        foreach (self::SHEETS as $sheet) {
            $state[$sheet] = array_map($withoutDraftNumbers, $state[$sheet]);
        }

        return ['company_id' => $company->id, 'revision' => $ledger?->revision ?? 0, 'initialized' => $ledger !== null, 'state' => $state, 'payroll' => array_map($withoutDraftNumbers, $ledger?->payroll ?? []), 'journals' => $journals];
    }

    public function snapshotFor(Company $company, array $tabs): array
    {
        $snapshot = $this->read($company);
        if (in_array('sheets', $tabs, true)) {
            return $snapshot;
        }
        if (array_intersect($tabs, ['books', 'reports', 'tax-reports']) === []) {
            $snapshot['state'] = self::emptyState();
            $snapshot['journals'] = [];
        }
        if (! in_array('tax-reports', $tabs, true)) {
            $snapshot['payroll'] = [];
        }
        foreach ([...self::SHEETS, 'payroll'] as $sheet) {
            $rows = $sheet === 'payroll' ? $snapshot['payroll'] : $snapshot['state'][$sheet];
            $rows = array_map(function (array $record): array {
                unset($record['attachments'], $record['links']);

                return $record;
            }, $rows);
            if ($sheet === 'payroll') {
                $snapshot['payroll'] = $rows;
            } else {
                $snapshot['state'][$sheet] = $rows;
            }
        }

        return $snapshot;
    }

    public function save(Company $company, User $actor, array $data): array
    {
        return app(ClientDatabase::class)->central()->transaction(function () use ($company, $actor, $data): array {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            $result = $company->run(fn (): array => $this->saveCurrent($company, $actor, $data));
            $numbers = [];
            foreach ([...$result['state'], 'payroll' => $result['payroll']] as $sheet => $records) {
                if (is_array($records)) {
                    foreach ($records as $record) {
                        $numbers[$sheet.':'.$record['id']] = $record['number'] ?: null;
                    }
                }
            }
            $central = app(ClientDatabase::class)->central();
            foreach ($central->table('client_follow_ups')->where('company_id', $company->id)->whereNotNull('linked_record_id')->select(['id', 'linked_sheet', 'linked_record_id', 'transaction_number'])->orderBy('id')->cursor() as $followUp) {
                $key = $followUp->linked_sheet.':'.$followUp->linked_record_id;
                if (array_key_exists($key, $numbers) && $followUp->transaction_number !== $numbers[$key]) {
                    $central->table('client_follow_ups')->where('company_id', $company->id)->where('id', $followUp->id)->update(['transaction_number' => $numbers[$key], 'updated_at' => now()]);
                }
            }

            return $result;
        });
    }

    private function saveCurrent(Company $company, User $actor, array $data): array
    {
        return DB::transaction(function () use ($company, $actor, $data): array {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $member = $locked->users()->where('users.id', $actor->id)->where('registration_status', 'approved')->wherePivot('is_suspended', false)->first();
            abort_unless($member && ! $member->must_change_password, 403, 'Your company membership is no longer active.');
            $tabs = TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs);
            abort_unless(in_array('sheets', $tabs, true), 403, 'Your company owner has not enabled Sheets.');
            $hash = hash('sha256', json_encode([$data['state'], $data['payroll']], JSON_THROW_ON_ERROR));
            $receipt = DB::table('company_ledger_changes')->where('company_id', $locked->id)->where('request_id', $data['request_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'This save identifier was already used for a different change.');
                abort_unless((int) CompanyLedger::query()->where('company_id', $locked->id)->value('revision') === (int) $receipt->revision, 409, 'Your save succeeded, and another accountant has since updated the book. Reload the latest records before continuing.');

                return $this->read($locked);
            }
            $ledger = CompanyLedger::query()->where('company_id', $locked->id)->lockForUpdate()->first();
            abort_unless(($ledger?->revision ?? 0) === $data['revision'], 409, 'Another accountant saved this company book. Your changes were not applied. Download your unsaved work, then reload the latest records.');
            $previousState = $ledger?->state ?? self::emptyState();
            $previousPayroll = $ledger?->payroll ?? [];
            $payroll = $data['payroll'];
            $state = $this->validateState($data['state'], $payroll, false);
            app(CashPurchaseWithholding::class)->synchronize($locked, $state, $previousState);
            $baseline = app(TransactionNumbering::class)->normalize($state, $payroll, $previousState, $previousPayroll);
            $state = $this->validateState($state, $payroll);
            $existing = DB::table('journal_entries')->where('company_id', $locked->id)->get()->keyBy('source_key')->all();
            $changes = [];
            $rebuild = [];
            foreach ([...self::SHEETS, 'payroll'] as $sheet) {
                $before = $sheet === 'payroll' ? $previousPayroll : $previousState[$sheet];
                $after = $sheet === 'payroll' ? $payroll : $state[$sheet];
                $old = collect($before)->keyBy(fn (array $record): string => (string) $record['id']);
                $new = collect($after)->keyBy(fn (array $record): string => (string) $record['id']);
                $comparisons = collect($sheet === 'payroll' ? $baseline['payroll'] : $baseline['state'][$sheet])->keyBy(fn (array $record): string => (string) $record['id']);
                foreach ($old as $id => $record) {
                    $replacement = $new->get($id);
                    $comparison = $comparisons->get($id, $record);
                    if (! $replacement && (isset($existing[$sheet.':'.$id]) || $record['status'] !== 'draft')) {
                        $this->fail('Posted and void transactions cannot be deleted. Void or archive them to preserve their history.');
                    }
                    if ($replacement && (isset($existing[$sheet.':'.$id]) || in_array($record['status'], ['posted', 'void', 'archived'], true))) {
                        if (($comparison['number'] ?? '') !== ($replacement['number'] ?? '')) {
                            $this->fail('A posted transaction keeps its original transaction number.');
                        }
                        if ($record['status'] === 'posted' && $replacement['status'] === 'posted') {
                            $this->validatePostedEdit($locked, $sheet, $comparison, $replacement);
                            if ($this->postingContent($comparison) !== $this->postingContent($replacement)) {
                                $rebuild[$sheet.':'.$id] = true;
                            }
                        } elseif ($this->financial($comparison) !== $this->financial($replacement)) {
                            $this->fail('Void and archived transactions retain their original financial details. Restore the entry before editing it.');
                        }
                        if ($record['status'] === 'posted' && $replacement['status'] === 'draft') {
                            $this->fail('Void and duplicate a posted transaction instead of reopening it as a draft.');
                        }
                        if ($record['status'] === 'posted' && $replacement['status'] === 'archived') {
                            $this->fail('Void a posted transaction before archiving it.');
                        }
                    }
                }
                foreach ($new->keys()->merge($old->keys())->unique() as $id) {
                    if ($old->get($id) !== $new->get($id)) {
                        $changes[] = ['sheet' => $sheet, 'id' => (string) $id, 'before' => $old->has($id) ? $this->auditRecord($old[$id]) : null, 'after' => $new->has($id) ? $this->auditRecord($new[$id]) : null];
                    }
                }
            }
            ChartAccount::seedWorkspace($locked->workspaceKey());
            $this->recordImportBatches($locked, $actor, $state, $previousState, $payroll, $previousPayroll);
            DB::table('journal_entries')->where('company_id', $locked->id)->update(['reserved_number' => null]);
            foreach ($this->posting->prepare($locked, $state, $payroll, array_diff_key($existing, $rebuild)) as $voucher) {
                $lines = $voucher['lines'];
                unset($voucher['lines']);
                if (isset($existing[$voucher['source_key']])) {
                    $id = $existing[$voucher['source_key']]->id;
                    DB::table('journal_entry_lines')->where('company_id', $locked->id)->where('journal_entry_id', $id)->delete();
                    DB::table('journal_entries')->where('company_id', $locked->id)->where('id', $id)->update([...$voucher, 'updated_at' => now()]);
                } else {
                    $id = DB::table('journal_entries')->insertGetId([...$voucher, 'reserved_number' => $voucher['number'], 'company_id' => $locked->id, 'status' => 'posted', 'posted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('journal_entry_lines')->insert(array_map(fn (array $line): array => [...$line, 'company_id' => $locked->id, 'journal_entry_id' => $id], $lines));
            }
            foreach ([...self::SHEETS, 'payroll'] as $sheet) {
                foreach ($sheet === 'payroll' ? $payroll : $state[$sheet] as $record) {
                    DB::table('journal_entries')->where('company_id', $locked->id)->where('source_key', $sheet.':'.$record['id'])->update(['status' => $record['status'] === 'posted' ? 'posted' : ($record['status'] === 'archived' ? 'archived' : 'void'), 'number' => $record['number'], 'reserved_number' => $record['status'] === 'archived' ? null : $record['number'], 'reference' => mb_substr((string) ($record['reference'] ?? $record['certificate'] ?? ''), 0, 255), 'updated_at' => now()]);
                }
            }
            $revision = ($ledger?->revision ?? 0) + 1;
            CompanyLedger::query()->updateOrCreate(['company_id' => $locked->id], ['revision' => $revision, 'state' => $state, 'payroll' => $payroll]);
            DB::table('company_ledger_changes')->insert(['company_id' => $locked->id, 'user_id' => $actor->id, 'revision' => $revision, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $this->read($locked);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $state
     * @param  array<string, mixed>  $previous
     */
    private function recordImportBatches(Company $company, User $actor, array $state, array $previous, array $payroll, array $previousPayroll): void
    {
        foreach (['sales', 'purchases', 'payroll'] as $sheet) {
            $old = collect($sheet === 'payroll' ? $previousPayroll : $previous[$sheet])->keyBy(fn (array $record): string => (string) $record['id']);
            $groups = [];
            foreach ($sheet === 'payroll' ? $payroll : $state[$sheet] as $record) {
                if ($record['status'] === 'posted' && ! empty($record['importBatchKey']) && ($old->get((string) $record['id'])['status'] ?? null) !== 'posted') {
                    $groups[$record['importBatchKey']] = ($groups[$record['importBatchKey']] ?? 0) + 1;
                }
            }
            foreach ($groups as $hash => $count) {
                if (DB::table('sheet_import_batches')->where('company_id', $company->id)->where('sheet', $sheet)->where('batch_hash', $hash)->exists()) {
                    $this->fail('This import batch was already posted for this company and sheet. Reload the records before importing again.');
                }
                DB::table('sheet_import_batches')->insert(['company_id' => $company->id, 'sheet' => $sheet, 'batch_hash' => $hash, 'entry_count' => $count, 'posted_by' => $actor->id, 'created_at' => now()]);
            }
        }
    }

    private function validateState(array $state, array &$payroll, bool $requirePostedNumbers = true): array
    {
        $allowed = [...self::SHEETS, 'nextCollectionId', 'nextCollectionNumber'];
        if (array_diff(array_keys($state), $allowed)) {
            $this->fail('The ledger contains unsupported sections.');
        }
        $state = array_replace(self::emptyState(), $state);
        foreach ([...self::SHEETS, 'payroll'] as $sheet) {
            $rows = $sheet === 'payroll' ? $payroll : $state[$sheet];
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 10000) {
                $this->fail('Each sheet must contain a list of at most 10,000 records.');
            }
            $ids = [];
            $numbers = [];
            $drafts = 0;
            foreach ($rows as &$record) {
                if (! is_array($record)) {
                    $this->fail('Every transaction must be an object.');
                }
                $isDraft = ($record['status'] ?? null) === 'draft';
                if ($isDraft) {
                    $record['number'] = '';
                }
                Validator::make($record, [
                    'id' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', (string) $value)) {
                            $fail('Use a valid transaction identity.');
                        }
                    }],
                    'number' => [$requirePostedNumbers && ($record['status'] ?? null) === 'posted' ? 'required' : 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D'],
                    'date' => ['required', 'date_format:Y-m-d'], 'status' => ['required', 'in:draft,posted,void,archived'],
                    'importBatchKey' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                ])->validate();
                if (isset($ids[(string) $record['id']]) || ($record['status'] !== 'archived' && ! empty($record['number']) && isset($numbers[$record['number']]))) {
                    $this->fail('Duplicate transaction identities or numbers are not allowed on '.$sheet.'.');
                }
                $ids[(string) $record['id']] = true;
                if ($record['status'] !== 'archived' && ! empty($record['number'])) {
                    $numbers[$record['number']] = true;
                }
                if ($record['status'] === 'draft' && ($record['origin'] ?? '') !== 'cash-sale' && ($record['origin'] ?? '') !== 'cash-purchase') {
                    $drafts++;
                }
                if ($sheet === 'payroll' && $record['status'] === 'posted') {
                    Validator::make($record, ['periodStart' => ['required', 'date_format:Y-m-d'], 'periodEnd' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodStart'], 'employees' => ['required', 'array', 'list', 'min:1', 'max:1000']])->validate();
                }
                if ($record['status'] === 'void' && ! in_array($record['origin'] ?? '', ['cash-sale', 'cash-purchase'], true) && trim((string) ($record['voidReason'] ?? $record['voidAudit']['reason'] ?? $record['voidNote'] ?? '')) === '') {
                    $this->fail('A void transaction must include its reason.');
                }
            }
            unset($record);
            if ($sheet === 'payroll') {
                $payroll = $rows;
            } else {
                $state[$sheet] = $rows;
            }
            if ($drafts > 1) {
                $this->fail('Only one saved draft is allowed per company sheet. Another accountant may already have saved a draft.');
            }
        }

        return $state;
    }

    private function financial(array $record): string
    {
        $fields = ['id', 'number', 'date', 'partyId', 'customer', 'supplier', 'account', 'bank', 'gross', 'amount', 'tax', 'cwt', 'vatCwt', 'ewt', 'wvat', 'ewtCode', 'wvatCode', 'offset', 'taxBase', 'atc', 'atcCode', 'rate', 'invoice', 'saleId', 'bill', 'billId', 'type', 'terms', 'lines', 'employees', 'periodStart', 'periodEnd', 'payrollAllocations', 'origin'];

        if (! empty($record['clientPayrollImport'])) {
            $fields = [...$fields, 'clientPayrollImport', 'importReviewVersion', 'frequency'];
        }

        return json_encode($this->canonical(array_intersect_key($record, array_flip($fields))), JSON_THROW_ON_ERROR);
    }

    private function postingContent(array $record): string
    {
        return $this->financial($record).json_encode($this->canonical(array_intersect_key($record, array_flip(['reference', 'certificate', 'particulars', 'memo', 'description', 'notes']))), JSON_THROW_ON_ERROR);
    }

    private function validatePostedEdit(Company $company, string $sheet, array $before, array $after): void
    {
        foreach (['origin', 'importBatchKey', 'clientPayrollImport', 'saleId', 'payrollRunId', 'payrollAllocations'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $this->fail('The source and import links of a posted transaction cannot be changed.');
            }
        }
        if ($sheet === 'wtpCertificates' && (string) ($before['billId'] ?? '') !== (string) ($after['billId'] ?? '')) {
            $this->fail('Void and replace the issued certificate to change its source purchase.');
        }
        if (($before['origin'] ?? '') === 'payroll-remittance' && $this->financial($before) !== $this->financial($after)) {
            $this->fail('Void this generated payroll remittance and generate a replacement from Payroll to change its date or amounts.');
        }
        if ($this->postingContent($before) !== $this->postingContent($after)
            && app(ClientDatabase::class)->central()->table('monthly_closings')->where('company_id', $company->id)->where('status', 'completed')->whereIn('month', [substr($before['date'], 0, 7), substr($after['date'], 0, 7)])->exists()) {
            $this->fail('Reopen the completed month before editing a posted transaction in or into that month.');
        }
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    private function auditRecord(array $record): array
    {
        unset($record['attachments']);

        return $record;
    }

    private function storedCents(string $amount): int
    {
        $parts = explode('.', $amount);

        return (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ledger' => $message]);
    }
}
