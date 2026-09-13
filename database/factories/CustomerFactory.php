<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->name(),
            'party_type' => Customer::PARTY_TYPE_INDIVIDUAL,
            'phone' => '+9665'.fake()->unique()->numerify('########'),
            'whatsapp' => null,
            'email' => fake()->safeEmail(),
            'orders_count' => 0,
            'total_purchases' => 0,
            'notes' => fake()->optional()->sentence(),
            'metadata' => [],
        ];
    }
}
