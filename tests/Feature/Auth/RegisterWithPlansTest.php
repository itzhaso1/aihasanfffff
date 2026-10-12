<?php

namespace Tests\Feature\Auth;

use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterWithPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_succeeds_when_default_plan_exists(): void
    {
        $this->seed(FoundationSeeder::class);
        app(WorkspaceContext::class)->disableTestFixtureMutations();

        $response = $this->post('/register', [
            'name' => 'Seeded Plan User',
            'email' => 'seeded-plan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'workspace_name' => 'Seeded Workspace',
        ]);

        $response->assertRedirect(route('workspace.dashboard', absolute: false));
        $this->assertAuthenticated();
    }
}
