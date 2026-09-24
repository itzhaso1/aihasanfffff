<?php

namespace Database\Factories;

use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatCatalogItemVariant>
 */
class ChatCatalogItemVariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'chat_catalog_item_id' => ChatCatalogItem::factory(),
            'name' => fake()->randomElement(['S', 'M', 'L', 'XL']),
            'sku' => strtoupper(fake()->bothify('VAR-##??')),
            'price' => null,
            'stock' => fake()->optional()->numberBetween(0, 20),
            'is_active' => true,
            'attributes' => ['size' => fake()->randomElement(['S', 'M', 'L'])],
        ];
    }
}
