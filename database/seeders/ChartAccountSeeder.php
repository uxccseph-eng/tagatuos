<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use Illuminate\Database\Seeder;

class ChartAccountSeeder extends Seeder
{
    public function run(): void
    {
        ChartAccount::seedWorkspace(ChartAccount::workspaceKey(['company_name' => 'Sample Studio', 'tin' => '']));
    }
}
