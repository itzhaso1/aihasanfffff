<?php

namespace Tests\Feature\Feature;

use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FoundationSeederRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_seeder_creates_global_web_roles_when_team_roles_already_exist(): void
    {
        Role::query()->create([
            'name' => 'owner',
            'guard_name' => 'web',
            'workspace_id' => 999,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->seed(FoundationSeeder::class);

        $role = Role::findByName('owner', 'web');

        $this->assertSame('owner', $role->name);
        $this->assertSame('web', $role->guard_name);
        $this->assertNull($role->workspace_id);
    }
}
