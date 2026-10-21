<?php

namespace Database\Factories;

use App\Models\TaxCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxCode> */
class TaxCodeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_key' => hash('sha256', fake()->uuid()), 'code' => strtoupper(fake()->unique()->bothify('QA-####??')), 'name' => fake()->words(3, true), 'kind' => 'VAT', 'rate' => 12, 'archived' => false];
    }
}
