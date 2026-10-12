<?php

namespace App\Policies;

use App\Models\EmailBranding;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class EmailBrandingPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace ? $this->hasMembership($user, $workspace) : false;
    }

    public function view(User $user, EmailBranding $branding): bool
    {
        return $this->owns($user, $branding);
    }

    public function update(User $user, EmailBranding $branding): bool
    {
        return $this->owns($user, $branding);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    private function owns(User $user, EmailBranding $branding): bool
    {
        $workspace = $branding->workspace;
        if (! $workspace) {
            return false;
        }

        $currentId = app(WorkspaceContext::class)->workspaceId();
        if ($currentId && (int) $currentId !== (int) $branding->workspace_id) {
            return false;
        }

        return $this->hasMembership($user, $workspace);
    }
}
