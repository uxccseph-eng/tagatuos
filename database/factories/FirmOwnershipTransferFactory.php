<?php

namespace Database\Factories;

use App\Models\FirmOwnershipTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmOwnershipTransfer>
 */
class FirmOwnershipTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_owner_id' => User::factory()->firm(),
            'target_owner_id' => User::factory()->firm(),
            'status' => 'pending',
            'source_password_fingerprint' => str_repeat('0', 64),
            'scope_summary' => [],
            'expires_at' => now()->addDays(7),
        ];
    }
}
