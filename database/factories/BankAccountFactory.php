<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\ChartAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankAccount> */
class BankAccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_key' => hash('sha256', fake()->uuid()), 'name' => fake()->unique()->words(3, true), 'bank' => fake()->company(), 'account_number' => fake()->numerify('####-####-####'), 'chart_account_id' => ChartAccount::factory()->state(['type' => 'Asset', 'is_cash_bank' => true]), 'currency' => 'PHP', 'archived' => false];
    }
}
