<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Models\WorkspaceUser;
use App\Notifications\EmployeeInvitationNotification;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EmployeeController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();
        if (! $user || ! $this->workspaceAccess->canManageMembers($user, $workspace)) {
            return $this->fail('غير مصرح لك بإدارة الموظفين.', 403);
        }

        $memberships = $workspace->memberships()
            ->with('user')
            ->whereIn('status', ['active', 'invited', 'suspended'])
            ->get();

        $employees = $memberships->map(function (WorkspaceUser $membership) use ($workspace) {
            $user = $membership->user;
            $isOwner = (int) $workspace->owner_user_id === (int) $membership->user_id;
            $role = $membership->membership_role ?: ($isOwner ? 'owner' : 'agent');

            return [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'name' => $user?->name ?? 'موظف',
                'email' => $user?->email,
                'phone' => $user?->phone,
                'avatar_url' => $user?->avatar_url,
                'membership_role' => $role,
                'status' => $membership->status,
                'is_owner' => $isOwner,
                'created_at' => $membership->created_at?->toIso8601String(),
            ];
        });

        $pendingInvitations = EmployeeInvitation::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->get()
            ->map(fn (EmployeeInvitation $inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'role' => $inv->role,
                'status' => 'pending',
                'expires_at' => $inv->expires_at?->toIso8601String(),
                'created_at' => $inv->created_at?->toIso8601String(),
            ]);

        return $this->ok([
            'employees' => $employees,
            'invitations' => $pendingInvitations,
            'assignable_roles' => WorkspaceAccess::ASSIGNABLE_ROLES,
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $actor = $request->user();
        if (! $actor || ! $this->workspaceAccess->canManageMembers($actor, $workspace)) {
            return $this->fail('غير مصرح لك بدعوة موظفين.', 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:admin,manager,agent,accountant,receptionist,staff_doctor,staff'],
        ]);

        if (! $this->workspaceAccess->canInviteRole($actor, $workspace, $validated['role'])) {
            return $this->fail('لا يمكنك دعوة موظف بهذه الرتبة.', 403);
        }

        $invitation = EmployeeInvitation::query()->create([
            'email' => $validated['email'],
            'role' => $validated['role'],
            'status' => 'pending',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
            'invited_by' => $actor->id,
        ]);

        try {
            Notification::route('central_mail', $validated['email'])
                ->notify(new EmployeeInvitationNotification($invitation));
        } catch (\Throwable) {
            // Notification best-effort in dev/local
        }

        return $this->ok($invitation, message: 'تم إرسال دعوة الانضمام بنجاح.', status: 201);
    }

    public function update(Request $request, WorkspaceUser $membership): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        if ($membership->workspace_id !== $workspace->id) {
            return $this->fail('العنصر غير موجود.', 404);
        }

        $actor = $request->user();
        if (! $actor) {
            return $this->fail('غير مصرح.', 403);
        }

        $validated = $request->validate([
            'membership_role' => ['nullable', 'in:admin,manager,agent,accountant,receptionist,staff_doctor,staff'],
            'status' => ['nullable', 'in:active,invited,suspended'],
        ]);

        $target = User::query()->findOrFail($membership->user_id);
        if (! $this->workspaceAccess->canModifyMembership($actor, $workspace, $target, $validated['membership_role'] ?? null)) {
            return $this->fail('لا تملك صلاحية تعديل هذا العضو.', 403);
        }

        if (isset($validated['membership_role'])) {
            $roleName = strtolower(trim((string) $validated['membership_role']));
            $validated['membership_role'] = $this->workspaceAccess->persistableMembershipRole($roleName);

            if (Role::query()->where('guard_name', 'web')->where('name', $roleName)->exists()) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
                $target->syncRoles([$roleName]);
            }
        }

        $membership->update($validated);

        return $this->ok($membership->refresh(), message: 'تم تحديث بيانات الموظف بنجاح.');
    }

    public function destroy(WorkspaceUser $membership): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        if ($membership->workspace_id !== $workspace->id) {
            return $this->fail('العنصر غير موجود.', 404);
        }

        $actor = request()->user();
        if (! $actor) {
            return $this->fail('غير مصرح.', 403);
        }

        $target = User::query()->findOrFail($membership->user_id);
        if (! $this->workspaceAccess->canModifyMembership($actor, $workspace, $target) || (int) $membership->user_id === (int) $workspace->owner_user_id) {
            return $this->fail('لا يمكنك حذف مالك مساحة العمل أو هذا العضو.', 403);
        }

        $membership->delete();

        return $this->ok(message: 'تم حذف الموظف بنجاح.');
    }

    public function roles(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $actor = $request->user();
        if (! $actor || ! $this->workspaceAccess->canManageMembers($actor, $workspace)) {
            return $this->fail('غير مصرح.', 403);
        }

        return $this->ok([
            'roles' => Role::query()->where('guard_name', 'web')->get(['id', 'name']),
            'permissions' => Permission::query()->where('guard_name', 'web')->get(['id', 'name']),
        ]);
    }
}
