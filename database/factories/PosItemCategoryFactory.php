<?php

namespace Database\Factories;

use App\Models\PosItemCategory;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosItemCategory>
 */
class PosItemCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->randomElement(['مشروبات', 'أكل', 'حلويات']).' '.fake()->numberBetween(1, 1000),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 30),
        ];
    }
}
