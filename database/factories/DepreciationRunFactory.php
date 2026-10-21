<?php

namespace Database\Factories;

use App\Models\DepreciationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepreciationRun>
 */
class DepreciationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_key' => hash('sha256', fake()->uuid()), 'period' => '2026-10', 'number' => 'DEP-2026-10',
            'snapshot' => ['rows' => []], 'journal' => ['id' => 'DEP-2026-10', 'number' => 'DEP-2026-10', 'date' => '2026-10-31', 'status' => 'posted', 'lines' => []],
            'total_cents' => 0, 'posted_by' => 'Sample User', 'posted_at' => now(),
        ];
    }
}
