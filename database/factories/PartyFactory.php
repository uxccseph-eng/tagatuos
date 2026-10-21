<?php

namespace Database\Factories;

use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Party> */
class PartyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_key' => hash('sha256', fake()->uuid()), 'code' => strtoupper(fake()->unique()->bothify('QA-####??')), 'name' => fake()->words(3, true), 'classification' => 'Customer', 'archived' => false];
    }
}
