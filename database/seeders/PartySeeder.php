<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use App\Models\Party;
use Illuminate\Database\Seeder;

class PartySeeder extends Seeder
{
    public function run(): void
    {
        Party::seedWorkspace(ChartAccount::workspaceKey(['company_name' => 'Sample Studio', 'tin' => '']));
    }
}
