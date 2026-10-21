<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\ChartAccount;
use Illuminate\Database\Seeder;

class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        $workspaceKey = ChartAccount::workspaceKey(['company_name' => 'Sample Studio', 'tin' => '']);
        ChartAccount::seedWorkspace($workspaceKey);
        $account = ChartAccount::query()->where('workspace_key', $workspaceKey)->where('code', '1020')->firstOrFail();
        BankAccount::query()->firstOrCreate(['workspace_key' => $workspaceKey, 'name' => 'Sample Bank'], ['chart_account_id' => $account->id, 'currency' => 'PHP']);
    }
}
