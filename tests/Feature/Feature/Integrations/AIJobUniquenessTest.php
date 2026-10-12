<?php

namespace Tests\Feature\Feature\Integrations;

use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class AIJobUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_id_is_workspace_scoped(): void
    {
        $job = new ProcessAIResponse(10, 20, 99);

        $this->assertSame('ai-reply:99:10:20', $job->uniqueId());
    }

    public function test_duplicate_handle_produces_single_ai_reply(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '966500000010',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hello',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('automated reply');
        $this->app->instance(AIService::class, $ai);

        $job = new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ai_generated', true)
            ->count());
    }

    public function test_duplicate_dispatch_collapses_under_should_be_unique(): void
    {
        Bus::fake();

        $workspace = Workspace::factory()->create();
        ProcessAIResponse::dispatch(1, 2, $workspace->id);
        ProcessAIResponse::dispatch(1, 2, $workspace->id);
        ProcessAIResponse::dispatch(1, 2, $workspace->id);

        Bus::assertDispatched(ProcessAIResponse::class, 1);
    }
}
