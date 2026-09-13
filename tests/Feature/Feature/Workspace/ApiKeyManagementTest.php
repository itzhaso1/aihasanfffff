<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_keys_page_lists_keys_and_create_shows_plaintext_once(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $this->enableFeature($workspace, 'api');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.api-keys.store'), ['name' => 'تكامل تجريبي'])
            ->assertRedirect(route('workspace.api-keys.index'));

        $key = ApiKey::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($key);
        $this->assertNotSame('', (string) $key->key_hash);
        $this->assertStringStartsWith('hs_', (string) $key->key_prefix);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.api-keys.index'))
            ->assertOk()
            ->assertSee('مفاتيح API')
            ->assertSee('تكامل تجريبي');
    }

    public function test_api_key_service_stores_hash_only(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $service = app(ApiKeyService::class);

        $result = $service->create($workspace, 'CI Key');
        $plain = $result['plain_text'];
        $apiKey = $result['api_key'];

        $this->assertSame(hash('sha256', $plain), $apiKey->key_hash);
        $this->assertDatabaseHas('api_keys', [
            'id' => $apiKey->id,
            'key_hash' => hash('sha256', $plain),
        ]);
        $this->assertDatabaseMissing('api_keys', [
            'key_prefix' => $plain,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function enableFeature(Workspace $workspace, string $feature): void
    {
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => $feature],
            ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
        );
    }
}
