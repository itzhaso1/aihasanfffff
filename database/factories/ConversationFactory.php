<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
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
            'customer_id' => Customer::factory(),
            'channel' => fake()->randomElement(['whatsapp', 'web', 'manual']),
            'external_id' => fake()->uuid(),
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
            'metadata' => [],
        ];
    }
}
