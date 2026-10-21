<?php

namespace Database\Factories;

use App\Models\PpeAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpeAsset>
 */
class PpeAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_key' => hash('sha256', fake()->uuid()),
            'code' => strtoupper(fake()->unique()->bothify('PPE-####??')),
            'name' => fake()->words(3, true), 'category' => 'Office equipment',
            'acquired_date' => '2026-01-01', 'service_date' => '2026-01-01',
            'cost_cents' => 120000, 'residual_cents' => 0, 'life_months' => 12,
        ];
    }
}
