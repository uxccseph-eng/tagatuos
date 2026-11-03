<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerRecordConcurrency
{
    /** @return array<string, array<string, int>> */
    public function versions(int $companyId, array $state, array $payroll): array
    {
        $stored = $this->stored($companyId);
        $result = [];
        foreach ([...CompanyLedgerService::SHEETS, 'payroll'] as $sheet) {
            $result[$sheet] = [];
            foreach ($sheet === 'payroll' ? $payroll : $state[$sheet] as $record) {
                if (! isset($record['id'])) {
                    continue;
                }
                $id = (string) $record['id'];
                $result[$sheet][$id] = (int) ($stored[$sheet][$id]->version ?? 1);
            }
        }

        return $result;
    }

    /**
     * Apply compare-and-swap operations to the latest state while the ledger row is locked.
     * Deleted records retain a version tombstone to prevent identity reuse and stale recreation.
     *
     * @param  list<array{sheet: string, id: string|int, expected_version: int, record: ?array}>  $operations
     * @return array{state: array, payroll: array}
     */
    public function apply(int $companyId, User $actor, array $state, array $payroll, array $operations): array
    {
        $stored = $this->stored($companyId);
        $sheets = [...$state, 'payroll' => $payroll];
        $indexed = [];
        foreach ([...CompanyLedgerService::SHEETS, 'payroll'] as $sheet) {
            $indexed[$sheet] = collect($sheets[$sheet])->keyBy(fn (array $record): string => (string) $record['id']);
        }
        $seen = [];
        $conflicts = [];
        foreach ($operations as $operation) {
            $sheet = $operation['sheet'];
            $id = (string) $operation['id'];
            $key = $sheet.':'.$id;
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['operations' => 'An entry may appear only once in a save batch.']);
            }
            $seen[$key] = true;
            $records = $indexed[$sheet];
            $before = $records->get($id);
            $version = (int) ($stored[$sheet][$id]->version ?? ($before ? 1 : 0));
            if ($version !== (int) $operation['expected_version'] || (! $before && (int) $operation['expected_version'] !== 0)) {
                $conflicts[] = ['sheet' => $sheet, 'id' => $id, 'number' => $before['number'] ?? '', 'expected_version' => $operation['expected_version'], 'current_version' => $version, 'last_edited_by' => isset($stored[$sheet][$id]) ? app(ClientDatabase::class)->central()->table('users')->where('id', $stored[$sheet][$id]->updated_by)->value('name') : null];

                continue;
            }
            if ($before && ! $this->visible($before, $actor->id)) {
                abort(403, 'This saved draft belongs to another accountant.');
            }
            $after = $operation['record'];
            if ($after === null) {
                $records->forget($id);
            } else {
                if ((string) ($after['id'] ?? '') !== $id) {
                    throw ValidationException::withMessages(['operations' => 'The transaction identity cannot be changed.']);
                }
                $after['id'] = $before['id'] ?? $after['id'];
                unset($after['draft_owner_id']);
                if (($after['status'] ?? '') === 'draft') {
                    $after['draft_owner_id'] = $actor->id;
                } elseif (isset($before['draft_owner_id'])) {
                    $after['draft_owner_id'] = $before['draft_owner_id'];
                }
                if (! $before || ($before['status'] ?? '') === 'draft') {
                    $after['number'] = '';
                    if (in_array($sheet, ['cwtCertificates', 'wtpCertificates']) && ($after['certificate'] ?? '') === ($operation['record']['number'] ?? '')) {
                        $after['certificate'] = '';
                    }
                }
                $records->put($id, $after);
            }
            $indexed[$sheet] = $records;
        }
        if ($conflicts !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Another accountant changed the same entry. Your changes remain in this tab. Review the conflicting entries before saving.',
                'record_conflict' => true, 'conflicts' => $conflicts,
            ], 409));
        }
        foreach ($indexed as $sheet => $records) {
            $sheets[$sheet] = $records->values()->all();
        }
        $payroll = $sheets['payroll'];
        unset($sheets['payroll']);

        return ['state' => $sheets, 'payroll' => $payroll];
    }

    public function visible(array $record, ?int $viewer): bool
    {
        return $viewer === null || ($record['status'] ?? '') !== 'draft' || ! isset($record['draft_owner_id']) || (int) $record['draft_owner_id'] === $viewer;
    }

    /**
     * @param  list<array{sheet: string, id: string, before: ?array, after: ?array}>  $changes
     * @return array<string, array<string, int>>
     */
    public function record(int $companyId, int $actorId, array $changes): array
    {
        $stored = $this->stored($companyId);
        $rows = [];
        $versions = [];
        foreach ($changes as $change) {
            $sheet = $change['sheet'];
            $id = (string) $change['id'];
            $version = (int) ($stored[$sheet][$id]->version ?? ($change['before'] === null ? 0 : 1));
            $versions[$sheet][$id] = $version + 1;
            $rows[] = ['company_id' => $companyId, 'sheet' => $sheet, 'record_id' => $id,
                'version' => $version + 1, 'updated_by' => $actorId, 'created_at' => $stored[$sheet][$id]->created_at ?? now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('company_transaction_versions')->upsert($chunk, ['company_id', 'sheet', 'record_id'], ['version', 'updated_by', 'updated_at']);
        }

        return $versions;
    }

    /** @return array<string, array<string, object>> */
    private function stored(int $companyId): array
    {
        $result = [];
        foreach (DB::table('company_transaction_versions')->where('company_id', $companyId)->get() as $record) {
            $result[$record->sheet][$record->record_id] = $record;
        }

        return $result;
    }
}
