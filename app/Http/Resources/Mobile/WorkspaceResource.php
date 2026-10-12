<?php

namespace App\Http\Resources\Mobile;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

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

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_owner' => $isOwner,
        ];

        if (Schema::hasColumn('workspaces', 'slug')) {
            $data['slug'] = $this->slug;
        }

        return $data;
    }
}
