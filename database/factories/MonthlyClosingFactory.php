<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\MonthlyClosing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyClosing>
 */
class MonthlyClosingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'month' => '2026-10',
            'status' => 'not_started',
            'checklist' => MonthlyClosing::emptyChecklist(),
            'notes' => null,
            'version' => 1,
        ];
    }
}
