<?php

namespace App\Services\Commerce\Intelligence;

final readonly class CommerceProductIntelligenceProfile
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $requirements
     * @param  array<int, array<string, mixed>>  $compatibilities
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function __construct(
        public int $itemId,
        public ?int $variantId,
        public string $itemName,
        public ?string $variantName,
        public ?string $categoryName,
        public array $attributes,
        public array $requirements,
        public array $compatibilities,
        public array $rules,
        public array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'variant_id' => $this->variantId,
            'item_name' => $this->itemName,
            'variant_name' => $this->variantName,
            'category_name' => $this->categoryName,
            'attributes' => $this->attributes,
            'requirements' => $this->requirements,
            'compatibilities' => $this->compatibilities,
            'rules' => $this->rules,
            'metadata' => $this->metadata,
        ];
    }
}
