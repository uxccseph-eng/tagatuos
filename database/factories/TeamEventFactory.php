<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\TeamEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamEvent>
 */
class TeamEventFactory extends Factory
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
            'actor_id' => User::factory()->firm(),
            'subject_id' => null,
            'action' => 'Access updated',
            'details' => ['name' => fake()->name()],
        ];
    }
}
