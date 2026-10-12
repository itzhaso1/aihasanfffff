<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmailContactGroup */
class EmailContactGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'contacts_count' => (int) ($this->contacts_count ?? $this->contacts?->count() ?? 0),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'contacts' => $this->whenLoaded('contacts', fn () => EmailContactResource::collection($this->contacts)),
        ];
    }
}
