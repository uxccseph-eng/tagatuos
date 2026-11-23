<?php

namespace App\Services;

use App\Models\User;
use App\TeamPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class WorkspacePresence
{
    public const SECTIONS = [
        'dashboard' => ['Home', 'dashboard'], 'setup' => ['Company setup', 'settings'],
        'sales' => ['Sales', 'sheets'], 'collections' => ['Collections', 'sheets'],
        'purchases' => ['Purchases', 'sheets'], 'payments' => ['Payments', 'sheets'],
        'cwt' => ['CWT (2307 Received)', 'sheets'], 'wtp' => ['WTP (2307 Issued)', 'sheets'],
        'payroll' => ['Payroll', 'sheets'], 'journal' => ['Journal', 'sheets'],
        'books' => ['Books', 'books'], 'masters' => ['Masters', 'masters'],
        'reports' => ['Financial Reports', 'reports'], 'tax-reports' => ['Tax Reports', 'tax-reports'],
        'tax-returns' => ['Tax Returns', 'tax-reports'], 'tax-summary' => ['Tax Summary', 'tax-reports'],
        'settings' => ['Settings', 'settings'], 'lapsing' => ['Lapsing (PPE)', 'lapsing'],
        'documents' => ['Documents', 'dashboard'], 'follow-ups' => ['Client follow-ups', 'dashboard'],
        'closing' => ['Monthly closing', 'dashboard'],
        'my-work' => ['My work', null], 'clients' => ['Company books', null], 'account' => ['Account', null],
    ];

    /** @param array{tab_id: string, company_id?: ?int, section: string, idle: bool, visible: bool} $data */
    public function heartbeat(User $user, array $data): void
    {
        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        $module = self::SECTIONS[$data['section']][1];
        if ($companyId !== null) {
            $company = $user->companies()->whereKey($companyId)->wherePivot('is_suspended', false)->first();
            abort_unless($company && ! $company->deletionPending(), 403, 'This company book is not assigned to your account.');
            abort_if($module !== null && ! in_array($module, TeamPermissions::allowed($company->pivot->role, $company->pivot->allowed_tabs), true), 403, 'This module is not enabled for your account.');
        } elseif ($module !== null) {
            throw ValidationException::withMessages(['company_id' => 'Select the company book you are working in.']);
        }

        Cache::lock($this->key($user->id).':lock', 5)->get(function () use ($user, $data, $companyId): void {
            $now = now()->timestamp;
            $tabs = Cache::get($this->key($user->id), []);
            $tabs = is_array($tabs) ? array_filter($tabs, fn (mixed $tab): bool => is_array($tab) && ($tab['seen_at'] ?? 0) >= $now - 90) : [];
            unset($tabs[$data['tab_id']]);
            $tabs = [$data['tab_id'] => [
                'company_id' => $companyId, 'section' => $data['section'], 'seen_at' => $now,
                'idle' => $data['idle'], 'visible' => $data['visible'],
            ], ...$tabs];
            Cache::put($this->key($user->id), array_slice($tabs, 0, 10, true), 120);
        });
    }

    /** @return list<array{user_id: int, name: string, company_id: ?int, company_name: ?string, section: string, status: string, is_you: bool}> */
    public function members(User $viewer): array
    {
        $books = $viewer->companies()->wherePivot('is_suspended', false)->get()->reject(fn ($book): bool => $book->deletionPending())->keyBy('id');
        if ($books->isEmpty()) {
            return [];
        }
        $users = User::query()->where('registration_status', 'approved')->where('must_change_password', false)
            ->whereHas('companies', fn ($query) => $query->whereIn('companies.id', $books->modelKeys())->where('company_user.is_suspended', false))
            ->with(['companies' => fn ($query) => $query->whereIn('companies.id', $books->modelKeys())->wherePivot('is_suspended', false)])
            ->orderBy('name')->get();
        $cached = Cache::many($users->map(fn (User $user): string => $this->key($user->id))->all());
        $members = [];
        foreach ($users as $user) {
            $tabs = $cached[$this->key($user->id)] ?? [];
            $tabs = is_array($tabs) ? array_values(array_filter($tabs, fn (mixed $tab): bool => is_array($tab) && ($tab['seen_at'] ?? 0) >= now()->timestamp - 90 && isset(self::SECTIONS[$tab['section'] ?? '']))) : [];
            usort($tabs, function (array $first, array $second): int {
                $rank = fn (array $tab): int => (int) ($tab['visible'] && ! $tab['idle']);

                return ($rank($second) <=> $rank($first)) ?: ($second['seen_at'] <=> $first['seen_at']);
            });
            $tab = $tabs[0] ?? null;
            if ($tab === null) {
                continue;
            }
            $book = $tab['company_id'] !== null ? $books->get($tab['company_id']) : null;
            $membership = $book ? $user->companies->firstWhere('id', $book->id) : null;
            if ($tab['company_id'] !== null && (! $book || ! $membership)) {
                continue;
            }
            $module = self::SECTIONS[$tab['section']][1];
            if ($module !== null && $membership && ! in_array($module, TeamPermissions::allowed($membership->pivot->role, $membership->pivot->allowed_tabs), true)) {
                continue;
            }
            if ($module !== null && $book && ! in_array($module, TeamPermissions::allowed($book->pivot->role, $book->pivot->allowed_tabs), true)) {
                continue;
            }
            $members[] = [
                'user_id' => $user->id, 'name' => $user->name,
                'company_id' => $book?->id, 'company_name' => $book?->name,
                'section' => self::SECTIONS[$tab['section']][0],
                'status' => $tab['visible'] && ! $tab['idle'] ? 'online' : 'away',
                'is_you' => $viewer->id === $user->id,
            ];
        }
        usort($members, fn (array $first, array $second): int => (($first['status'] === 'away') <=> ($second['status'] === 'away')) ?: ($first['is_you'] <=> $second['is_you']) ?: strcasecmp($first['name'], $second['name']));

        return $members;
    }

    private function key(int $userId): string
    {
        return 'workspace-presence:v1:'.$userId;
    }
}
