<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InboxDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxDocument>
 */
class InboxDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'uploaded_by' => User::factory(), 'original_name' => 'invoice.pdf', 'path' => fake()->uuid().'.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'sha256' => hash('sha256', fake()->uuid()), 'category' => 'purchases', 'status' => 'received', 'version' => 1,
        ];
    }
}
