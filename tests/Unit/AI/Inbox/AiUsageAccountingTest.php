<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Decision\ModelUsage;
use App\Exceptions\UsageLimitExceededException;
use App\Jobs\ProcessAIResponse;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\WorkspaceUsageMeter;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxHonestReplyFallback;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class AiUsageAccountingTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_alias_only_plan_counts_one_reply_and_ignores_phantom_token_cap(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, ['ai_usage' => 2000]);
        $this->bindHasimRuntime(modelScript: [
            new ModelResponse(
                'هلا، تفضل كيف أقدر أساعدك؟',
                usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 1800, 400),
            ),
        ]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('هلا، تفضل كيف أقدر أساعدك؟', $reply);
        $this->assertSame(1.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_tokens'));
    }

    public function test_duplicate_generate_reply_does_not_double_count_the_same_message(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, ['ai_usage' => 2000]);
        $this->bindHasimRuntime(modelScript: [
            new ModelResponse('رد أول', usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 20, 10)),
            new ModelResponse('رد ثان', usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 20, 10)),
        ]);

        app(AIService::class)->generateReply($conversation, $message);
        app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame(1.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_tokens'));
    }

    public function test_pre_generation_usage_limit_skips_the_model_and_does_not_consume(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, ['ai_usage' => 0]);
        [$gateway] = $this->bindHasimRuntime(modelScript: [
            new ModelResponse('should-not-run'),
        ]);

        try {
            app(AIService::class)->generateReply($conversation, $message);
            $this->fail('Expected the pre-generation usage limit to stop generation.');
        } catch (UsageLimitExceededException $exception) {
            $this->assertSame('ai_usage', $exception->meter);
            $this->assertSame(UsageLimitExceededException::PHASE_BEFORE_GENERATION, $exception->phase);
        }

        $this->assertSame([], $gateway->requests);
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
    }

    public function test_accounting_failure_after_generation_does_not_return_or_count_the_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, [
            'ai_usage' => 10,
            'ai_tokens' => 300,
        ]);
        [$gateway] = $this->bindHasimRuntime(modelScript: [
            new ModelResponse(
                'هلا، تفضل كيف أقدر أساعدك؟',
                usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 200, 200),
            ),
        ]);

        try {
            app(AIService::class)->generateReply($conversation, $message);
            $this->fail('Expected post-generation accounting to reject the unmetered reply.');
        } catch (UsageLimitExceededException $exception) {
            $this->assertSame('ai_tokens', $exception->meter);
            $this->assertSame(300.0, (float) $exception->limit);
            $this->assertSame(0.0, (float) $exception->used);
            $this->assertSame(UsageLimitExceededException::PHASE_AFTER_GENERATION, $exception->phase);
        }

        $this->assertCount(1, $gateway->requests);
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_tokens'));
    }

    public function test_released_claim_allows_a_later_settlement_without_double_count(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, [
            'ai_usage' => 10,
            'ai_tokens' => 300,
        ]);
        $this->bindHasimRuntime(modelScript: [
            new ModelResponse('أول محاولة', usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 200, 200)),
            new ModelResponse('ثاني محاولة', usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 20, 10)),
        ]);

        try {
            app(AIService::class)->generateReply($conversation, $message);
            $this->fail('Expected the first attempt to fail accounting.');
        } catch (UsageLimitExceededException $exception) {
            $this->assertSame(UsageLimitExceededException::PHASE_AFTER_GENERATION, $exception->phase);
        }

        $this->setPlanLimits((int) $conversation->workspace_id, [
            'ai_usage' => 10,
            'ai_tokens' => 100000,
        ]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('ثاني محاولة', $reply);
        $this->assertSame(1.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
        $this->assertSame(30.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_tokens'));
    }

    public function test_process_ai_response_logs_skipped_limits_before_generation_and_sends_nothing(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, ['ai_usage' => 0]);
        $this->bindHasimRuntime();
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $skipLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.skipped_limits',
        ));
        $accountingLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.usage_accounting_failed',
        ));

        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
        $this->assertCount(1, $skipLogs);
        $this->assertSame([], $accountingLogs);
        $this->assertSame('ai_usage', $skipLogs[0]->context['meter'] ?? null);
        $this->assertSame(UsageLimitExceededException::PHASE_BEFORE_GENERATION, $skipLogs[0]->context['phase'] ?? null);
        $this->assertArrayNotHasKey('reason', $skipLogs[0]->context);
        $this->assertArrayNotHasKey('content', $skipLogs[0]->context);
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
    }

    public function test_process_ai_response_does_not_send_or_fallback_when_accounting_fails_after_generation(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->setPlanLimits((int) $conversation->workspace_id, [
            'ai_usage' => 10,
            'ai_tokens' => 300,
        ]);
        $this->bindHasimRuntime(modelScript: [
            new ModelResponse(
                'هلا، تفضل كيف أقدر أساعدك؟',
                usage: new ModelUsage('google_ai_studio', 'gemini-2.5-flash', 200, 200),
            ),
        ]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $this->runAiJob((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);

        $accountingLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.usage_accounting_failed',
        ));
        $fallbackLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'ai.reply.honest_fallback',
        ));

        $this->assertSame(
            [],
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
        $this->assertNotContains(
            InboxHonestReplyFallback::GREETING,
            $this->outboundContents((int) $conversation->workspace_id, (int) $conversation->id),
        );
        $this->assertCount(1, $accountingLogs);
        $this->assertSame([], $fallbackLogs);
        $this->assertSame('ai_tokens', $accountingLogs[0]->context['meter'] ?? null);
        $this->assertSame(300.0, (float) ($accountingLogs[0]->context['limit'] ?? 0));
        $this->assertSame(0.0, (float) ($accountingLogs[0]->context['used'] ?? -1));
        $this->assertSame(UsageLimitExceededException::PHASE_AFTER_GENERATION, $accountingLogs[0]->context['phase'] ?? null);
        $this->assertArrayNotHasKey('content', $accountingLogs[0]->context);
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_usage'));
        $this->assertSame(0.0, $this->meterUsed((int) $conversation->workspace_id, 'ai_tokens'));
    }

    /**
     * @param  array<string, int|float>  $limits
     */
    private function setPlanLimits(int $workspaceId, array $limits): void
    {
        $plan = Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->firstOrFail()
            ->plan;
        $plan->update(['limits' => $limits]);
    }

    private function meterUsed(int $workspaceId, string $meter): float
    {
        $row = WorkspaceUsageMeter::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('meter_key', $meter)
            ->first();

        return $row ? (float) $row->used : 0.0;
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
