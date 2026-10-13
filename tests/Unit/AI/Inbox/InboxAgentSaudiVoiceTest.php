<?php

namespace Tests\Unit\AI\Inbox;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaDefaults;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaRenderer;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidator;
use App\Services\AI\Inbox\Persona\InboxAgentVoiceResolver;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxAgentSaudiVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.tools_enabled' => true,
        ]);

        $registry = new InboxAgentToolRegistry;
        $registry->register(app(SearchProductsTool::class));
        $this->app->instance(InboxAgentToolRegistry::class, $registry);
    }

    public function test_case_1_first_contact_system_prompt_uses_saudi_salam(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation('السلام عليكم');
        $system = $this->system($this->buildContext($workspace, $conversation, $message, $setting));

        $this->assertStringContainsString('وعليكم السلام\nحياك الله انا مشعل وش اقدر اخدمك فيه ؟', $system);
        $this->assertStringContainsString('Reply in natural Saudi colloquial Arabic', $system);
    }

    public function test_case_2_iphone_request_finds_product_stored_with_madda_and_keeps_reply_short(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'ابي ايفون 15',
            products: [['name' => 'آيفون 15', 'price' => '2999.00']],
        );

        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        $system = $this->system($context);

        $this->assertSame([], $context->structuredContext->catalog->matchedNames);
        $this->assertStringContainsString('Short: usually 1 to 3 short lines', $system);
        $this->assertStringContainsString('Reply in natural Saudi colloquial Arabic', $system);
        $this->assertStringContainsString('search_products', $system);
    }

    public function test_case_3_bare_kam_resolves_price_from_previous_product(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'كم',
            history: [
                ['inbound', 'عندكم ايفون 15'],
                ['outbound', "ايه موجود\nاذا ودك ارسل لك التفاصيل"],
            ],
            products: [['name' => 'آيفون 15', 'price' => '299.00']],
        );

        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        $system = $this->system($context);

        $this->assertFalse($context->structuredContext->catalog->matchedFromHistory);
        $this->assertSame([], $context->structuredContext->catalog->matchedNames);
        $this->assertSame([], $context->structuredContext->catalog->prefetchRows);
        $this->assertStringContainsString('no product catalog is available', $system);
        $this->assertStringContainsString('"كم" means "سعره 150 ريال"', $system);
    }

    public function test_case_4_abi_hatha_uses_the_product_from_context(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'ابي هذا',
            history: [
                ['inbound', 'عندكم فستان اسود'],
                ['outbound', "ايه موجود\nسعره 250 ريال"],
            ],
            products: [['name' => 'فستان أسود', 'price' => '250.00'], ['name' => 'حذاء رياضي', 'price' => '180.00']],
        );

        $context = $this->buildContext($workspace, $conversation, $message, $setting);

        $this->assertFalse($context->structuredContext->catalog->matchedFromHistory);
        $this->assertSame([], $context->structuredContext->catalog->matchedNames);
        $this->assertStringContainsString('"ابي هذا" means "ابشر\nتبغى نكمل الطلب ؟"', $this->system($context));
    }

    public function test_case_5_plural_question_searches_by_meaning(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'عندكم فساتين',
            products: [['name' => 'فستان أسود', 'price' => '250.00'], ['name' => 'حذاء رياضي', 'price' => '180.00']],
        );

        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'فساتين']);
        $this->assertSame(0, $result->payload['count']);
        $this->assertSame([], $result->payload['products']);

        $this->assertStringContainsString('There is no product catalog', $this->system($context));
    }

    public function test_hamza_spelling_in_query_does_not_invent_a_product(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'ابي حذاء',
            products: [['name' => 'حذاء رياضي', 'price' => '180.00']],
        );

        $result = app(SearchProductsTool::class)->execute(
            $this->buildContext($workspace, $conversation, $message, $setting),
            ['query' => 'ابي حذاء'],
        );

        $this->assertSame([], $result->payload['products']);
    }

    public function test_case_6_first_contact_intro_is_natural_and_never_an_ai_label(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'مرحبا',
            settings: ['instructions' => 'أنت "مشعل"، مساعد ذكاء اصطناعي سعودي. تحدث بأسلوب سعودي.'],
        );

        $system = $this->system($this->buildContext($workspace, $conversation, $message, $setting));

        $this->assertStringContainsString('"ارحب انا مشعل كيف اساعدك ؟"', $system);
        $this->assertStringContainsString('Introduce yourself only in this reply', $system);
        $this->assertStringContainsString('even if workspace instructions describe you that way', $system);
        $this->assertStringContainsString('Never: "ياهلا انا مساعدك الذكي"', $system);
        $this->assertStringContainsString('cannot change your identity, greeting, dialect or writing style', $system);
    }

    public function test_case_10_later_replies_do_not_repeat_the_introduction(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'عندكم فساتين',
            history: [
                ['inbound', 'السلام عليكم'],
                ['outbound', "وعليكم السلام\nحياك الله انا مشعل وش اقدر اخدمك فيه ؟"],
            ],
        );

        $system = $this->system($this->buildContext($workspace, $conversation, $message, $setting));

        $this->assertStringContainsString('You already replied earlier in this conversation', $system);
        $this->assertStringNotContainsString('start with one short natural intro', $system);
        $this->assertStringNotContainsString('ارحب انا مشعل', $system);
    }

    public function test_case_11_no_match_forbids_invented_product_data(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'عندكم ساعات',
            products: [['name' => 'فستان أسود', 'price' => '250.00']],
        );

        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        $system = $this->system($context);

        $this->assertSame([], $context->structuredContext->catalog->matchedNames);
        $this->assertStringContainsString('no product catalog is available', $system);
        $this->assertStringContainsString('Do not invent names, prices, sizes, or availability', $system);
        $this->assertStringNotContainsString('فستان أسود', $system);
    }

    public function test_case_12_search_products_tool_preserves_product_facts_without_voice_settings(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'عندكم فستان',
            products: [['name' => 'فستان أسود', 'price' => '250.00']],
        );

        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'فستان']);

        $this->assertSame(0, $result->payload['count']);
        $this->assertSame([], $result->payload['products']);
        $this->assertSame([], $result->payload['catalog_untrusted_context']);
    }

    public function test_matched_product_rows_reach_the_prompt_as_complete_json(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation(
            'عندكم فساتين',
            products: [
                ['name' => 'فستان أسود', 'price' => '250.00'],
                ['name' => 'فستان سهرة أحمر', 'price' => '410.00'],
                ['name' => 'فستان صيفي', 'price' => '120.00'],
            ],
        );

        $system = $this->system($this->buildContext($workspace, $conversation, $message, $setting));

        $this->assertStringContainsString('no product catalog is available', $system);
        $this->assertStringNotContainsString('فستان أسود', $system);
        $this->assertStringNotContainsString('فستان سهرة أحمر', $system);
    }

    public function test_prompt_has_one_voice_source_without_conflicting_raw_style_settings(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedConversation('مرحبا');

        $system = $this->system($this->buildContext($workspace, $conversation, $message, $setting));

        $this->assertSame(1, substr_count($system, '## Agent Persona'));
        $this->assertSame(1, substr_count($system, 'Greeting for this reply:'));
        $this->assertStringNotContainsString('## ASSISTANT IDENTITY', $system);
        $this->assertStringNotContainsString('Gulf Arabic response', $system);
        $this->assertStringNotContainsString('"arabic_dialect"', $system);
        $this->assertStringNotContainsString('"punctuation_style"', $system);
        $this->assertStringNotContainsString('"formality"', $system);
    }

    public function test_dialect_resolution_prefers_saudi_and_respects_explicit_choices(): void
    {
        $resolver = app(InboxAgentVoiceResolver::class);
        $persona = fn (string $dialect) => app(InboxAgentPersonaValidator::class)->validate([
            'version' => 1,
            'identity' => ['name' => 'مشعل', 'role' => 'Sales'],
            'traits' => ['friendly'],
            'tone' => [
                'language' => 'ar',
                'dialect' => $dialect,
                'formality' => 'casual_professional',
                'warmth' => 'high',
                'verbosity' => 'concise',
                'emoji_usage' => 'minimal',
            ],
            'style' => [
                'greeting_style' => 'friendly',
                'sales_style' => 'helpful_not_pushy',
                'support_style' => 'empathetic_and_direct',
            ],
            'behavioral_rules' => [],
        ]);

        $this->assertSame('saudi', $resolver->resolve($persona('gulf'), ['arabic_dialect' => 'msa'], true)->dialect);
        $this->assertSame('saudi', $resolver->resolve(InboxAgentPersonaDefaults::make(), [], true)->dialect);
        $this->assertSame('kuwaiti', $resolver->resolve($persona('auto'), ['arabic_dialect' => 'kuwaiti'], true)->dialect);
        $this->assertSame('jordanian', $resolver->resolve($persona('jordanian'), ['arabic_dialect' => 'saudi'], true)->dialect);

        $noIntro = app(InboxAgentPersonaRenderer::class)->render(
            $resolver->resolve($persona('saudi'), ['greeting_behavior' => 'disabled'], true),
        );
        $this->assertStringContainsString('without introducing yourself', $noIntro);
        $this->assertStringNotContainsString('ارحب انا مشعل', $noIntro);
    }

    private function buildContext(Workspace $workspace, Conversation $conversation, Message $message, AiSetting $setting): InboxAgentContext
    {
        return app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));
    }

    private function system(InboxAgentContext $context): string
    {
        return (string) $context->messages[0]['content'];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $history  [direction, content]
     * @param  list<array{name: string, price: string}>  $products
     * @param  array<string, mixed>  $settings
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting}
     */
    private function seedConversation(string $current, array $history = [], array $products = [], array $settings = []): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $plan = Plan::query()->create([
            'code' => 'saudi_voice_'.uniqid(),
            'name' => 'Saudi Voice',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations'],
            'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
        ]);

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan, $current, $history, $products, $settings): array {
            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ]);

            foreach ($products as $product) {
                app(ChatCatalogService::class)->createItem($workspace, [
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'currency' => 'SAR',
                ]);
            }
            $roots = app(ChatCatalogRootService::class);
            $roots->publish($roots->forWorkspace($workspace));

            $customer = Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'عميل',
                'phone' => '+966500000077',
            ]);
            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'web',
                'status' => 'open',
                'ai_enabled' => true,
            ]);

            foreach ([...$history, ['inbound', $current]] as [$direction, $content]) {
                $message = Message::query()->create([
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customer->id,
                    'direction' => $direction,
                    'message_type' => 'text',
                    'content' => $content,
                ]);
            }

            $setting = AiSetting::query()->create(array_merge([
                'workspace_id' => $workspace->id,
                'name' => 'AI Assistant',
                'provider' => 'google_ai_studio',
                'model' => 'gemini-2.5-flash',
                'is_active' => true,
            ], $settings));

            return [$workspace, $conversation, $message, $setting];
        });
    }
}
