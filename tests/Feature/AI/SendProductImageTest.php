<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Tools\Adapters\InboxToolContextFactory;
use App\Jobs\ProcessAIResponse;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use App\Services\AI\AiMediaStagingService;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\Tools\SendProductImageTool;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class SendProductImageTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
    }

    private function provisionCatalog(Workspace $workspace): ChatCatalog
    {
        return app(ChatCatalogRootService::class)->provision($workspace);
    }

    private function makeContext(Workspace $workspace, Conversation $conversation, Message $message, $employee)
    {
        $request = new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            employeeId: (int) $employee->id,
            message: new AiInboundMessage((string) $message->content, (int) $message->id),
            runMode: AiRunMode::Live,
        );

        return (new InboxToolContextFactory)->make($request);
    }

    public function test_send_product_image_tool_stages_image_when_item_has_image(): void
    {
        [$workspace, $conversation, $incomingMessage, $employee] = $this->seedHasimInbox();

        Storage::disk('public')->put('workspaces/'.$workspace->id.'/chat-catalog/red_dress.jpg', 'fake-image-bytes');

        $item = ChatCatalogItem::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'فستان احمر',
            'price' => '14.00',
            'currency' => 'SAR',
            'image_path' => 'workspaces/'.$workspace->id.'/chat-catalog/red_dress.jpg',
            'is_active' => true,
            'is_visible' => true,
            'is_searchable' => true,
            'publication_status' => 'published',
        ]);

        $this->assertTrue($item->hasImage());
        $this->assertSame('workspaces/'.$workspace->id.'/chat-catalog/red_dress.jpg', $item->primaryImagePath());

        $context = $this->makeContext($workspace, $conversation, $incomingMessage, $employee);

        $tool = app(SendProductImageTool::class);
        $result = $tool->execute($context, [
            'item_id' => $item->id,
        ]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->payload['image_attached']);
        $this->assertTrue($result->payload['has_image']);
        $this->assertSame('فستان احمر', $result->payload['product_name']);

        $staged = app(AiMediaStagingService::class)->consumeStagedAttachments(
            (int) $workspace->id,
            (int) $conversation->id,
        );

        $this->assertCount(1, $staged);
        $this->assertSame('workspaces/'.$workspace->id.'/chat-catalog/red_dress.jpg', $staged[0]['path']);
        $this->assertSame('public', $staged[0]['disk']);
        $this->assertSame('فستان احمر', $staged[0]['product_name']);
    }

    public function test_send_product_image_tool_finds_item_by_fuzzy_arabic_name(): void
    {
        [$workspace, $conversation, $incomingMessage, $employee] = $this->seedHasimInbox();

        Storage::disk('public')->put('workspaces/'.$workspace->id.'/chat-catalog/dress.jpg', 'fake-image-bytes');

        $item = ChatCatalogItem::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'فستان احمر مطرز',
            'price' => '25.00',
            'currency' => 'SAR',
            'image_path' => 'workspaces/'.$workspace->id.'/chat-catalog/dress.jpg',
            'is_active' => true,
            'is_visible' => true,
            'is_searchable' => true,
            'publication_status' => 'published',
        ]);

        $context = $this->makeContext($workspace, $conversation, $incomingMessage, $employee);

        $tool = app(SendProductImageTool::class);
        $result = $tool->execute($context, [
            'product_name' => 'فستان',
        ]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->payload['image_attached']);
        $this->assertSame($item->id, $result->payload['item_id']);
    }

    public function test_send_product_image_tool_reports_no_image_when_item_lacks_image(): void
    {
        [$workspace, $conversation, $incomingMessage, $employee] = $this->seedHasimInbox();

        $item = ChatCatalogItem::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'كتاب تعلم Excel للمبتدئين',
            'price' => '10.00',
            'currency' => 'SAR',
            'image_path' => null,
            'is_active' => true,
            'is_visible' => true,
            'is_searchable' => true,
            'publication_status' => 'published',
        ]);

        $this->assertFalse($item->hasImage());

        $context = $this->makeContext($workspace, $conversation, $incomingMessage, $employee);

        $tool = app(SendProductImageTool::class);
        $result = $tool->execute($context, [
            'item_id' => $item->id,
        ]);

        $this->assertTrue($result->success);
        $this->assertFalse($result->payload['image_attached']);
        $this->assertFalse($result->payload['has_image']);
        $this->assertStringContainsString('لا يحتوي على صورة', $result->payload['message']);
    }

    public function test_send_product_image_tool_reports_not_found_when_item_does_not_exist(): void
    {
        [$workspace, $conversation, $incomingMessage, $employee] = $this->seedHasimInbox();

        $context = $this->makeContext($workspace, $conversation, $incomingMessage, $employee);

        $tool = app(SendProductImageTool::class);
        $result = $tool->execute($context, [
            'item_id' => 99999,
        ]);

        $this->assertTrue($result->success);
        $this->assertFalse($result->payload['item_found']);
        $this->assertFalse($result->payload['image_attached']);
    }

    public function test_process_ai_response_consumes_staged_attachment_and_attaches_to_message(): void
    {
        [$workspace, $conversation, $incomingMessage] = $this->seedHasimInbox();

        app(AiMediaStagingService::class)->stageAttachment(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            attachmentData: [
                'item_id' => 10,
                'product_name' => 'فستان احمر',
                'disk' => 'public',
                'path' => 'workspaces/'.$workspace->id.'/chat-catalog/dress.jpg',
                'original_name' => 'dress.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 12345,
            ],
        );

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateReply')
            ->once()
            ->andReturn('تفضل، هذه صورة الفستان الأحمر وسعره 14 ريال.');

        $this->app->instance(AIService::class, $aiService);

        $job = new ProcessAIResponse(
            conversationId: (int) $conversation->id,
            messageId: (int) $incomingMessage->id,
            workspaceId: (int) $workspace->id,
        );

        $job->handle(
            aiService: $aiService,
            channelOutbound: app(ChannelOutboundService::class),
            eligibility: app(AiReplyEligibility::class),
            outputValidator: app(AiOutputValidator::class),
        );

        $outbound = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame('image', $outbound->message_type);

        $attachment = MessageAttachment::withoutGlobalScopes()
            ->where('message_id', $outbound->id)
            ->first();

        $this->assertNotNull($attachment);
        $this->assertSame('workspaces/'.$workspace->id.'/chat-catalog/dress.jpg', $attachment->path);
        $this->assertSame(MessageAttachment::STATUS_STORED, $attachment->status);
        $this->assertSame('image', $attachment->kind);
        $this->assertSame('public', $attachment->disk);
    }

    public function test_catalog_retrieval_exposes_has_image_to_ai_context(): void
    {
        [$workspace] = $this->seedHasimInbox();

        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            $this->provisionCatalog($workspace);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'فستان احمر',
                'price' => '14.00',
                'currency' => 'SAR',
                'image_path' => 'workspaces/'.$workspace->id.'/chat-catalog/dress.jpg',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'publication_status' => 'published',
            ]);

            ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => 'كتاب تعلم Excel',
                'price' => '10.00',
                'currency' => 'SAR',
                'image_path' => null,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'publication_status' => 'published',
            ]);

            $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'فستان', 5);

            $this->assertSame('listed', $lookup['status']);
            $this->assertNotEmpty($lookup['items']);
            $this->assertTrue($lookup['items'][0]['has_image']);

            $bookLookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'Excel', 5);
            $this->assertSame('listed', $bookLookup['status']);
            $this->assertNotEmpty($bookLookup['items']);
            $this->assertFalse($bookLookup['items'][0]['has_image']);
        });
    }
}
