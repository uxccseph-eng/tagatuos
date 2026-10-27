<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLedger;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class ClientFollowUpService
{
    public const CATEGORIES = ['receipt' => 'Invoice / receipt', 'form2307' => 'Form 2307', 'party_details' => 'Customer / supplier details', 'clarification' => 'Amount / transaction clarification'];

    public function __construct(private CompanyBookService $books) {}

    public function company(User $user, int $companyId): Company
    {
        $company = $this->books->findAccessibleBook(User::query()->findOrFail($user->id), $companyId);
        abort_unless(in_array('sheets', TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true), 403, 'Sheets access is required to manage client follow-ups.');

        return $company;
    }

    /** @param array<string, mixed> $filters */
    public function query(int $companyId, array $filters): Builder
    {
        $query = DB::table('client_follow_ups')->select('client_follow_ups.*')->selectRaw('CAST(amount AS CHAR) AS amount')->where('company_id', $companyId);
        if (($filters['status'] ?? 'open') !== 'all') {
            $query->where('status', $filters['status'] ?? 'open');
        }
        if ($filters['category'] ?? null) {
            $query->where('category', $filters['category']);
        }
        if ($filters['q'] ?? null) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(function (Builder $search) use ($term): void {
                foreach (['request_text', 'party', 'reference', 'transaction_number'] as $column) {
                    $search->orWhereRaw('LOWER('.$column.") LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        if ($filters['from'] ?? null) {
            $query->where('transaction_date', '>=', $filters['from']);
        }
        if ($filters['to'] ?? null) {
            $query->where('transaction_date', '<=', $filters['to']);
        }

        return $query;
    }

    public function item(int $companyId, int $itemId): stdClass
    {
        return $this->query($companyId, ['status' => 'all'])->where('id', $itemId)->firstOrFail();
    }

    /** @return list<array{key: string, label: string, date: string}> */
    public function transactions(int $companyId, string $search = ''): array
    {
        $ledger = CompanyLedger::query()->where('company_id', $companyId)->first();
        $transactions = [];
        foreach ([...CompanyLedgerService::SHEETS, 'payroll'] as $sheet) {
            foreach ($sheet === 'payroll' ? ($ledger?->payroll ?? []) : ($ledger?->state[$sheet] ?? []) as $record) {
                if (in_array($record['status'], ['draft', 'posted'], true)) {
                    $transactions[] = ['key' => $sheet.':'.$record['id'], 'label' => ($record['number'] ?? 'Draft').' · '.($record['date'] ?? '').' · '.($record['customer'] ?? $record['supplier'] ?? ucfirst($sheet)), 'date' => $record['date'] ?? ''];
                }
            }
        }

        return collect($transactions)->filter(fn (array $item): bool => $search === '' || str_contains(mb_strtolower($item['label']), mb_strtolower($search)))->sortByDesc('date')->take(200)->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, int $companyId, array $data, ?int $itemId = null): int
    {
        return DB::transaction(function () use ($actor, $companyId, $data, $itemId): int {
            Company::query()->lockForUpdate()->findOrFail($companyId);
            $this->company($actor, $companyId);
            $now = now();
            if ($itemId === null) {
                abort_unless($data['action'] === 'create', 422);
                $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
                $retry = DB::table('client_follow_ups')->where('company_id', $companyId)->where('request_id', $data['request_id'])->first();
                if ($retry) {
                    abort_unless(hash_equals($retry->creation_hash, $hash), 409, 'This request identifier was already used. Reload the checklist.');

                    return $retry->id;
                }
                $snapshot = $this->snapshot($companyId, $data);
                $values = [...$snapshot, 'company_id' => $companyId, 'request_id' => $data['request_id'], 'creation_hash' => $hash, 'category' => $data['category'], 'request_text' => $data['request_text'], 'due_date' => $data['due_date'] ?? null, 'status' => 'open', 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now, 'version' => 1];
                $values['open_key'] = $this->openKey((object) $values);
                $this->checkDuplicate($companyId, $values['open_key']);
                $itemId = DB::table('client_follow_ups')->insertGetId($values);
                $before = null;
            } else {
                abort_if($data['action'] === 'create', 422);
                $before = $this->query($companyId, ['status' => 'all'])->where('id', $itemId)->lockForUpdate()->firstOrFail();
                abort_unless((int) $before->version === (int) $data['version'], 409, 'Another accountant updated this request. Reload before saving.');
                $values = ['version' => $before->version + 1, 'updated_at' => $now];
                if ($data['action'] === 'edit') {
                    abort_unless($before->status === 'open', 422, 'Reopen the request before editing it.');
                    $values += ['request_text' => $data['request_text'], 'due_date' => $data['due_date'] ?? null];
                    $values['open_key'] = $this->openKey((object) [...(array) $before, ...$values]);
                    $this->checkDuplicate($companyId, $values['open_key'], $itemId);
                } elseif ($data['action'] === 'resolve') {
                    abort_unless($before->status === 'open', 422, 'This request is already resolved.');
                    $values += ['status' => 'resolved', 'open_key' => null, 'resolved_by' => $actor->id, 'resolved_at' => $now, 'resolution_note' => $data['note']];
                } else {
                    abort_unless($before->status === 'resolved', 422, 'Only resolved requests can be reopened.');
                    $key = $this->openKey($before);
                    $this->checkDuplicate($companyId, $key, $itemId);
                    $values += ['status' => 'open', 'open_key' => $key, 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null];
                }
                DB::table('client_follow_ups')->where('company_id', $companyId)->where('id', $itemId)->update($values);
            }
            DB::table('client_follow_up_events')->insert(['company_id' => $companyId, 'client_follow_up_id' => $itemId, 'actor_id' => $actor->id, 'action' => $data['action'], 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($this->item($companyId, $itemId), JSON_THROW_ON_ERROR), 'note' => $data['note'] ?? null, 'created_at' => $now]);

            return $itemId;
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function snapshot(int $companyId, array $data): array
    {
        $result = ['linked_sheet' => null, 'linked_record_id' => null, 'transaction_number' => null, 'transaction_date' => $data['transaction_date'] ?? null, 'party' => $data['party'] ?? null, 'reference' => $data['reference'] ?? null, 'amount' => $this->decimal($data['amount'] ?? null)];
        if (empty($data['transaction'])) {
            return $result;
        }
        [$sheet, $id] = array_pad(explode(':', $data['transaction'], 2), 2, '');
        if (! in_array($sheet, [...CompanyLedgerService::SHEETS, 'payroll'], true)) {
            throw ValidationException::withMessages(['transaction' => 'Choose a transaction from this company book.']);
        }
        $ledger = CompanyLedger::query()->where('company_id', $companyId)->first();
        $record = collect($sheet === 'payroll' ? ($ledger?->payroll ?? []) : ($ledger?->state[$sheet] ?? []))->first(fn (array $row): bool => (string) $row['id'] === $id && in_array($row['status'], ['draft', 'posted'], true));
        if (! $record) {
            throw ValidationException::withMessages(['transaction' => 'The selected transaction is missing or no longer active. Reload the list.']);
        }
        $cents = $record['gross'] ?? $record['amount'] ?? $record['taxBase'] ?? null;

        return ['linked_sheet' => $sheet, 'linked_record_id' => $id, 'transaction_number' => $record['number'] ?? null, 'transaction_date' => ($record['date'] ?? '') ?: null, 'party' => $record['customer'] ?? $record['supplier'] ?? null, 'reference' => $record['reference'] ?? null, 'amount' => is_int($cents) && $cents >= 0 ? intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'00' : null];
    }

    private function decimal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return $whole.'.'.str_pad($fraction, 4, '0');
    }

    public static function money(?string $value): string
    {
        if ($value === null) {
            return '—';
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function openKey(stdClass $row): string
    {
        return hash('sha256', $row->linked_sheet ? json_encode([$row->linked_sheet, $row->linked_record_id, $row->category], JSON_THROW_ON_ERROR) : mb_strtolower(json_encode([$row->category, $row->transaction_date, trim($row->party ?? ''), trim($row->reference ?? ''), trim($row->request_text)], JSON_THROW_ON_ERROR)));
    }

    private function checkDuplicate(int $companyId, string $key, ?int $except = null): void
    {
        if (DB::table('client_follow_ups')->where('company_id', $companyId)->where('open_key', $key)->when($except, fn (Builder $query): Builder => $query->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['request_text' => 'An open request for these details already exists. Update the existing request instead.']);
        }
    }
}
