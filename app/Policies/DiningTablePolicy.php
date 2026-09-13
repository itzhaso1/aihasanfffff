<?php

namespace App\Policies;

use App\Models\DiningTable;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class DiningTablePolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DiningTable $table): bool
    {
        return $this->hasMembership($user, $table->workspace);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, DiningTable $table): bool
    {
        return $this->hasAnyWorkspaceRole($user, $table->workspace, ['owner', 'admin', 'manager', 'agent', 'receptionist']);
    }

    public function delete(User $user, DiningTable $table): bool
    {
        return $this->hasAnyWorkspaceRole($user, $table->workspace, ['owner', 'admin', 'manager']);
    }
}
