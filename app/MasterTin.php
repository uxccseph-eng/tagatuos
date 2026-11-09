<?php

namespace App;

use App\Models\Employee;
use App\Models\Party;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterTin
{
    public static function assertUnique(string $registry, string $workspaceKey, ?string $tin, ?int $ignoreId = null): void
    {
        if (! in_array($registry, ['parties', 'employees'], true) || Tin::key($tin) === null) {
            return;
        }
        $model = $registry === 'parties' ? Party::class : Employee::class;
        $match = $model::query()->where('workspace_key', $workspaceKey)
            ->whereIn(DB::raw("REPLACE(TRIM(tin), '-', '')"), Tin::equivalentDigits($tin))
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->first();
        if ($match !== null) {
            $guidance = $registry === 'parties' ? ' Use the existing party; select Both if it is a customer and supplier.' : ' Use the existing employee.';
            if ($match->archived) {
                $guidance = ' Restore or correct the archived record instead of creating a duplicate.';
            }
            throw ValidationException::withMessages(['tin' => 'This TIN and branch already belong to '.$match->code.' - '.$match->name.'.'.$guidance]);
        }
    }

    public static function write(string $registry, string $workspaceKey, callable $callback): mixed
    {
        if (! in_array($registry, ['parties', 'employees'], true)) {
            return $callback();
        }
        try {
            return Cache::lock('masters-import-'.hash('sha256', $workspaceKey), 120)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['tin' => 'Another accountant is updating Masters. Please try again.']);
        }
    }
}
