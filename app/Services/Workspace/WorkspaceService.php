<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WorkspaceService
{
    public function createForUser(User $user, string $workspaceType, ?string $workspaceName = null): Workspace
    {
        return DB::transaction(function () use ($user, $workspaceType, $workspaceName): Workspace {
            $name = $workspaceName ?: $user->name.' Workspace';

            $workspace = Workspace::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'type' => $workspaceType,
                'owner_user_id' => $user->id,
                'status' => 'active',
            ]);

            $workspace->users()->attach($user->id, [
                'membership_role' => 'owner',
                'status' => 'active',
                'is_primary' => true,
                'joined_at' => now(),
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
            Role::findOrCreate('owner', 'web');
            $user->assignRole('owner');
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            $defaultPlan = Plan::query()
                ->where('code', 'starter')
                ->where('is_active', true)
                ->first()
                ?? Plan::query()
                    ->where('is_active', true)
                    ->when(
                        \Illuminate\Support\Facades\Schema::hasColumn('plans', 'is_official'),
                        fn ($q) => $q->where('is_official', true)
                    )
                    ->orderBy('price')
                    ->first()
                ?? Plan::query()
                    ->where('workspace_type', $workspace->type)
                    ->where('is_active', true)
                    ->orderBy('price')
                    ->first();

            if ($defaultPlan) {
                Subscription::withoutGlobalScopes()->firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'plan_id' => $defaultPlan->id,
                    ],
                    [
                        'status' => 'trialing',
                        'starts_at' => now(),
                        'trial_ends_at' => now()->addDays(14),
                        'current_period_start' => now(),
                        'current_period_end' => now()->addDays(14),
                    ]
                );
            }

            return $workspace->fresh();
        });
    }
}
