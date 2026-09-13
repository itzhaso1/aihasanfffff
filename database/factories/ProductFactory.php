<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'workspace_id' => Workspace::factory(),
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'sku' => strtoupper(fake()->bothify('SKU-####??')),
            'price' => fake()->randomFloat(2, 10, 500),
            'sale_price' => null,
            'currency' => 'USD',
            'stock' => fake()->numberBetween(0, 100),
            'inventory_tracking' => true,
            'status' => 'active',
            'brand' => fake()->optional()->company(),
            'weight' => fake()->optional()->randomFloat(3, 0.1, 10),
            'images' => [],
            'attributes' => [],
        ];
    }
}
