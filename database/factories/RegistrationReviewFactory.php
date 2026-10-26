<?php

namespace Database\Factories;

use App\Models\RegistrationReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationReview>
 */
class RegistrationReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->systemOwner(),
            'applicant_id' => User::factory()->pending(),
            'action' => 'rejected',
            'previous_status' => 'pending',
            'username' => null,
            'reason' => 'Please confirm your registration details.',
        ];
    }
}
