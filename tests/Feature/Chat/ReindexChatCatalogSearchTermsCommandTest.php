<?php

namespace Tests\Feature\Chat;

use App\Models\ChatCatalogItemSearchTerm;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReindexChatCatalogSearchTermsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rebuilds_item_search_term_rows_for_workspace(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        app(ChatCatalogRootService::class)->forWorkspace($workspace);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'فستان سهرة',
                'price' => '100.00',
                'currency' => 'SAR',
                'tags' => ['سهرة'],
                'aliases' => ['فستان'],
            ]);
        });

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            ChatCatalogItemSearchTerm::query()->delete();
            $this->assertSame(0, ChatCatalogItemSearchTerm::query()->where('workspace_id', $workspace->id)->count());
        });

        $exitCode = Artisan::call('chat-catalog:reindex-search', ['workspace' => (string) $workspace->id]);

        $this->assertSame(0, $exitCode);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->assertGreaterThanOrEqual(2, ChatCatalogItemSearchTerm::query()->where('workspace_id', $workspace->id)->count());
            $this->assertTrue(
                ChatCatalogItemSearchTerm::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('term', 'فستان')
                    ->where('term_type', 'alias')
                    ->exists()
            );
        });
    }
}
