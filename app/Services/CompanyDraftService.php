<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\TeamPermissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CompanyDraftService
{
    public function authorize(Company $company, User $user): void
    {
        $member = $company->users()->whereKey($user->id)->wherePivot('is_suspended', false)->first();
        abort_unless($user->hasApprovedAccess() && ! $user->must_change_password && $member && in_array('sheets', TeamPermissions::allowed($member->pivot->role, $member->pivot->allowed_tabs), true), 403, 'You do not have access to private sheet drafts in this company.');
    }

    /** @return list<array{id: string, version: int, updated_at: string}> */
    public function listing(Company $company, User $user): array
    {
        $this->authorize($company, $user);

        $requests = $this->query($company, $user)->whereNotNull('commit_request_id')->pluck('commit_request_id');
        if ($requests->isNotEmpty()) {
            $confirmed = DB::table('company_ledger_changes')->where('company_id', $company->id)->whereIn('request_id', $requests)->pluck('request_id');
            $this->query($company, $user)->whereIn('commit_request_id', $confirmed)->delete();
        }

        return $this->query($company, $user)->orderByDesc('updated_at')->get(['draft_id', 'version', 'updated_at'])->map(fn (object $row): array => $this->metadata($row))->all();
    }

    /** @return array{id: string, version: int, updated_at: string, payload: array} */
    public function read(Company $company, User $user, string $draftId): array
    {
        $this->authorize($company, $user);
        $row = $this->query($company, $user)->where('draft_id', $draftId)->first();
        abort_unless($row, 404);

        return [...$this->metadata($row), 'payload' => json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR)];
    }

    /** @return array{id: string, version: int, updated_at: string} */
    public function save(Company $company, User $user, string $draftId, int $version, array $payload): array
    {
        return app(ClientDatabase::class)->central()->transaction(function () use ($company, $user, $draftId, $version, $payload): array {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->authorize($company, $user);
            abort_unless((string) $payload['company_id'] === (string) $company->id, 409, 'This draft belongs to another company.');
            $query = $this->query($company, $user)->where('draft_id', $draftId);
            $current = $query->lockForUpdate()->first();
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            if ($current && (int) $current->version > $version && hash_equals(hash('sha256', Crypt::decryptString($current->payload)), hash('sha256', $encoded))) {
                return $this->metadata($current);
            }
            abort_unless((int) ($current->version ?? 0) === $version, 409, 'This private draft changed in another tab. Keep a separate copy.');
            abort_if(! $current && $this->query($company, $user)->count() >= 25, 422, 'Resume or discard an older private draft before creating another.');
            $commitRequest = isset($payload['request']) && $payload['changes'] === $payload['request']['operations'] ? $payload['request']['request_id'] : null;
            $values = ['version' => $version + 1, 'payload' => Crypt::encryptString($encoded), 'updated_at' => now(), 'commit_request_id' => $commitRequest];
            if ($current) {
                $query->update($values);
            } else {
                $query->insert([...$values, 'company_id' => $company->id, 'user_id' => $user->id, 'draft_id' => $draftId, 'created_at' => now()]);
            }

            return ['id' => $draftId, 'version' => $version + 1, 'updated_at' => now()->toIso8601String()];
        });
    }

    public function delete(Company $company, User $user, string $draftId, int $version): void
    {
        $this->authorize($company, $user);
        $deleted = $this->query($company, $user)->where('draft_id', $draftId)->where('version', $version)->delete();
        abort_if(! $deleted && $this->query($company, $user)->where('draft_id', $draftId)->exists(), 409, 'A newer private draft exists. It has been kept.');
    }

    private function query(Company $company, User $user): Builder
    {
        return app(ClientDatabase::class)->central()->table('company_ledger_drafts')->where('company_id', $company->id)->where('user_id', $user->id);
    }

    /** @return array{id: string, version: int, updated_at: string} */
    private function metadata(object $row): array
    {
        return ['id' => $row->draft_id, 'version' => (int) $row->version, 'updated_at' => Carbon::parse($row->updated_at)->toIso8601String()];
    }
}
