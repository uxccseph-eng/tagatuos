<?php

namespace Database\Seeders;

use App\Models\DepreciationRun;
use Illuminate\Database\Seeder;

class DepreciationRunSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DepreciationRun::factory()->create(['workspace_key' => hash('sha256', 'depreciation-demo-workspace')]);
    }
}
