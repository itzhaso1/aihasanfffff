<?php

namespace App\Http\Resources\Mobile;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workspace */
class WorkspaceResource extends JsonResource
{
    private ?int $ownerUserIdOverride = null;

    public function forUser(User $user): static
    {
        $this->ownerUserIdOverride = $user->id;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $userId = $this->ownerUserIdOverride
            ?? ($user instanceof User ? $user->id : null);
        $isOwner = $userId !== null
            && (
                (int) $this->owner_user_id === (int) $userId
                || $this->pivot?->membership_role === WorkspaceAccess::ROLE_OWNER
            );

        $role = $this->pivot?->membership_role ?? ($isOwner ? WorkspaceAccess::ROLE_OWNER : null);

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'role' => $role,
            'membership_role' => $role,
            'is_owner' => $isOwner,
        ];

        $attributes = $this->resource->getAttributes();
        if (array_key_exists('slug', $attributes)) {
            $data['slug'] = $this->slug;
        }

        return $data;
    }
}
