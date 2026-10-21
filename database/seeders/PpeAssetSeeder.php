<?php

namespace Database\Seeders;

use App\Models\PpeAsset;
use Illuminate\Database\Seeder;

class PpeAssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PpeAsset::factory()->count(3)->create(['workspace_key' => hash('sha256', 'ppe-demo-workspace')]);
    }
}
