<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Context\ConversationState;
use App\AI\Context\IntentResolver;
use App\AI\Context\ReferenceResolver;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\Checks\TruthCheck;
use App\AI\Inbox\HasimInboxRuntime;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Support\Chat\CatalogBrowseQueryDetector;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class AiSalesConversationTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function provisionCatalog(Workspace $workspace): ChatCatalog
    {
        return app(ChatCatalogRootService::class)->provision($workspace);
    }

    /**
     * Case 1: Question about available books -> browse query retrieves actual catalog items.
     */
    public function test_case_1_asks_about_available_books_returns_listed_catalog_items(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'تعلم لغة بايثون',
                'price' => 45.00,
                'currency' => 'SAR',
                'stock' => 15,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'رواية الخيميائي',
                'price' => 35.00,
                'currency' => 'SAR',
                'stock' => 10,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $query = 'شو الكتب المتوفرة عندك؟';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertSame('listed', $lookup['status']);
        $this->assertCount(2, $lookup['items']);
        $names = array_column($lookup['items'], 'name');
        $this->assertContains('تعلم لغة بايثون', $names);
        $this->assertContains('رواية الخيميائي', $names);

        $detector = app(CatalogBrowseQueryDetector::class);
        $tokens = $retrieval->tokens($query);
        $this->assertTrue($detector->isBrowseCatalogQuery($query, $tokens));
        $this->assertSame('product', $detector->browseItemKindFilter($query, $tokens));
    }

    /**
     * Case 2: Question about price -> exact price from DB without guessing.
     */
    public function test_case_2_asks_about_book_price_returns_exact_db_price(): void
    {
        [$workspace] = $this->seedHasimInbox();

        $book = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
            $this->provisionCatalog($workspace);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتاب أساسيات الذكاء الاصطناعي',
                'price' => 85.00,
                'currency' => 'SAR',
                'stock' => 5,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $query = 'بكم كتاب أساسيات الذكاء الاصطناعي؟';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertSame('listed', $lookup['status']);
        $this->assertNotEmpty($lookup['items']);
        $this->assertSame('85.00', (string) $lookup['items'][0]['price']);
        $this->assertSame('SAR', $lookup['items'][0]['currency']);

        $resolver = app(ReferenceResolver::class);
        $resolution = app(WorkspaceContext::class)->runFor($workspace, fn () => $resolver->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: $query,
        ));

        $this->assertTrue($resolution->resolved);
        $this->assertSame('listed', $resolution->retrievalStatus);
        $this->assertSame((int) $book->id, $resolution->productIds[0]);
    }

    /**
     * Case 3: Question about stock / availability -> factually confirmed.
     */
    public function test_case_3_asks_about_stock_and_availability(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتاب التصميم المعماري',
                'price' => 120.00,
                'currency' => 'SAR',
                'stock' => 7,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $query = 'هل كتاب التصميم المعماري متوفر وكم حبة باقي منه؟';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertSame('listed', $lookup['status']);
        $item = $lookup['items'][0];
        $this->assertSame(7, $item['stock']);
        $this->assertSame('in_stock', $item['availability']);
    }

    /**
     * Case 4: Category question -> matches items under the category.
     */
    public function test_case_4_asks_about_categories_matches_category_items(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);

            $category = ChatCatalogCategory::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتب برمجة وتقنية',
                'slug' => 'programming-books',
                'is_active' => true,
                'is_visible' => true,
            ]);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'chat_catalog_category_id' => $category->id,
                'name' => 'محترف بايثون',
                'price' => 50.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $query = 'عندكم كتب برمجة؟';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertSame('listed', $lookup['status']);
        $this->assertNotEmpty($lookup['items']);
        $this->assertSame('محترف بايثون', $lookup['items'][0]['name']);
    }

    /**
     * Case 5: Contextual follow-up ("طيب شو أسعارها؟") inherits previous product and price intent.
     */
    public function test_case_5_contextual_follow_up_inherits_product_and_price_intent(): void
    {
        [$workspace] = $this->seedHasimInbox();

        $book = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
            $this->provisionCatalog($workspace);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'تعلم بايثون',
                'price' => 45.00,
                'currency' => 'SAR',
                'stock' => 10,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $state = new ConversationState(
            referencedProductIds: [(int) $book->id],
            lastIntent: 'availability_question',
            employeeId: null,
        );

        $followUp = 'طيب شو أسعارها؟';
        $intentResolver = app(IntentResolver::class);
        $resolvedIntent = $intentResolver->resolve($followUp, $state, true);

        $this->assertSame('price_question', $resolvedIntent->canonical);
        $this->assertContains('price', $resolvedIntent->rawIntents);
        $this->assertEmpty($resolvedIntent->searchTokens);

        $referenceResolver = app(ReferenceResolver::class);
        $resolution = app(WorkspaceContext::class)->runFor($workspace, fn () => $referenceResolver->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: $state,
            currentMessage: $followUp,
        ));

        $this->assertTrue($resolution->resolved);
        $this->assertSame('conversation_state', $resolution->source);
        $this->assertSame([(int) $book->id], $resolution->productIds);
        $this->assertSame('45.00', (string) $resolution->catalogItems[0]['price']);
    }

    /**
     * Case 6: Topic shift -> moves cleanly to delivery without confusing contexts.
     */
    public function test_case_6_topic_shift_from_products_to_delivery(): void
    {
        [$workspace] = $this->seedHasimInbox();

        $book = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
            $this->provisionCatalog($workspace);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتاب التاريخ',
                'price' => 60.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $stateWithBook = new ConversationState(
            referencedProductIds: [(int) $book->id],
            lastIntent: 'price_question',
            employeeId: null,
        );

        $shiftMessage = 'كيف التوصيل عندكم وكم بياخذ وقت؟';
        $resolver = app(ReferenceResolver::class);
        $resolution = app(WorkspaceContext::class)->runFor($workspace, fn () => $resolver->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: $stateWithBook,
            currentMessage: $shiftMessage,
        ));

        $this->assertNotSame('conversation_state', $resolution->source);
        $this->assertEmpty($resolution->catalogItems);
    }

    /**
     * Case 7: Repeated question -> handles contextually without crashing.
     */
    public function test_case_7_repeated_question_handled_contextually(): void
    {
        [$workspace, $conversation, $message, $employee, $customer] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتاب البرمجة',
                'price' => 50.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $this->bindHasimRuntime(modelScript: [
            new ModelResponse('سعر كتاب البرمجة 50 ريال.'),
            new ModelResponse('مثل ما ذكرت لك، سعر الكتاب 50 ريال وهو متوفر حالياً.'),
        ]);

        $runtime = app(HasimInboxRuntime::class);
        $msg1 = Message::query()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'بكم كتاب البرمجة؟',
            'ai_generated' => false,
            'metadata' => [],
        ]);

        $res1 = $runtime->reply($conversation, $msg1);
        $this->assertTrue($res1->deliverable);

        $msg2 = Message::query()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'بكم كتاب البرمجة؟',
            'ai_generated' => false,
            'metadata' => [],
        ]);

        $res2 = $runtime->reply($conversation, $msg2);
        $this->assertTrue($res2->deliverable);
    }

    /**
     * Case 8: Vague inquiry ("بدي كتاب مناسب") -> triggers clarifying guidance.
     */
    public function test_case_8_vague_question_clarifying_behavior(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);
        });

        $query = 'بدي كتاب مناسب';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertContains($lookup['status'], ['name_not_found', 'catalog_empty']);
    }

    /**
     * Case 9: Non-existent product -> reported unavailable without hallucination.
     */
    public function test_case_9_non_existent_product_honestly_reported(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتاب بايثون',
                'price' => 40.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $query = 'عندك كتاب الطبخ الصيني؟';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertSame('name_not_found', $lookup['status']);
        $this->assertEmpty($lookup['items']);

        $truthCheck = new TruthCheck;
        $hallucinated = new GuardInput(
            request: new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: 1,
                employeeId: null,
                message: new AiInboundMessage($query),
                runMode: AiRunMode::Live,
            ),
            text: 'نعم كتاب الطبخ الصيني متوفر بسعر 90 ريال.',
            facts: [],
            toolCalls: [],
            confirmedActions: [],
        );

        $finding = $truthCheck->inspect($hallucinated, $hallucinated->text);
        $this->assertSame(GuardVerdict::Rejected, $finding->verdict);
        $this->assertSame('invented_price', $finding->reasonCode);
    }

    /**
     * Case 10: Empty catalog or lookup failure -> safe handling without crash.
     */
    public function test_case_10_empty_catalog_handled_gracefully(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);
        });

        $query = 'شو المنتجات المتوفرة عندكم؟';
        $retrieval = app(ChatCatalogRetrievalService::class);
        $lookup = $retrieval->lookupForAssistant($workspace, $query, 8);

        $this->assertSame('catalog_empty', $lookup['status']);
        $this->assertEmpty($lookup['items']);
    }

    /**
     * Case 11: Insufficient information -> prevents invented facts.
     */
    public function test_case_11_insufficient_information_prevents_hallucination(): void
    {
        [$workspace, $conversation] = $this->seedHasimInbox();

        $truthCheck = new TruthCheck;
        $inventedClaim = new GuardInput(
            request: new AiRequest(
                workspaceId: (int) $workspace->id,
                conversationId: (int) $conversation->id,
                employeeId: null,
                message: new AiInboundMessage('كم وزن كتاب بايثون؟'),
                runMode: AiRunMode::Live,
            ),
            text: 'وزن الكتاب 500 جرام وسعره 100 ريال.',
            facts: [],
            toolCalls: [],
            confirmedActions: [],
        );

        $finding = $truthCheck->inspect($inventedClaim, $inventedClaim->text);
        $this->assertSame(GuardVerdict::Rejected, $finding->verdict);
    }

    /**
     * Case 12: Colloquial Arabic dialects (Gulf, Levantine, Egyptian) detected.
     */
    public function test_case_12_colloquial_arabic_dialects_handled(): void
    {
        $detector = app(CatalogBrowseQueryDetector::class);
        $retrieval = app(ChatCatalogRetrievalService::class);

        $queries = [
            'شو الكتب المتوفرة عندكم؟', // Levantine
            'وش الكتب اللي عندكم؟',    // Gulf / Saudi
            'عندكم كتب؟',             // Pan-Arab
            'ايش الكتب المتوفرة؟',    // Hejazi / Gulf
        ];

        foreach ($queries as $q) {
            $tokens = $retrieval->tokens($q);
            $this->assertTrue(
                $detector->isBrowseCatalogQuery($q, $tokens),
                "Failed asserting browse query for: $q"
            );
        }
    }

    /**
     * Case 13: English language conversation handled cleanly.
     */
    public function test_case_13_english_conversation_handled(): void
    {
        $detector = app(CatalogBrowseQueryDetector::class);
        $retrieval = app(ChatCatalogRetrievalService::class);

        $query = 'What books do you have available?';
        $tokens = $retrieval->tokens($query);
        $this->assertTrue($detector->isBrowseCatalogQuery($query, $tokens));
        $this->assertSame('product', $detector->browseItemKindFilter($query, $tokens));
    }

    /**
     * Case 14: Multi-turn conversation preserves context across turns.
     */
    public function test_case_14_multi_turn_conversation_retains_context(): void
    {
        [$workspace, $conversation, $message, $employee, $customer] = $this->seedHasimInbox();

        $book = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
            $this->provisionCatalog($workspace);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'رواية البؤساء',
                'price' => 60.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $this->bindHasimRuntime(modelScript: [
            new ModelResponse('نعم، رواية البؤساء متوفرة لدينا بسعر 60 ريال.'),
            new ModelResponse('سعر رواية البؤساء 60 ريال.'),
        ]);

        $runtime = app(HasimInboxRuntime::class);

        // Turn 1
        $turn1 = Message::query()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'عندكم رواية البؤساء؟',
            'ai_generated' => false,
            'metadata' => [],
        ]);

        $res1 = $runtime->reply($conversation, $turn1);
        $this->assertTrue($res1->deliverable);

        $conversation->refresh();
        $storedState = data_get($conversation->metadata, 'ai.state');
        $this->assertNotEmpty($storedState['referenced_products']);
        $this->assertContains((int) $book->id, $storedState['referenced_products']);

        // Turn 2: Follow-up without naming the book
        $turn2 = Message::query()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'طيب كم سعرها؟',
            'ai_generated' => false,
            'metadata' => [],
        ]);

        $res2 = $runtime->reply($conversation, $turn2);
        $this->assertTrue($res2->deliverable);
    }

    /**
     * Case 15: Cross-workspace isolation -> no data leakage between tenants.
     */
    public function test_case_15_cross_workspace_data_isolation(): void
    {
        [$workspaceA] = $this->seedHasimInbox();
        [$workspaceB] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($workspaceA): void {
            $this->provisionCatalog($workspaceA);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspaceA->id,
                'name' => 'كتاب سري لشركة أ',
                'price' => 100.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        app(WorkspaceContext::class)->runFor($workspaceB, function () use ($workspaceB): void {
            $this->provisionCatalog($workspaceB);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspaceB->id,
                'name' => 'كتاب خاص لشركة ب',
                'price' => 200.00,
                'currency' => 'SAR',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
            ]);
        });

        $retrieval = app(ChatCatalogRetrievalService::class);

        // Workspace A lookup must only see Workspace A items
        $lookupA = $retrieval->lookupForAssistant($workspaceA, 'شو الكتب المتوفرة؟', 8);
        $this->assertSame('listed', $lookupA['status']);
        $namesA = array_column($lookupA['items'], 'name');
        $this->assertContains('كتاب سري لشركة أ', $namesA);
        $this->assertNotContains('كتاب خاص لشركة ب', $namesA);

        // Workspace B lookup must only see Workspace B items
        $lookupB = $retrieval->lookupForAssistant($workspaceB, 'شو الكتب المتوفرة؟', 8);
        $this->assertSame('listed', $lookupB['status']);
        $namesB = array_column($lookupB['items'], 'name');
        $this->assertContains('كتاب خاص لشركة ب', $namesB);
        $this->assertNotContains('كتاب سري لشركة أ', $namesB);
    }
}
