<?php

namespace App\Console\Commands;

use App\Support\Authorization\FoundationRolePermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncFoundationRolesCommand extends Command
{
    protected $signature = 'permissions:sync-foundation-roles';

    protected $description = 'Sync global manager and accountant Spatie templates (does not reset owner/admin or custom roles)';

    public function handle(PermissionRegistrar $registrar): int
    {
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();

        foreach (['manager', 'accountant'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        $manager = Role::findByName('manager', 'web');
        $accountant = Role::findByName('accountant', 'web');
        $manager->syncPermissions($this->existing(FoundationRolePermissions::manager()));
        $accountant->syncPermissions($this->existing(FoundationRolePermissions::accountant()));

        $registrar->forgetCachedPermissions();

        Log::info('foundation.roles.synced', [
            'roles' => ['manager', 'accountant'],
            'manager_permissions' => $manager->permissions()->pluck('name')->values()->all(),
            'accountant_permission_count' => $accountant->permissions()->count(),
        ]);

        $this->info('Synced foundation manager and accountant permission templates.');
        $this->line('manager_count='.$manager->permissions()->count());
        $this->line('accountant_count='.$accountant->permissions()->count());

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function existing(array $names): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();
    }
}
