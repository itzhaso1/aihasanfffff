<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Context\ConversationState;
use App\AI\Context\IntentResolver;
use App\AI\Context\ReferenceResolver;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Inbox\Memory\ConversationFactsMemory;
use App\Services\AI\Inbox\Memory\ConversationRecentMessagesCache;
use App\Services\AI\Response\SmartResponseEngine;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class SmartResponseEngineTest extends TestCase
{
    use CreatesEmployees {
        employee as storeEmployee;
    }
    use RefreshDatabase;

    private Workspace $workspace;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->conversation = Conversation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel' => 'whatsapp',
        ]);
    }

    public function test_recent_messages_cache_sliding_window_and_order(): void
    {
        $cache = app(ConversationRecentMessagesCache::class);

        // Create 6 messages in order
        $messages = [];
        for ($i = 1; $i <= 6; $i++) {
            $messages[] = Message::factory()->create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $this->conversation->id,
                'direction' => $i % 2 === 1 ? 'inbound' : 'outbound',
                'ai_generated' => $i % 2 === 0,
                'content' => "Message {$i}",
            ]);
        }

        $cached = $cache->get((int) $this->workspace->id, (int) $this->conversation->id);

        // Must hold exactly 5 messages
        $this->assertCount(5, $cached);

        // Oldest (Message 1) must be dropped, so messages 2, 3, 4, 5, 6 remain in chronological order
        $this->assertSame('Message 2', $cached[0]['content']);
        $this->assertSame('Message 6', $cached[4]['content']);

        // Check identities
        $this->assertSame('ai_employee', $cached[0]['sender_identity']); // Message 2 was outbound ai
        $this->assertSame('customer', $cached[1]['sender_identity']);    // Message 3 was inbound
        $this->assertSame('ai_employee', $cached[4]['sender_identity']); // Message 6 was outbound ai

        // Verify exact cache key
        $exactKey = "conversation_context:{$this->workspace->id}:{$this->conversation->id}";
        $this->assertTrue(Cache::has($exactKey));
    }

    public function test_recent_messages_cache_identity_mapping_admin_and_human(): void
    {
        $adminUser = User::factory()->create();
        $this->workspace->update(['owner_user_id' => $adminUser->id]);

        $humanUser = User::factory()->create();

        $cache = app(ConversationRecentMessagesCache::class);

        $adminMsg = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'user_id' => $adminUser->id,
            'ai_generated' => false,
            'content' => 'مرحباً، أنا مدير المتجر',
        ]);

        $humanMsg = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'user_id' => $humanUser->id,
            'ai_generated' => false,
            'content' => 'أهلاً، أنا موظف خدمة العملاء',
        ]);

        $this->assertSame('admin', $cache->determineSenderIdentity($adminMsg));
        $this->assertSame('human_employee', $cache->determineSenderIdentity($humanMsg));
    }

    public function test_recent_messages_cache_deduplication(): void
    {
        $cache = app(ConversationRecentMessagesCache::class);

        $msg = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'رسالة تجريبية',
        ]);

        $cached1 = $cache->push((int) $this->workspace->id, (int) $this->conversation->id, $msg);
        $count1 = count($cached1);

        // Push same message again (e.g. queue retry or duplicate webhook)
        $cached2 = $cache->push((int) $this->workspace->id, (int) $this->conversation->id, $msg);
        $this->assertCount($count1, $cached2);
    }

    public function test_recent_messages_cache_rebuilds_from_db_on_cache_miss(): void
    {
        $cache = app(ConversationRecentMessagesCache::class);

        Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'رسالة محفوظة في قاعدة البيانات',
        ]);

        // Explicitly clear cache
        $cache->invalidate((int) $this->workspace->id, (int) $this->conversation->id);
        $exactKey = "conversation_context:{$this->workspace->id}:{$this->conversation->id}";
        $this->assertFalse(Cache::has($exactKey));

        // Get must automatically rebuild from DB
        $rebuilt = $cache->get((int) $this->workspace->id, (int) $this->conversation->id);
        $this->assertNotEmpty($rebuilt);
        $this->assertSame('رسالة محفوظة في قاعدة البيانات', $rebuilt[0]['content']);
        $this->assertTrue(Cache::has($exactKey));
    }

    public function test_conversation_facts_memory_extraction_and_db_sync(): void
    {
        $factsMemory = app(ConversationFactsMemory::class);

        // Inbound message with phone, city, quantity, and purchase intent
        $inboundMsg = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'أبغاه، أنا في الرياض ورقمي 0512345678 وأبي حبتين',
        ]);

        $facts = $factsMemory->get((int) $this->workspace->id, (int) $this->conversation->id);

        $this->assertTrue($facts['purchase_intent']);
        $this->assertSame('0512345678', $facts['phone_number']);
        $this->assertSame('الرياض', $facts['city']);
        $this->assertSame(2, $facts['quantity']);

        // Check database synchronization in conversation metadata
        $fresh = $this->conversation->fresh();
        $this->assertIsArray($fresh->metadata['conversation_facts']);
        $this->assertSame('0512345678', $fresh->metadata['conversation_facts']['phone_number']);
        $this->assertTrue($fresh->metadata['conversation_facts']['purchase_intent']);

        // Outbound reply clarifies price
        $outboundMsg = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'ai_generated' => true,
            'content' => 'سعر المنتج 50 ريال شامل الضريبة.',
        ]);

        $updatedFacts = $factsMemory->get((int) $this->workspace->id, (int) $this->conversation->id);
        $this->assertTrue($updatedFacts['price_clarified']);
    }

    public function test_smart_response_engine_sanitizes_repetitive_greetings_on_follow_up(): void
    {
        $engine = app(SmartResponseEngine::class);

        // Simulate prior message in conversation
        Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'السلام عليكم، كم سعر كتاب Excel؟',
        ]);

        $incomingFollowUp = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'كم سعره؟',
        ]);

        // AI generated a reply that unnecessarily repeats greeting and unsolicited questions
        $verboseReply = 'أهلاً وسهلاً بك، يسعدنا اهتمامك بكتاب Excel، وهو من الكتب المميزة لدينا، وسعره 10 ريال فقط، هل ترغب في معرفة المزيد من التفاصيل؟';

        $sanitized = $engine->sanitizeReply(
            $verboseReply,
            $this->conversation,
            $incomingFollowUp,
            ['price_clarified' => false],
        );

        // The sanitized reply must be concise and direct without the repeated greeting or trailing question
        $this->assertStringNotContainsString('أهلاً وسهلاً بك', $sanitized);
        $this->assertStringNotContainsString('هل ترغب في معرفة المزيد', $sanitized);
        $this->assertStringContainsString('10 ريال', $sanitized);
    }

    public function test_smart_response_engine_preserves_greetings_on_initial_message(): void
    {
        $engine = app(SmartResponseEngine::class);

        $newConv = Conversation::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel' => 'chat',
        ]);

        $initialMessage = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $newConv->id,
            'direction' => 'inbound',
            'content' => 'مرحباً',
        ]);

        $greetingReply = 'أهلاً وسهلاً بك! كيف يمكنني مساعدتك؟';

        $sanitized = $engine->sanitizeReply(
            $greetingReply,
            $newConv,
            $initialMessage,
            [],
        );

        // On first message, greeting is preserved
        $this->assertStringContainsString('أهلاً وسهلاً بك', $sanitized);
    }

    public function test_contextual_follow_up_resolves_abgah_to_purchase_intent(): void
    {
        $item = null;
        app(WorkspaceContext::class)->runFor($this->workspace, function () use (&$item): void {
            $item = app(ChatCatalogService::class)->createItem($this->workspace, [
                'name' => 'فستان سهرة أسود',
                'price' => '250.00',
                'currency' => 'SAR',
            ]);
        });
        $roots = app(ChatCatalogRootService::class);
        $roots->publish($roots->forWorkspace($this->workspace));

        $factsMemory = app(ConversationFactsMemory::class);
        $factsMemory->recordPriceStated(
            (int) $this->workspace->id,
            (int) $this->conversation->id,
            (int) $item->id,
            'فستان سهرة أسود',
        );

        // Customer message is just "أبغاه"
        $resolver = app(ReferenceResolver::class);
        $resolution = $resolver->resolve(
            workspace: $this->workspace,
            conversationMetadata: $this->conversation->fresh()->metadata,
            state: new ConversationState,
            currentMessage: 'أبغاه',
            recentMessages: [],
        );

        $this->assertTrue($resolution->resolved);
        $this->assertContains((int) $item->id, $resolution->productIds);

        $intentResolver = app(IntentResolver::class);
        $resolvedIntent = $intentResolver->resolve(
            message: 'أبغاه',
            state: new ConversationState,
            hasProductContext: true,
        );

        $this->assertSame('purchase_intent', $resolvedIntent->canonical);
    }

    public function test_workspace_isolation_in_cache_and_facts(): void
    {
        $workspaceB = Workspace::factory()->create();
        $convB = Conversation::factory()->create(['workspace_id' => $workspaceB->id]);

        $cache = app(ConversationRecentMessagesCache::class);
        $factsMemory = app(ConversationFactsMemory::class);

        // Add message in workspace A
        Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'رسالة شركة أ',
        ]);

        // Add message in workspace B
        Message::factory()->create([
            'workspace_id' => $workspaceB->id,
            'conversation_id' => $convB->id,
            'direction' => 'inbound',
            'content' => 'رسالة شركة ب',
        ]);

        $cachedA = $cache->get((int) $this->workspace->id, (int) $this->conversation->id);
        $cachedB = $cache->get((int) $workspaceB->id, (int) $convB->id);

        $this->assertSame('رسالة شركة أ', $cachedA[0]['content']);
        $this->assertSame('رسالة شركة ب', $cachedB[0]['content']);

        // Verify keys are completely segregated
        $this->assertNotSame(
            ConversationRecentMessagesCache::key((int) $this->workspace->id, (int) $this->conversation->id),
            ConversationRecentMessagesCache::key((int) $workspaceB->id, (int) $convB->id),
        );
    }

    public function test_message_update_and_delete_invalidates_recent_messages_cache(): void
    {
        $cache = app(ConversationRecentMessagesCache::class);

        $msg = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'نص أولي',
        ]);

        $cachedKey = ConversationRecentMessagesCache::key((int) $this->workspace->id, (int) $this->conversation->id);
        $this->assertTrue(Cache::has($cachedKey));

        // Update model -> cache invalidated
        $msg->update(['content' => 'نص معدل']);
        $this->assertFalse(Cache::has($cachedKey));

        // Accessing get() rebuilds fresh
        $rebuilt = $cache->get((int) $this->workspace->id, (int) $this->conversation->id);
        $this->assertSame('نص معدل', $rebuilt[0]['content']);
        $this->assertTrue(Cache::has($cachedKey));

        // Delete model -> cache invalidated
        $msg->delete();
        $this->assertFalse(Cache::has($cachedKey));
    }

    public function test_concurrency_lock_blocks_simultaneous_reply_generation(): void
    {
        $incoming = Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'هل متوفر؟',
        ]);

        $lockKey = "ai_reply_lock:{$this->workspace->id}:{$this->conversation->id}:{$incoming->id}";
        $lock = Cache::lock($lockKey, 30);
        $this->assertTrue($lock->get());

        // A second concurrent process attempts to run the same job
        $secondLock = Cache::lock($lockKey, 30);
        $this->assertFalse($secondLock->get());

        // Release first lock
        $lock->release();

        // Now it can acquire
        $this->assertTrue($secondLock->get());
        $secondLock->release();
    }

    public function test_prompt_rules_payload_reflects_facts_state(): void
    {
        $facts = [
            'interested_product_name' => 'كتاب Excel',
            'price_clarified' => true,
            'delivery_clarified' => true,
            'purchase_intent' => true,
        ];

        $payload = SmartResponseEngine::rulesPayload($facts, hasRecentMessages: true);

        $this->assertTrue($payload['has_prior_turn']);
        $this->assertStringContainsString('Do NOT repeat greetings', $payload['rules']['no_repeated_greetings']);
        $this->assertStringContainsString('Price was already clarified', $payload['rules']['anti_repetition_pricing']);
        $this->assertStringContainsString('Delivery was already clarified', $payload['rules']['anti_repetition_delivery']);
        $this->assertSame('كتاب Excel', $payload['facts_summary']['active_product']);
        $this->assertTrue($payload['facts_summary']['price_already_clarified']);
        $this->assertTrue($payload['facts_summary']['delivery_already_clarified']);
    }
}
