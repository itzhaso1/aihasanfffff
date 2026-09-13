<?php

namespace Tests\Unit;

use App\Jobs\UpdateAIUsage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceUsageMeter;
use App\Services\AI\AiSafety;
use App\Services\AI\AiUsageAccountant;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSafetyAndUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_caps_ignore_client_expensive_model_and_tokens(): void
    {
        $safety = app(AiSafety::class);

        $this->assertSame('google_ai_studio', $safety->resolveProvider('not-a-provider'));
        $this->assertNotSame('gpt-expensive', $safety->resolveModel('google_ai_studio', 'gpt-expensive'));
        $this->assertContains($safety->resolveModel('google_ai_studio', 'gpt-expensive'), $safety->allowedModels('google_ai_studio'));
        $this->assertSame(1024, $safety->capMaxTokens(99999));
        $this->assertSame(1.0, $safety->capTemperature(9.9));
        $this->assertSame(800, mb_strlen($safety->truncateInput(str_repeat('أ', 1200), 800)));
        $this->assertSame('hello', $safety->sanitizeReply('<script>alert(1)</script>hello'));
        $this->assertSame('[REDACTED]', $safety->redactSecrets('sk-abcdefghijklmnopqrstuv'));
        $this->assertFalse($safety->storesContent());
    }

    public function test_duplicate_usage_event_does_not_double_charge(): void
    {
        $workspace = $this->workspaceWithAiPlan(1000, 100000);

        $job = new UpdateAIUsage($workspace->id, 25, 'evt-duplicate-1');
        $job->handle(app(FeatureAccessService::class), app(AiUsageAccountant::class));
        $job->handle(app(FeatureAccessService::class), app(AiUsageAccountant::class));

        $tokens = WorkspaceUsageMeter::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('meter_key', 'ai_tokens')
            ->first();

        $this->assertNotNull($tokens);
        $this->assertEquals(25, (float) $tokens->used);
    }

    public function test_default_log_metadata_drops_prompt_keys(): void
    {
        $payload = app(AiSafety::class)->logMetadata('google_ai_studio', 'gemini-2.5-flash', 12, 'success', [
            'messages' => [['role' => 'user', 'content' => 'secret prompt']],
            'raw' => ['hello'],
            'product_names' => ['Shoe'],
        ]);

        $this->assertArrayNotHasKey('messages', $payload);
        $this->assertArrayNotHasKey('raw', $payload);
        $this->assertSame(['Shoe'], $payload['product_names']);
        $this->assertFalse($payload['content_stored']);
    }

    private function workspaceWithAiPlan(int $usageLimit, int $tokenLimit): Workspace
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $plan = Plan::query()->create([
            'code' => 'ai_usage_'.$workspace->id,
            'name' => 'AI usage',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations', 'smart_replies'],
            'limits' => ['ai_usage' => $usageLimit, 'ai_tokens' => $tokenLimit],
        ]);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan): void {
            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ]);
        });

        return $workspace;
    }
}
