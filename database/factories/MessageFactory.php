<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
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
            'conversation_id' => Conversation::factory(),
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'direction' => fake()->randomElement(['inbound', 'outbound']),
            'message_type' => 'text',
            'content' => fake()->sentence(),
            'external_message_id' => fake()->uuid(),
            'ai_generated' => false,
            'metadata' => [],
        ];
    }
}
