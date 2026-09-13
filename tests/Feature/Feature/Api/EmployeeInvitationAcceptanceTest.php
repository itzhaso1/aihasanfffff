<?php

namespace Tests\Feature\Feature\Api;

use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_accept_valid_employee_invitation(): void
    {
        $this->seed(FoundationSeeder::class);

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);

        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $invitee = User::factory()->create([
            'email' => 'agent@example.com',
        ]);

        $invitation = EmployeeInvitation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'agent@example.com',
            'role' => 'agent',
            'status' => 'pending',
            'token' => 'token-accept-1',
            'expires_at' => now()->addDays(3),
        ]);

        $token = $invitee->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/employee-invitations/'.$invitation->token.'/accept')
            ->assertOk()
            ->assertJsonPath('data.workspace_id', $workspace->id)
            ->assertJsonPath('data.membership.membership_role', 'agent')
            ->assertJsonPath('data.membership.status', 'active');

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'membership_role' => 'agent',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('employee_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    public function test_invitation_role_outside_membership_enum_is_persisted_safely(): void
    {
        $this->seed(FoundationSeeder::class);

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $invitee = User::factory()->create(['email' => 'front@example.com']);
        $invitation = EmployeeInvitation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'front@example.com',
            'role' => 'receptionist',
            'status' => 'pending',
            'token' => 'token-accept-receptionist',
            'expires_at' => now()->addDays(3),
        ]);

        $this->withToken($invitee->createToken('api')->plainTextToken)
            ->postJson('/api/employee-invitations/'.$invitation->token.'/accept')
            ->assertOk()
            ->assertJsonPath('data.membership.membership_role', 'agent');

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'membership_role' => 'agent',
        ]);
    }

    public function test_user_cannot_accept_invitation_for_another_email(): void
    {
        $this->seed(FoundationSeeder::class);

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);

        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        EmployeeInvitation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invited_by' => $owner->id,
            'email' => 'agent@example.com',
            'role' => 'agent',
            'status' => 'pending',
            'token' => 'token-accept-2',
            'expires_at' => now()->addDays(3),
        ]);

        $otherUser = User::factory()->create([
            'email' => 'other@example.com',
        ]);

        $token = $otherUser->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/employee-invitations/token-accept-2/accept')
            ->assertNotFound();
    }
}
