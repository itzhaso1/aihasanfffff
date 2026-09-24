<?php

namespace App\Policies;

use App\Models\EmailTemplatePreference;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class EmailTemplatePreferencePolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace ? $this->hasMembership($user, $workspace) : false;
    }

    public function view(User $user, EmailTemplatePreference $preference): bool
    {
        return $this->owns($user, $preference);
    }

    public function update(User $user, EmailTemplatePreference $preference): bool
    {
        return $this->owns($user, $preference);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    private function owns(User $user, EmailTemplatePreference $preference): bool
    {
        $workspace = $preference->workspace;
        if (! $workspace) {
            return false;
        }

        $currentId = app(WorkspaceContext::class)->workspaceId();
        if ($currentId && (int) $currentId !== (int) $preference->workspace_id) {
            return false;
        }

        return $this->hasMembership($user, $workspace);
    }
}
