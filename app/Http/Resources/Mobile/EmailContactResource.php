<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\EmailContact */
class EmailContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'normalized_email' => $this->normalized_email,
            'phone' => $this->phone,
            'company' => $this->company,
            'job_title' => $this->job_title,
            'notes' => $this->notes,
            'is_favorite' => (bool) $this->is_favorite,
            'avatar_url' => $this->avatar_path
                ? Storage::disk('public')->url($this->avatar_path)
                : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'groups' => $this->whenLoaded('groups', fn () => $this->groups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
            ])->values()),
        ];
    }
}
