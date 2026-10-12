<?php

namespace Database\Factories;

use App\Models\PosCashierInvoice;
use App\Models\PosCashierInvoiceItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosCashierInvoiceItem>
 */
class PosCashierInvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 1, 25);

        return [
            'workspace_id' => Workspace::factory(),
            'pos_cashier_invoice_id' => PosCashierInvoice::factory(),
            'item_name' => fake()->words(2, true),
            'item_type' => fake()->randomElement(['مشروبات', 'أكل']),
            'size_label' => fake()->optional()->randomElement(['صغير', 'كبير']),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => 0,
            'total_amount' => round($quantity * $unitPrice, 2),
        ];
    }
}
