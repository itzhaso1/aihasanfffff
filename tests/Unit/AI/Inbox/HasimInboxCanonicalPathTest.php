<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Core\Data\ModelResponse;
use App\Exceptions\UsageLimitExceededException;
use App\Jobs\ProcessAIResponse;
use App\Models\AiLog;
use App\Models\AiSetting;
use App\Models\Message;
use App\Models\Subscription;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AI\Core\ScriptedModelGateway;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class HasimInboxCanonicalPathTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_inbox_always_uses_hasim_and_never_legacy_providers(): void
    {
        $fake = $this->bindLegacyProvider();
        [, $conversation, $message, $employee] = $this->seedHasimInbox();
        [$gateway] = $this->bindHasimRuntime();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame(0, $fake->calls);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame('أهلا، أقدر أساعدك في اختيار المنتج.', $reply);
        $this->assertSame((int) $employee->id, $this->boundEmployeeId($conversation));
        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertTrue((bool) data_get($log->input_payload, 'hasim_runtime'));
        $this->assertSame((int) $employee->id, (int) $log->ai_employee_id);
        $this->assertArrayNotHasKey('inbox_agent', $log->input_payload ?? []);
        $this->assertArrayNotHasKey('inbox_agent_fallback', $log->input_payload ?? []);
    }

    public function test_hasim_failure_does_not_fall_back_to_legacy_or_canned_success(): void
    {
        $fake = $this->bindLegacyProvider();
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new RuntimeException('gateway down')]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('', $reply);
        $this->assertSame(0, $fake->calls);
        $this->assertNotSame('تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.', $reply);
        $this->assertNotSame('تعذر إنشاء رد مناسب حالياً.', $reply);
        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertTrue((bool) data_get($log->input_payload, 'hasim_runtime'));
    }

    public function test_inactive_ai_setting_skips_hasim_and_legacy(): void
    {
        $fake = $this->bindLegacyProvider();
        [$workspace, $conversation, $message] = $this->seedHasimInbox();
        $gateway = new ScriptedModelGateway([new ModelResponse('should-not-run')]);
        $this->bindHasimRuntime($gateway);
        AiSetting::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Off',
            'instructions' => 'off',
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'is_active' => false,
        ]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('', $reply);
        $this->assertSame(0, $fake->calls);
        $this->assertSame([], $gateway->requests);
    }

    public function test_usage_limits_are_not_swallowed(): void
    {
        $this->bindHasimRuntime();
        [, $conversation, $message] = $this->seedHasimInbox();
        $plan = Subscription::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->firstOrFail()
            ->plan;
        $plan->update(['limits' => ['ai_usage' => 0, 'ai_tokens' => 0]]);

        $this->expectException(UsageLimitExceededException::class);
        app(AIService::class)->generateReply($conversation, $message);
    }

    public function test_process_ai_response_still_produces_a_single_outbound_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime();

        $job = new ProcessAIResponse((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->count());
        $this->assertSame(
            'أهلا، أقدر أساعدك في اختيار المنتج.',
            (string) Message::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('ai_generated', true)
                ->value('content'),
        );
    }
}
