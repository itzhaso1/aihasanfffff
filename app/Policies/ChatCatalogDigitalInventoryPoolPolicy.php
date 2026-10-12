<?php

namespace App\Policies;

use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Chat\ChatCatalogAccess;

class ChatCatalogDigitalInventoryPoolPolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, ChatCatalogDigitalInventoryPool $pool): bool
    {
        return $this->hasMembership($user, $pool->workspace)
            && app(ChatCatalogAccess::class)->canViewDigitalInventory($user, $pool->workspace);
    }

    public function update(User $user, ChatCatalogDigitalInventoryPool $pool): bool
    {
        return $this->hasMembership($user, $pool->workspace)
            && app(ChatCatalogAccess::class)->canManageDigitalInventory($user, $pool->workspace);
    }

    public function delete(User $user, ChatCatalogDigitalInventoryPool $pool): bool
    {
        return $this->update($user, $pool);
    }
}
