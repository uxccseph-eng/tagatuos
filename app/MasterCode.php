<?php

namespace App;

use App\Models\ChartAccount;
use App\Models\Employee;
use App\Models\Party;
use App\Models\TaxCode;
use Illuminate\Validation\ValidationException;

class MasterCode
{
    /** @param list<string> $reserved */
    public static function account(string $workspaceKey, string $type, array $reserved = []): string
    {
        $used = array_unique([...ChartAccount::query()->where('workspace_key', $workspaceKey)->pluck('code')->all(), ...array_column(ChartAccount::defaults(), 'code'), ...$reserved]);
        [$first, $last] = match ($type) {
            'Liability' => [2000, 2999], 'Equity' => [3000, 3999], 'Income' => [4000, 4999], 'Expense' => [5000, 9999], default => [1000, 1999],
        };
        $inRange = array_filter(array_map('intval', $used), fn (int $code): bool => $code >= $first && $code <= $last);
        $next = $inRange ? max($inRange) + 1 : $first;
        if ($next <= $last && ! in_array((string) $next, $used, true)) {
            return (string) $next;
        }
        for ($next = $first; $next <= $last; $next++) {
            if (! in_array((string) $next, $used, true)) {
                return (string) $next;
            }
        }
        throw ValidationException::withMessages(['code' => 'No unused account codes remain for this account type.']);
    }

    /** @param list<string> $reserved */
    public static function registry(string $workspaceKey, string $registry, string $classification = '', array $reserved = []): string
    {
        $model = match ($registry) {
            'parties' => Party::class, 'employees' => Employee::class, default => TaxCode::class
        };
        $prefix = $registry === 'parties' ? match ($classification) {
            'Customer' => 'CUS', 'Supplier' => 'SUP', default => 'PTY'
        } : ($registry === 'employees' ? 'E' : 'TAX');
        $used = [...$model::query()->where('workspace_key', $workspaceKey)->pluck('code')->all(), ...$reserved];
        $number = 1;
        do {
            $code = $prefix.'-'.str_pad((string) $number++, $registry === 'employees' ? 4 : 6, '0', STR_PAD_LEFT);
        } while (in_array($code, $used, true));

        return $code;
    }
}
