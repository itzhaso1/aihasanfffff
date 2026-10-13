<?php

namespace Tests\Feature\Feature\AI;

use App\Jobs\ExtractInboxAgentMemoryJob;
use App\Jobs\ProcessAIResponse;
use App\Jobs\SendChannelOutboundMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxHonestReplyFallback;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_job_uses_ai_queue(): void
    {
        $job = new ProcessAIResponse(1, 2, 3);

        $this->assertSame('ai', $job->queue);
    }

    public function test_human_takeover_before_generation_prevents_ai_reply(): void
    {
        [$workspace, $conversation, $inbound] = $this->fixtures();

        $conversation->update(['ai_enabled' => false]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateReply');
        $this->app->instance(AIService::class, $ai);

        $this->runAiJob($conversation, $inbound, $workspace);

        $this->assertSame(0, Message::withoutGlobalScopes()->where('ai_generated', true)->count());
    }

    public function test_human_outbound_after_inbound_prevents_stale_ai_reply(): void
    {
        [$workspace, $conversation, $inbound] = $this->fixtures();

        Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'أهلا، معك أحمد',
            'ai_generated' => false,
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateReply');
        $this->app->instance(AIService::class, $ai);

        $this->runAiJob($conversation, $inbound, $workspace);

        $this->assertSame(0, Message::withoutGlobalScopes()->where('ai_generated', true)->count());
    }

    public function test_discards_generated_reply_when_takeover_happens_before_persist(): void
    {
        [$workspace, $conversation, $inbound] = $this->fixtures();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturnUsing(function () use ($conversation): string {
            $conversation->update(['ai_enabled' => false]);

            return 'رد متأخر';
        });
        $this->app->instance(AIService::class, $ai);

        $this->runAiJob($conversation, $inbound, $workspace);

        $this->assertSame(0, Message::withoutGlobalScopes()->where('ai_generated', true)->count());
    }

    public function test_rejects_internal_failure_strings_and_sends_honest_fallback(): void
    {
        [$workspace, $conversation, $inbound] = $this->fixtures();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.');
        $this->app->instance(AIService::class, $ai);

        $this->runAiJob($conversation, $inbound, $workspace);

        $outbound = Message::withoutGlobalScopes()->where('ai_generated', true)->first();
        $this->assertNotNull($outbound);
        $this->assertSame(InboxHonestReplyFallback::GREETING, $outbound->content);
        $this->assertStringNotContainsString('تعذر إنشاء الرد', (string) $outbound->content);
    }

    public function test_duplicate_ai_job_creates_single_reply(): void
    {
        [$workspace, $conversation, $inbound] = $this->fixtures();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('رد واحد');
        $this->app->instance(AIService::class, $ai);

        Queue::fake([ExtractInboxAgentMemoryJob::class]);

        $this->runAiJob($conversation, $inbound, $workspace);
        $this->runAiJob($conversation, $inbound, $workspace);

        $this->assertSame(1, Message::withoutGlobalScopes()->where('ai_generated', true)->count());
    }

    public function test_ai_job_does_not_call_provider_http(): void
    {
        [$workspace, $conversation, $inbound] = $this->fixtures();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('رد آمن');
        $this->app->instance(AIService::class, $ai);

        Http::fake();
        Queue::fake([ExtractInboxAgentMemoryJob::class, SendChannelOutboundMessage::class]);

        $this->runAiJob($conversation, $inbound, $workspace);

        Http::assertNothingSent();
        Queue::assertPushed(SendChannelOutboundMessage::class);
    }

    public function test_memory_extraction_job_uses_default_queue_not_ai(): void
    {
        $job = new ExtractInboxAgentMemoryJob(1, 2, 3);

        $this->assertSame('default', $job->queue);
    }

    public function test_output_validator_rejects_secrets(): void
    {
        $validator = app(AiOutputValidator::class);

        $this->assertNull($validator->validateForOutbound('token sk-abcdefghijklmnopqrstuvwxyz1234567890'));
    }

    public function test_eligibility_is_workspace_scoped(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        $conversationA = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'channel' => 'manual',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $inboundA = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'conversation_id' => $conversationA->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hello A',
        ]);

        $foreignInbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'conversation_id' => Conversation::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceB->id,
                'channel' => 'manual',
                'status' => 'open',
            ])->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hello B',
        ]);

        $eligibility = app(AiReplyEligibility::class);

        $this->assertTrue($eligibility->mayGenerate($conversationA, $inboundA));
        $this->assertFalse($eligibility->mayGenerate($conversationA, $foreignInbound));
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message}
     */
    private function fixtures(): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $plan = Plan::query()->create([
            'code' => 'ai_hard_'.$workspace->id,
            'name' => 'AI',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations'],
            'limits' => ['ai_usage' => 1000, 'ai_tokens' => 100000],
        ]);
        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '966500000001',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'instagram',
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => ['channel_source' => 'instagram'],
        ]);

        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'مرحبا',
        ]);

        return [$workspace, $conversation, $inbound];
    }

    private function runAiJob(Conversation $conversation, Message $inbound, Workspace $workspace): void
    {
        $job = new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );
    }
}
