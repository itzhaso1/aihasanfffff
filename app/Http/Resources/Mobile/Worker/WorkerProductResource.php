<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\ChatCatalogItem;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ChatCatalogItem */
class WorkerProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $imageUrl = is_string($this->image_path) && $this->image_path !== ''
            ? Storage::disk('public')->url($this->image_path)
            : null;
        $assignment = $this->relationLoaded('workerEngagementProduct')
            ? $this->getRelation('workerEngagementProduct')
            : null;
        $minimumSellingPrice = $assignment?->minimum_selling_price
            ?? $this->worker_min_price;
        $maximumSellingPrice = $assignment?->maximum_selling_price
            ?? $this->worker_max_price;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->short_description ?: $this->description,
            'image_url' => $imageUrl,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'price' => $this->price !== null ? Money::of((string) $this->price) : null,
            'minimum_selling_price' => $minimumSellingPrice !== null
                ? Money::of((string) $minimumSellingPrice)
                : null,
            'maximum_selling_price' => $maximumSellingPrice !== null
                ? Money::of((string) $maximumSellingPrice)
                : null,
            'currency' => $this->currency ?: 'SAR',
            'status' => $this->is_active ? 'active' : 'inactive',
        ];
    }
}
