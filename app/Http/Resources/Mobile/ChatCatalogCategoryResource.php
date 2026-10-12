<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalogCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ChatCatalogCategory */
class ChatCatalogCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_path' => $this->image_path,
            'image_url' => is_string($this->image_path) && $this->image_path !== ''
                ? Storage::disk('public')->url($this->image_path)
                : null,
            'status' => $this->status,
            'parent_id' => $this->parent_id,
            'is_visible' => (bool) $this->is_visible,
            'is_featured' => (bool) $this->is_featured,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'seo' => $this->seo ?? [],
            'localization' => $this->localization ?? [],
            'aliases' => $this->aliases ?? [],
        ];
    }
}
