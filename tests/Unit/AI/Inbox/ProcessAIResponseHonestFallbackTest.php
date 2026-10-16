<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Core\Data\ModelResponse;
use App\AI\Models\ModelGatewayException;
use App\Enums\ConversationHandoffStatus;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxHonestReplyFallback;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class ProcessAIResponseHonestFallbackTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_an_inbound_team_request_stops_the_ai_job_before_a_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'حولني على الفريق']);
        $this->bindHasimRuntime(modelScript: [
            new ModelResponse('هلا كيف أقدر أساعدك؟'),
        ]);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->handoffStatusEnum()->blocksAi());
        $this->assertFalse($fresh->isAiReplying());
        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_a_reply_that_promises_a_transfer_leaves_ai_mode(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'كم السعر؟']);
        $this->bindHasimRuntime(modelScript: [
            new ModelResponse('أبشر بحولك على الفريق وهم بيكملون معك'),
        ]);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->handoffStatusEnum()->blocksAi());
        $this->assertFalse($fresh->isAiReplying());
        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('metadata->handoff_acknowledgement', true)
            ->count());
    }

    public function test_happy_path_persists_the_model_reply_once(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new ModelResponse('هلا، تفضل كيف أقدر أساعدك؟')]);
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'مرحبا']);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);
        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            ['هلا، تفضل كيف أقدر أساعدك؟'],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_model_gateway_exception_sends_honest_fallback_without_secrets(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'مرحبا']);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andThrow(
            new ModelGatewayException(
                ModelGatewayException::HTTP_ERROR,
                'provider body sk-secret should never be logged',
                503,
            ),
        );
        $this->app->instance(AIService::class, $ai);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $exceptionLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.generation_exception',
        ));

        $this->assertSame(
            [InboxHonestReplyFallback::GREETING],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
        $this->assertNotEmpty($exceptionLogs);
        $this->assertSame(ModelGatewayException::HTTP_ERROR, $exceptionLogs[0]->context['provider_error_code'] ?? null);
        $this->assertSame(503, $exceptionLogs[0]->context['http_status'] ?? null);
        $this->assertStringNotContainsString('sk-secret', json_encode($exceptionLogs[0]->context) ?: '');
    }

    public function test_swallowed_model_failure_still_sends_honest_fallback(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [ModelGatewayException::httpError('google_ai_studio', 500)]);
        Message::withoutGlobalScopes()->whereKey($message->id)->update([
            'content' => 'شو الخدمات المتوفرة عندكم؟',
        ]);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            [InboxHonestReplyFallback::OFFERINGS],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_rejected_model_text_sends_honest_fallback_not_the_invalid_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'مرحبا']);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.');
        $this->app->instance(AIService::class, $ai);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            [InboxHonestReplyFallback::GREETING],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_rejected_fallback_does_not_send_anything(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [ModelGatewayException::emptyResponse()]);
        config([
            'ai.reply.failure_markers' => [
                InboxHonestReplyFallback::GENERIC,
                InboxHonestReplyFallback::GREETING,
                InboxHonestReplyFallback::OFFERINGS,
            ],
        ]);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_model_failure_does_not_create_a_duplicate_fallback(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [
            ModelGatewayException::timeout('google_ai_studio'),
            ModelGatewayException::timeout('google_ai_studio'),
        ]);
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'مرحبا']);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);
        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            [InboxHonestReplyFallback::GREETING],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_model_gateway_failure_logs_job_complete_after_fallback_outbound(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'مرحبا']);
        $this->bindHasimRuntime(modelScript: [ModelGatewayException::httpError('google_ai_studio', 503)]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $completeLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.job_complete',
        ));
        $fallbackLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.honest_fallback',
        ));

        $this->assertNotEmpty($fallbackLogs);
        $this->assertNotEmpty($completeLogs);
        $this->assertSame(
            (int) $message->id,
            (int) ($completeLogs[0]->context['inbound_message_id'] ?? 0),
        );
        $this->assertSame(
            InboxHonestReplyFallback::GREETING,
            Message::withoutGlobalScopes()
                ->whereKey($completeLogs[0]->context['outbound_message_id'] ?? 0)
                ->value('content'),
        );
    }

    public function test_inbound_reopen_from_resolved_allows_one_auto_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
            'status' => 'archived',
            'handoff_status' => ConversationHandoffStatus::Resolved,
            'ai_enabled' => true,
        ]);
        $conversation = $conversation->fresh() ?? $conversation;
        $conversation->update($conversation->attributesForInboundCustomerMessage([
            'last_message_at' => now(),
        ]));
        $this->bindHasimRuntime(modelScript: [new ModelResponse('هلا، تفضل كيف أقدر أساعدك؟')]);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);
        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(ConversationHandoffStatus::None, $conversation->fresh()?->handoffStatusEnum());
        $this->assertSame(
            ['هلا، تفضل كيف أقدر أساعدك؟'],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    public function test_active_handoff_is_not_cleared_by_inbound_attribute_normalization(): void
    {
        [, $conversation] = $this->seedHasimInbox();
        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
            'status' => 'archived',
            'handoff_status' => ConversationHandoffStatus::Active,
            'ai_enabled' => true,
        ]);
        $conversation = $conversation->fresh() ?? $conversation;
        $attributes = $conversation->attributesForInboundCustomerMessage(['last_message_at' => now()]);

        $this->assertSame('open', $attributes['status'] ?? null);
        $this->assertArrayNotHasKey('handoff_status', $attributes);
    }

    public function test_active_handoff_skips_auto_reply_and_fallback(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
            'handoff_status' => ConversationHandoffStatus::Active,
            'ai_enabled' => false,
        ]);
        $this->bindHasimRuntime(modelScript: [ModelGatewayException::httpError('google_ai_studio', 503)]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $skipLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.skipped_precheck',
        ));

        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
        $this->assertNotEmpty($skipLogs);
        $this->assertContains(
            $skipLogs[0]->context['reason'] ?? null,
            ['ai_disabled', 'handoff_active'],
        );
        $this->assertArrayNotHasKey('content', $skipLogs[0]->context);
    }

    public function test_resolved_handoff_blocks_auto_reply_even_when_ai_enabled(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
            'handoff_status' => ConversationHandoffStatus::Resolved,
            'ai_enabled' => true,
        ]);
        $this->bindHasimRuntime(modelScript: [ModelGatewayException::httpError('google_ai_studio', 503)]);

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
    }

    /**
     * @return list<string>
     */
    private function outboundContents(int $workspaceId, int $conversationId): array
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', $conversationId)
            ->where('ai_generated', true)
            ->orderBy('id')
            ->pluck('content')
            ->map(static fn (mixed $content): string => (string) $content)
            ->all();
    }

    private function runAiJob(int $conversationId, int $messageId, int $workspaceId): void
    {
        $job = new ProcessAIResponse($conversationId, $messageId, $workspaceId);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );
    }
}
