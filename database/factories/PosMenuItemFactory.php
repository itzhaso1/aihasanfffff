<?php

namespace Database\Factories;

use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosMenuItem>
 */
class PosMenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'pos_item_category_id' => null,
            'name' => fake()->words(2, true),
            'item_type' => fake()->randomElement(['مشروبات', 'حلويات', 'وجبات']),
            'size_label' => fake()->optional()->randomElement(['صغير', 'وسط', 'كبير']),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 2, 80),
            'currency' => 'USD',
            'image_path' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 30),
        ];
    }

    public function withCategory(?PosItemCategory $category = null): self
    {
        return $this->state(fn () => [
            'pos_item_category_id' => $category?->id ?? PosItemCategory::factory(),
        ]);
    }
}
