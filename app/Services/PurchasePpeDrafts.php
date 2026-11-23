<?php

namespace App\Services;

use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\PpeAsset;
use Illuminate\Support\Facades\DB;

class PurchasePpeDrafts
{
    public function synchronize(Company $company, array $state): void
    {
        $workspace = $company->workspaceKey();
        $accounts = ChartAccount::query()->where('workspace_key', $workspace)->where('type', 'Asset')->where('normal_balance', 'Debit')->get()->filter(fn (ChartAccount $account): bool => $account->ppe_role === 'Asset' || in_array(strtolower(preg_replace('/[^a-z0-9]+/i', '', $account->name)), ['furnitureandfixtures', 'furnituresandfixtures', 'furniturefixtures', 'propertyplantandequipment', 'propertyandequipment'], true))->keyBy('code');
        foreach ($state['purchases'] as $bill) {
            $source = (string) $bill['id'];
            $asset = PpeAsset::query()->where('workspace_key', $workspace)->where('purchase_source_id', $source)->lockForUpdate()->first();
            $code = preg_split('/\s*[·–—]\s*|\s+-\s+/', (string) ($bill['account'] ?? ''), 2)[0];
            $account = $accounts->get($code) ?? $accounts->first(fn (ChartAccount $item): bool => $item->name === ($bill['account'] ?? ''));
            $valid = ($bill['status'] ?? '') === 'posted' && $account !== null;
            if (! $valid) {
                if ($asset) {
                    $asset->update(['source_review_required' => true]);
                }

                continue;
            }
            $header = DB::table('journal_entries')->where('company_id', $company->id)->where('source_key', 'purchases:'.$source)->where('status', 'posted')->first();
            if (! $header) {
                continue;
            }
            $cost = 0;
            foreach (DB::table('journal_entry_lines')->where('company_id', $company->id)->where('journal_entry_id', $header->id)->where('chart_account_id', $account->id)->get() as $line) {
                [$whole, $fraction] = array_pad(explode('.', (string) $line->debit), 2, '');
                $cost += (int) $whole * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
            }
            if ($cost <= 0) {
                continue;
            }
            $basis = ['cost_cents' => $cost, 'acquired_date' => $bill['date'], 'asset_account_id' => $account->id];
            if ($asset && ! $asset->setup_pending) {
                $changed = collect($basis)->contains(fn (mixed $value, string $key): bool => (string) $asset->{$key} !== (string) $value);
                $asset->update(['capitalization_reference' => $bill['number'], 'source_review_required' => $changed]);

                continue;
            }
            if ($asset) {
                $asset->update([...$basis, 'capitalization_reference' => $bill['number'], 'source_review_required' => false]);
            } else {
                PpeAsset::query()->create([...$basis, 'workspace_key' => $workspace, 'purchase_source_id' => $source, 'code' => 'PUR-'.strtoupper(substr(hash('sha256', $source), 0, 20)), 'name' => mb_substr(trim($bill['description'] ?? '') ?: $account->name.' · '.($bill['supplier'] ?? ''), 0, 150), 'category' => mb_substr($account->ppe_category ?: $account->name, 0, 60), 'service_date' => null, 'life_months' => null, 'residual_cents' => 0, 'setup_pending' => true, 'capitalization_reference' => $bill['number']]);
            }
        }
    }
}
