<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanySetting> */
class CompanySettingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = hash('sha256', fake()->uuid());

        return ['workspace_key' => $key, 'lookup_key' => $key, 'profile' => ['company_name' => fake()->company(), 'tin' => '000000000'], 'preferences' => []];
    }
}
