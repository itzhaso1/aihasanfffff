<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Core\Data\ModelResponse;
use App\AI\Models\ModelGatewayException;
use App\Jobs\ProcessAIResponse;
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
