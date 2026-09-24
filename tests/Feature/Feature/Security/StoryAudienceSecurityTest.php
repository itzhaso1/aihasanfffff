<?php

namespace Tests\Feature\Feature\Security;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryAudienceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_story_create_rejects_foreign_selected_user_ids(): void
    {
        [$userA, $workspaceA, $tokenA] = $this->authMember('story-aud-a@example.com');
        [$userB] = $this->authMember('story-aud-b@example.com');

        $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Selected audience',
                'visibility' => 'selected',
                'selected_user_ids' => [$userB->id],
            ])
            ->assertStatus(422);
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $email,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, (string) $login->json('data.token')];
    }
}
