<?php

namespace Tests\Unit\AI\Inbox;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\AIService;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class InboxAgentLiveSettingsRegressionTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    public function test_ai_active_true_uses_hasim_not_legacy_providers(): void
    {
        $fake = new FakeAiProvider;
        $fake->content = 'رد نشط';
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        [$workspace, $conversation, $message] = $this->seedFixtures(['is_active' => true]);
        $this->employee($workspace);
        [$gateway] = $this->bindHasimRuntime();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('أهلا، أقدر أساعدك في اختيار المنتج.', $reply);
        $this->assertSame(0, $fake->calls);
        $this->assertCount(1, $gateway->requests);
    }

    public function test_ai_inactive_blocks_inbox_reply_generation(): void
    {
        $fake = new FakeAiProvider;
        $fake->content = 'يجب ألا يُستدعى';
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        [$workspace, $conversation, $message] = $this->seedFixtures(['is_active' => false]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('', $reply);
        $this->assertSame(0, $fake->calls);
    }

    /**
     * @param  array<string, mixed>  $settingOverrides
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting}
     */
    private function seedFixtures(array $settingOverrides = []): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $plan = Plan::query()->create([
            'code' => 'live_settings_'.uniqid(),
            'name' => 'Live Settings',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations'],
            'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
        ]);

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan, $settingOverrides): array {
            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ]);

            $customer = Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'عميل',
                'phone' => '+966500000099',
            ]);

            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'web',
                'status' => 'open',
                'ai_enabled' => true,
            ]);

            $message = Message::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'مرحبا',
            ]);

            $setting = AiSetting::query()->create(array_merge([
                'workspace_id' => $workspace->id,
                'name' => 'مساعد الاختبار',
                'instructions' => 'كن مختصرًا.',
                'provider' => 'google_ai_studio',
                'model' => 'gemini-2.5-flash',
                'is_active' => true,
            ], $settingOverrides));

            return [$workspace, $conversation, $message, $setting];
        });
    }
}
