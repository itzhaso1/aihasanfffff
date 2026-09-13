<?php

namespace Database\Factories;

use App\Models\PosCashierInvoice;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosCashierInvoice>
 */
class PosCashierInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'invoice_number' => 'CASH-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => 'closed',
            'currency' => 'USD',
            'subtotal' => fake()->randomFloat(2, 10, 100),
            'discount_amount' => 0,
            'total_amount' => fake()->randomFloat(2, 10, 100),
            'closed_at' => now(),
            'metadata' => [],
        ];
    }
}
