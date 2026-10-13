<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\Chat\Storefront\PublicStorefrontRenderer;
use App\Services\Chat\Storefront\StorefrontTemplateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicChatCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_catalog_product_surface_is_gone(): void
    {
        $workspace = Workspace::factory()->create([
            'type' => 'company',
            'slug' => 'public-catalog-workspace',
        ]);
        $user = User::factory()->create();
        $user->workspaces()->attach($workspace->id, [
            'membership_role' => 'owner',
            'status' => 'active',
        ]);

        $this->get('/catalog/'.$workspace->slug)->assertNotFound();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/workspace/chat-catalog')
            ->assertNotFound();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/workspace/chat-catalog/settings')
            ->assertNotFound();

        $this->assertFalse(Route::has('public.chat-catalog.show'));
        $this->assertFalse(Route::has('public.chat-catalog.data'));
        $this->assertFalse(Route::has('public.chat-catalog.product'));
        $this->assertFalse(Route::has('workspace.chat-catalog.index'));
        $this->assertFalse(Route::has('workspace.chat-catalog.settings.edit'));
        $this->assertFalse(Route::has('workspace.chat-catalog.storefront'));
        $this->assertFalse(is_file(app_path('Services/Chat/ChatCatalogRetrievalService.php')));
        $this->assertTrue(class_exists(SearchProductsTool::class));
        $this->assertFalse(class_exists(PublicStorefrontRenderer::class));
        $this->assertFalse(class_exists(StorefrontTemplateResolver::class));
        $this->assertFalse(is_file(resource_path('views/workspace/chat-catalog/index.blade.php')));
        $this->assertFalse(is_file(resource_path('views/workspace/chat-catalog/settings.blade.php')));
    }
}
