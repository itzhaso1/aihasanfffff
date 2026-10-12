<?php

namespace Tests\Unit;

use App\Models\AiLog;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Services\AI\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class AIServiceWorkspaceIsolationTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    public function test_inbox_hasim_path_does_not_call_legacy_providers_or_other_workspace_catalog(): void
    {
        $fake = $this->bindLegacyProvider();
        [$workspaceA, $conversation, $message] = $this->seedHasimInbox();
        [$gateway] = $this->bindHasimRuntime();

        $workspaceB = Workspace::factory()->create(['type' => 'company']);
        ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'منتج من مساحة أخرى',
            'slug' => 'other-workspace-product',
            'sku' => 'B-OTHER-1',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 3,
            'is_active' => true,
            'item_kind' => 'product',
            'tangibility' => 'tangible',
        ]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame(0, $fake->calls);
        $this->assertCount(1, $gateway->requests);
        $this->assertNotSame('', $reply);
        $prompt = json_encode($gateway->requests[0]->prompt->toArray(), JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('منتج من مساحة أخرى', $prompt);

        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($workspaceA->id, (int) $log->workspace_id);
        $this->assertTrue((bool) data_get($log->input_payload, 'hasim_runtime'));
        $this->assertArrayNotHasKey('messages', $log->input_payload ?? []);
    }
}
