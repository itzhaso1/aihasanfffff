<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
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
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Black 42', 'White M', 'Blue XL']),
            'sku' => strtoupper(fake()->bothify('VAR-####??')),
            'attributes' => ['size' => fake()->randomElement(['S', 'M', 'L', 'XL']), 'color' => fake()->safeColorName()],
            'price' => fake()->randomFloat(2, 10, 500),
            'sale_price' => null,
            'stock' => fake()->numberBetween(0, 50),
            'status' => 'active',
        ];
    }
}
