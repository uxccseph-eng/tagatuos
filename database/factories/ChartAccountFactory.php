<?php

namespace Database\Factories;

use App\Models\ChartAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChartAccount> */
class ChartAccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_key' => hash('sha256', fake()->uuid()), 'code' => (string) fake()->unique()->numberBetween(8000, 8999), 'name' => fake()->words(3, true), 'type' => 'Expense', 'normal_balance' => 'Debit', 'statement_section' => 'General and Administrative Expenses', 'is_cash_bank' => false, 'is_advance' => false, 'is_control' => false, 'subsidiary' => null, 'ppe_role' => null, 'ppe_category' => null, 'relief' => false, 'percentage_tax' => false, 'ewt' => false, 'archived' => false];
    }
}
