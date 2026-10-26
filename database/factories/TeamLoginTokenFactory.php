<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\TeamLoginToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamLoginToken>
 */
class TeamLoginTokenFactory extends Factory
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
            'user_id' => User::factory()->accountant(),
            'issued_by' => User::factory()->firm(),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
        ];
    }
}
