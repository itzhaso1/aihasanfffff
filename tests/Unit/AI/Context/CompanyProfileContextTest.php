<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Context;

use App\AI\Context\AiContextBuilder;
use App\AI\Context\AiContextProvider;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Prompt\PromptLayer;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Models\WorkspaceCompanyProfile;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class CompanyProfileContextTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_empty_company_profile_keeps_current_ai_context(): void
    {
        [$workspace, $conversation, $current] = $this->conversation();

        $context = app(AiContextBuilder::class)->build(
            $this->request($workspace, $conversation, $current),
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['sales']),
        );
        $payload = app(AiContextProvider::class)->build(
            $this->request($workspace, $conversation, $current),
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['sales']),
        );

        $this->assertFalse($context->companyProfile['present']);
        $this->assertSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['pricing']['status']);
        $this->assertSame('not_loaded', $context->workspacePolicyContext['business_capabilities']['availability']['status']);
        $this->assertNotContains(
            'context.company_profile',
            array_map(static fn ($section): string => $section->key, $payload->sections),
        );
    }

    public function test_company_profile_reaches_auto_reply_and_suggest_reply_context_without_becoming_price_data(): void
    {
        [$workspace, $conversation, $message, , $customer] = $this->seedHasimInbox();
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            WorkspaceCompanyProfile::factory()->create([
                'workspace_id' => $workspace->id,
                'company_name' => 'شركة النور',
                'about' => 'متجر أحذية',
                'offerings' => 'أحذية رياضية بسعر 50 ريال',
                'services' => 'توصيل',
                'audience' => 'شباب',
                'reply_guidelines' => 'اختصر الرد',
            ]);
        });
        [$gateway] = $this->bindHasimRuntime(modelScript: [
            new ModelResponse('اقتراح مناسب من سياق الشركة.'),
            new ModelResponse('رد تلقائي مناسب من سياق الشركة.'),
        ]);

        $builderContext = app(AiContextBuilder::class)->build(
            new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: (int) $conversation->id,
                employeeId: 7,
                message: new AiInboundMessage((string) $message->content, (int) $message->id),
                runMode: AiRunMode::Live,
                channel: 'web',
            ),
            new ResolvedEmployee(employeeId: 7, configVersion: '1', skillKeys: ['sales']),
        );

        $this->assertTrue($builderContext->companyProfile['present']);
        $this->assertSame('شركة النور', $builderContext->companyProfile['company_name']);
        $this->assertSame('not_loaded', $builderContext->workspacePolicyContext['business_capabilities']['pricing']['status']);
        $this->assertSame('not_loaded', $builderContext->workspacePolicyContext['business_capabilities']['availability']['status']);
        $this->assertArrayNotHasKey('price', $builderContext->companyProfile);
        $this->assertStringContainsString('not proof of a specific product, price', (string) $builderContext->companyProfile['usage']);

        $suggestReply = app(AIService::class)->generateReply($conversation, $message);
        $this->assertNotSame('', $suggestReply);
        $this->assertTrue($this->promptHasCompanyProfile($gateway, 'شركة النور'));

        $followUp = app(WorkspaceContext::class)->runFor(
            $workspace,
            fn () => $this->inboundFollowUp($conversation, $customer, 'هل الحذاء متوفر؟'),
        );
        $job = new ProcessAIResponse((int) $conversation->id, (int) $followUp->id, (int) $workspace->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertTrue($this->promptHasCompanyProfile($gateway, 'شركة النور'));
        $this->assertTrue(
            Message::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('conversation_id', $conversation->id)
                ->where('ai_generated', true)
                ->exists()
        );
    }

    public function test_auto_reply_still_runs_when_company_profile_is_missing(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        [$gateway] = $this->bindHasimRuntime();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertNotSame('', $reply);
        $this->assertFalse($this->promptHasCompanyProfile($gateway, 'شركة النور'));
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message}
     */
    private function conversation(): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company', 'name' => 'Profile WS']);
        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $current = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'content' => 'مرحبا',
        ]);

        return [$workspace, $conversation, $current];
    }

    private function request(Workspace $workspace, Conversation $conversation, Message $current): AiRequest
    {
        return new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            employeeId: 7,
            message: new AiInboundMessage((string) $current->content, (int) $current->id),
            runMode: AiRunMode::Live,
            channel: 'web',
        );
    }

    private function promptHasCompanyProfile(object $gateway, string $companyName): bool
    {
        foreach ($gateway->requests as $modelRequest) {
            foreach ($modelRequest->prompt->forLayer(PromptLayer::Context) as $section) {
                if ($section->key === 'context.company_profile' && str_contains($section->content, $companyName)) {
                    return true;
                }
            }
        }

        return false;
    }
}
