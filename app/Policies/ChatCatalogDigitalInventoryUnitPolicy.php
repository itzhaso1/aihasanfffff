<?php

namespace App\Policies;

use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Chat\ChatCatalogAccess;

class ChatCatalogDigitalInventoryUnitPolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, ChatCatalogDigitalInventoryUnit $unit): bool
    {
        return $this->hasMembership($user, $unit->workspace)
            && app(ChatCatalogAccess::class)->canViewDigitalInventory($user, $unit->workspace);
    }

    public function update(User $user, ChatCatalogDigitalInventoryUnit $unit): bool
    {
        return $this->hasMembership($user, $unit->workspace)
            && app(ChatCatalogAccess::class)->canManageDigitalInventory($user, $unit->workspace);
    }

    public function delete(User $user, ChatCatalogDigitalInventoryUnit $unit): bool
    {
        return $this->update($user, $unit);
    }
}
