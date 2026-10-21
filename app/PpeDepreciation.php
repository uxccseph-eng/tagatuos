<?php

namespace App;

use App\Models\ChartAccount;
use App\Models\DepreciationRun;
use App\Models\PpeAsset;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PpeDepreciation
{
    private function month(string $date): int
    {
        return ((int) substr($date, 0, 4)) * 12 + (int) substr($date, 5, 2) - 1;
    }

    public function accumulated(PpeAsset $asset, string $date): int
    {
        if ($date < $asset->service_date) {
            return 0;
        }
        $end = (new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
        $last = $this->month($date) - ($date < $end ? 1 : 0);
        if ($asset->disposal_date) {
            $last = min($last, $this->month($asset->disposal_date) - 1);
        }
        $months = max(0, min($asset->life_months, $last - $this->month($asset->service_date) + 1));

        return (int) round(($asset->cost_cents - $asset->residual_cents) * $months / $asset->life_months);
    }

    /**
     * @return array{preview_hash: string, period: string, from: string, to: string, rows: list<array<string, mixed>>, attention: list<array{id: int, code: string, name: string, message: string}>, total_cents: int, run: ?DepreciationRun, ready: bool, history: Collection<int, DepreciationRun>}
     */
    public function preview(string $workspace, string $period): array
    {
        $start = $period.'-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $prior = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
        $existing = DepreciationRun::query()->where('workspace_key', $workspace)->where('period', $period)->first();
        $assets = PpeAsset::query()->where('workspace_key', $workspace)->where('service_date', '<=', $end)->where(function (Builder $query) use ($start): void {
            $query->whereNull('disposal_date')->orWhere('disposal_date', '>=', $start);
        })->orderBy('category')->orderBy('code')->get();
        $accounts = ChartAccount::query()->where('workspace_key', $workspace)->get()->keyBy('id');
        $previous = DepreciationRun::query()->where('workspace_key', $workspace)->where('period', '<', $period)->orderByDesc('period')->get();
        $rows = [];
        $attention = [];
        foreach ($assets as $asset) {
            $hasPosted = $existing && in_array($asset->id, array_column($existing->snapshot['rows'], 'id'), true);
            foreach ($previous as $run) {
                $hasPosted = $hasPosted || in_array($asset->id, array_column($run->snapshot['rows'], 'id'), true);
            }
            if ($asset->archived && ! $hasPosted) {
                continue;
            }
            $opening = $this->accumulated($asset, $prior);
            $closing = $this->accumulated($asset, $end);
            $issues = [];
            foreach (['asset_account_id' => ['Asset', 'Debit'], 'accumulated_account_id' => ['Asset', 'Credit'], 'expense_account_id' => ['Expense', 'Debit']] as $field => [$type, $side]) {
                $account = $accounts->get($asset->{$field});
                if (! $account || $account->archived || $account->type !== $type || $account->normal_balance !== $side) {
                    $issues[] = 'Select an active '.str_replace('_id', '', str_replace('_', ' ', $field)).' ('.$type.', '.$side.').';
                }
            }
            if (! $asset->capitalization_reference) {
                $issues[] = 'Record the reference to the existing capitalization/opening entry in PPE Masters.';
            }
            if (! $existing && $opening > 0) {
                $last = null;
                foreach ($previous as $run) {
                    foreach ($run->snapshot['rows'] as $item) {
                        if ($item['id'] === $asset->id) {
                            $last = ['period' => $run->period, 'closing_dep' => $item['closing_dep']];
                            break 2;
                        }
                    }
                }
                if (! $last || $last['period'] !== substr($prior, 0, 7) || $last['closing_dep'] !== $opening) {
                    $issues[] = 'Post missing prior monthly runs, beginning with the in-service month, before this run.';
                }
            }
            $row = ['id' => $asset->id, 'code' => $asset->code, 'name' => $asset->name, 'category' => $asset->category, 'acquired_date' => $asset->acquired_date, 'service_date' => $asset->service_date, 'cost_cents' => $asset->cost_cents, 'residual_cents' => $asset->residual_cents, 'life_months' => $asset->life_months, 'months_charged' => max(0, min($asset->life_months, min($this->month($end), $asset->disposal_date ? $this->month($asset->disposal_date) - 1 : $this->month($end)) - $this->month($asset->service_date) + 1)), 'monthly' => (int) round(($asset->cost_cents - $asset->residual_cents) / $asset->life_months), 'opening_dep' => $opening, 'depreciation' => $closing - $opening, 'closing_dep' => $closing, 'net' => $asset->cost_cents - $closing, 'expense_account' => '', 'accumulated_account' => ''];
            foreach (['expense_account_id' => 'expense_account', 'accumulated_account_id' => 'accumulated_account'] as $field => $target) {
                $account = $accounts->get($asset->{$field});
                $row[$target] = $account ? $account->code.' · '.$account->name : '';
            }
            $rows[] = $row;
            if ($existing) {
                $issues = in_array($asset->id, array_column($existing->snapshot['rows'], 'id'), true) ? [] : ['This asset was added after this month was posted. Review the locked run before making an adjustment.'];
            }
            foreach ($issues as $issue) {
                $attention[] = ['id' => $asset->id, 'code' => $asset->code, 'name' => $asset->name, 'message' => $issue];
            }
        }
        if ($existing) {
            $rows = $existing->snapshot['rows'];
        }
        $total = array_sum(array_column($rows, 'depreciation'));

        return ['preview_hash' => hash('sha256', json_encode([$period, $rows], JSON_THROW_ON_ERROR)), 'period' => $period, 'from' => $start, 'to' => $end, 'rows' => $rows, 'attention' => $attention, 'total_cents' => $total, 'run' => $existing, 'ready' => ! $existing && $attention === [] && $total > 0, 'history' => DepreciationRun::query()->where('workspace_key', $workspace)->orderByDesc('period')->get(['id', 'period', 'number', 'total_cents', 'posted_by', 'posted_at'])];
    }
}
