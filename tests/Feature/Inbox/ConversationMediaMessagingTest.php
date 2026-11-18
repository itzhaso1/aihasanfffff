<?php

namespace Tests\Feature\Inbox;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationMediaMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspaceUserAndConversation(): array
    {
        $workspace = Workspace::factory()->create([
            'status' => 'active',
        ]);

        $user = User::factory()->create();
        $user->workspaces()->attach($workspace->id, [
            'membership_role' => 'owner',
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'workspace_id' => $workspace->id,
            'name' => 'العميل التجريبي',
            'phone' => '+966500000001',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$user, $workspace, $conversation, $customer];
    }

    public function test_user_can_upload_single_image_with_caption(): void
    {
        Storage::fake('public');

        [$user, $workspace, $conversation] = $this->createWorkspaceUserAndConversation();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);

        $imageFile = UploadedFile::fake()->image('test_product.jpg', 600, 400);

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'هذه صورة المنتج المطلوب مع الوصف الكامل',
            'attachments' => [$imageFile],
        ]);

        $response->assertOk()
            ->assertJsonPath('message.content', 'هذه صورة المنتج المطلوب مع الوصف الكامل')
            ->assertJsonPath('message.direction', 'outbound')
            ->assertJsonCount(1, 'message.attachments');

        $attachmentData = $response->json('message.attachments.0');
        $this->assertEquals('image', $attachmentData['kind']);
        $this->assertEquals('test_product.jpg', $attachmentData['original_name']);

        $this->assertDatabaseHas('message_attachments', [
            'workspace_id' => $workspace->id,
            'kind' => 'image',
            'original_name' => 'test_product.jpg',
            'status' => 'stored',
        ]);
    }

    public function test_user_can_upload_batch_of_multiple_images(): void
    {
        Storage::fake('public');

        [$user, $workspace, $conversation] = $this->createWorkspaceUserAndConversation();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);

        $img1 = UploadedFile::fake()->image('catalog1.png', 400, 400);
        $img2 = UploadedFile::fake()->image('catalog2.png', 400, 400);
        $img3 = UploadedFile::fake()->image('catalog3.webp', 400, 400);

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'مجموعة صور الكتالوج',
            'attachments' => [$img1, $img2, $img3],
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'message.attachments');

        $this->assertDatabaseCount('message_attachments', 3);
    }

    public function test_user_can_upload_document_file(): void
    {
        Storage::fake('public');

        [$user, $workspace, $conversation] = $this->createWorkspaceUserAndConversation();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);

        $pdfFile = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'مرفق لكم العقد بصيغة PDF',
            'attachments' => [$pdfFile],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'message.attachments')
            ->assertJsonPath('message.attachments.0.kind', 'file');

        $this->assertDatabaseHas('message_attachments', [
            'workspace_id' => $workspace->id,
            'kind' => 'file',
            'original_name' => 'contract.pdf',
        ]);
    }

    public function test_media_gallery_api_returns_attachments_for_conversation(): void
    {
        Storage::fake('public');

        [$user, $workspace, $conversation] = $this->createWorkspaceUserAndConversation();

        $message = Message::create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'image',
            'content' => 'صورة في المحادثة',
        ]);

        MessageAttachment::create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'provider' => 'web_inbox',
            'disk' => 'public',
            'path' => 'attachments/fake.jpg',
            'original_name' => 'fake.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12345,
            'kind' => 'image',
            'status' => MessageAttachment::STATUS_STORED,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->getJson(route('workspace.conversations.media', $conversation));

        $response->assertOk()
            ->assertJsonCount(1, 'media')
            ->assertJsonPath('media.0.original_name', 'fake.jpg')
            ->assertJsonPath('counts.image', 1);
    }

    public function test_attachment_download_is_protected_against_cross_workspace_idor(): void
    {
        Storage::fake('public');

        // Workspace A
        [$userA, $workspaceA, $conversationA] = $this->createWorkspaceUserAndConversation();
        $messageA = Message::create([
            'workspace_id' => $workspaceA->id,
            'conversation_id' => $conversationA->id,
            'direction' => 'outbound',
            'message_type' => 'image',
            'content' => 'سري للشركة A',
        ]);

        $fakeFilePath = 'attachments/workspace_a_secret.jpg';
        Storage::disk('public')->put($fakeFilePath, 'fake image content');

        $attachmentA = MessageAttachment::create([
            'workspace_id' => $workspaceA->id,
            'message_id' => $messageA->id,
            'provider' => 'web_inbox',
            'disk' => 'public',
            'path' => $fakeFilePath,
            'original_name' => 'secret.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 500,
            'kind' => 'image',
            'status' => MessageAttachment::STATUS_STORED,
        ]);

        // User B from Workspace B
        [$userB, $workspaceB] = $this->createWorkspaceUserAndConversation();

        // 1. User A can download attachment A
        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.message-attachments.download', $attachmentA))
            ->assertOk();

        // 2. User B in Workspace B tries to download attachment A -> MUST be 403 or 404
        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.message-attachments.download', $attachmentA))
            ->assertNotFound();
    }

    public function test_forbidden_file_types_are_rejected(): void
    {
        Storage::fake('public');

        [$user, $workspace, $conversation] = $this->createWorkspaceUserAndConversation();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);

        $dangerousFile = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'محاولة رفع ملف تنفيذي',
            'attachments' => [$dangerousFile],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_oversized_files_are_rejected(): void
    {
        Storage::fake('public');

        [$user, $workspace, $conversation] = $this->createWorkspaceUserAndConversation();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);

        // File larger than 20MB (e.g. 25MB = 25600 KB)
        $hugeFile = UploadedFile::fake()->create('huge_video.mp4', 25600, 'video/mp4');

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'ملف ضخم جداً',
            'attachments' => [$hugeFile],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);
    }
}
