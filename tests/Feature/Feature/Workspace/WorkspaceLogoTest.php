<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_and_remove_workspace_logo(): void
    {
        Storage::fake('public');
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.logo.update'), [
                'logo' => $this->logoUpload('brand.jpg'),
            ])
            ->assertRedirect();

        $workspace->refresh();
        $path = $workspace->settings['logo_path'] ?? null;

        $this->assertIsString($path);
        $this->assertStringContainsString('workspaces/'.$workspace->id.'/branding/', $path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.logo.destroy'))
            ->assertRedirect();

        $workspace->refresh();
        $this->assertArrayNotHasKey('logo_path', $workspace->settings ?? []);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_member_cannot_change_another_workspace_logo(): void
    {
        Storage::fake('public');
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('company');

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.logo.update'), [
                'logo' => $this->logoUpload('a.jpg'),
            ])
            ->assertRedirect();

        $pathA = $workspaceA->fresh()->settings['logo_path'] ?? null;
        $this->assertIsString($pathA);

        $this->actingAs($ownerB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->post(route('workspace.logo.update'), [
                'logo' => $this->logoUpload('b.jpg'),
            ])
            ->assertRedirect();

        $this->assertSame($pathA, $workspaceA->fresh()->settings['logo_path'] ?? null);
        $this->assertNotSame($pathA, $workspaceB->fresh()->settings['logo_path'] ?? null);
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

    private function logoUpload(string $name): UploadedFile
    {
        $source = public_path('images/hasim-logo.jpg');
        $this->assertFileExists($source);

        return new UploadedFile($source, $name, 'image/jpeg', null, true);
    }
}
