<?php

namespace App\Http\Resources\Cashier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\PosMenuItem */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageUrl = null;
        if (is_string($this->image_path) && $this->image_path !== '') {
            $imageUrl = Storage::disk('public')->url($this->image_path);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'item_type' => $this->item_type,
            'size_label' => $this->size_label,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency ?: 'SAR',
            'image_url' => $imageUrl,
            'is_active' => (bool) $this->is_active,
            'availability' => $this->is_active ? 'available' : 'unavailable',
            'sort_order' => (int) $this->sort_order,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'pos_item_category_id' => $this->pos_item_category_id,
            'product_id' => $this->product_id,
            'stock' => $this->when(
                $this->relationLoaded('product') && $this->product,
                fn () => (int) $this->product->stock,
            ),
        ];
    }
}
