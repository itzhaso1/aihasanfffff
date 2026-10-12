<?php

namespace Database\Factories;

use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatCatalogItem>
 */
class ChatCatalogItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'workspace_id' => Workspace::factory(),
            'chat_catalog_category_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'sku' => strtoupper(fake()->bothify('CHAT-####??')),
            'item_kind' => 'product',
            'tangibility' => 'tangible',
            'price' => fake()->randomFloat(2, 10, 400),
            'currency' => 'SAR',
            'stock' => fake()->optional()->numberBetween(0, 50),
            'is_active' => true,
            'sort_order' => 0,
            'image_path' => null,
            'attributes' => [],
        ];
    }

    public function service(): self
    {
        return $this->state(fn (): array => [
            'item_kind' => 'service',
            'tangibility' => 'intangible',
            'stock' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
