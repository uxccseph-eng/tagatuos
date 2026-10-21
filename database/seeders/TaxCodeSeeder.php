<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use App\Models\TaxCode;
use Illuminate\Database\Seeder;

class TaxCodeSeeder extends Seeder
{
    public function run(): void
    {
        TaxCode::seedWorkspace(ChartAccount::workspaceKey(['company_name' => 'Sample Studio', 'tin' => '']));
    }
}
