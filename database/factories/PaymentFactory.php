<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'order_id' => Order::factory(),
            'provider' => 'local',
            'provider_payment_id' => fake()->uuid(),
            'idempotency_key' => fake()->uuid(),
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'USD',
        ];
    }
}
