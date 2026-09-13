<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $actor = $request->user();
        abort_unless($actor && $this->workspaceAccess->canManageMembers($actor, $workspace), 403);

        return response()->json([
            'data' => [
                'roles' => Role::query()->where('guard_name', 'web')->get(['id', 'name', 'guard_name']),
                'permissions' => Permission::query()->where('guard_name', 'web')->get(['id', 'name', 'guard_name']),
            ],
        ]);
    }

    public function assignRole(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $actor = $request->user();
        abort_unless($actor, 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', 'max:64'],
        ]);

        $target = User::query()->findOrFail($validated['user_id']);
        $roleName = strtolower(trim((string) $validated['role']));

        abort_unless(
            $this->workspaceAccess->canAssignRole($actor, $workspace, $roleName, $target),
            403,
            'You are not allowed to assign this role.'
        );

        abort_unless(
            Role::query()->where('guard_name', 'web')->where('name', $roleName)->exists(),
            422,
            'Unknown role.'
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $target->syncRoles([$roleName]);

        $workspace->users()->updateExistingPivot($target->id, [
            'membership_role' => $this->workspaceAccess->persistableMembershipRole($roleName),
        ]);

        Log::info('workspace.role.assigned', [
            'workspace_id' => $workspace->id,
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'role' => $roleName,
        ]);

        return response()->json(['data' => ['user_id' => $target->id, 'role' => $roleName]]);
    }

    public function syncPermissions(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $actor = $request->user();
        abort_unless($actor && $this->workspaceAccess->canSyncPermissions($actor, $workspace), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'max:120'],
        ]);

        $target = User::query()->findOrFail($validated['user_id']);
        abort_unless($this->workspaceAccess->isActiveMember($target, $workspace), 404);

        if ((int) $target->id === (int) $actor->id && ! $this->workspaceAccess->isWorkspaceOwner($actor, $workspace)) {
            abort(403, 'You cannot change your own permissions.');
        }

        $requested = array_values(array_unique(array_map('strval', $validated['permissions'] ?? [])));
        $known = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $requested)
            ->pluck('name')
            ->all();

        if (! $this->workspaceAccess->isWorkspaceOwner($actor, $workspace)) {
            $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();
            $known = array_values(array_intersect($known, $actorPermissions));
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $target->syncPermissions($known);

        Log::info('workspace.permissions.synced', [
            'workspace_id' => $workspace->id,
            'actor_id' => $actor->id,
            'target_user_id' => $target->id,
            'permission_count' => count($known),
        ]);

        return response()->json(['data' => ['user_id' => $target->id, 'permissions' => $known]]);
    }

    private function requireWorkspace()
    {
        $workspace = $this->workspaceContext->workspace();
        abort_unless($workspace, 422, 'Workspace not resolved.');

        return $workspace;
    }
}
