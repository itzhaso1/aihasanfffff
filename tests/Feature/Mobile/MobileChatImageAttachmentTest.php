<?php

namespace Tests\Feature\Mobile;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileChatImageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_jpeg_upload_stores_file_and_returns_canonical_urls(): void
    {
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token, $message] = $this->authWithMessage();

        $upload = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->post('/api/mobile/v1/messages/'.$message->id.'/attachments', [
                'file' => UploadedFile::fake()->image('photo.jpg', 40, 40),
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($upload->json('data.id'));
        $this->assertSame('image', $upload->json('data.kind'));
        $this->assertSame('photo.jpg', $upload->json('data.original_name'));
        $downloadUrl = $upload->json('data.download_url');
        $previewUrl = $upload->json('data.preview_url');
        $this->assertIsString($downloadUrl);
        $this->assertNotSame('', $downloadUrl);
        $this->assertIsString($previewUrl);
        $this->assertStringContainsString('/api/mobile/v1/attachments/', $downloadUrl);
        $this->assertStringContainsString('signature=', $downloadUrl);
        $this->assertStringContainsString('workspace=', $downloadUrl);
        $this->assertStringNotContainsString('/inbox/', (string) parse_url($downloadUrl, PHP_URL_PATH));

        $attachment = MessageAttachment::withoutGlobalScopes()->findOrFail((int) $upload->json('data.id'));
        $this->assertSame((int) $workspace->id, (int) $attachment->workspace_id);
        $this->assertSame((int) $message->id, (int) $attachment->message_id);
        Storage::disk($attachment->disk)->assertExists($attachment->path);

        $download = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->get($downloadUrl)
            ->assertOk();
        $this->assertStringStartsWith('image/', (string) $download->headers->get('content-type'));
    }

    public function test_png_upload_is_accepted(): void
    {
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token, $message] = $this->authWithMessage();

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->post('/api/mobile/v1/messages/'.$message->id.'/attachments', [
                'file' => UploadedFile::fake()->image('shot.png', 20, 20),
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'image');
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token, $message] = $this->authWithMessage();

        $upload = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->post('/api/mobile/v1/messages/'.$message->id.'/attachments', [
                'file' => UploadedFile::fake()->image('photo.jpg'),
            ])
            ->assertCreated();

        $downloadUrl = $upload->json('data.download_url');
        $this->assertIsString($downloadUrl);
        $parts = parse_url($downloadUrl);
        $relative = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get($relative)->assertUnauthorized();
    }

    public function test_heic_upload_is_rejected(): void
    {
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token, $message] = $this->authWithMessage();

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->post('/api/mobile/v1/messages/'.$message->id.'/attachments', [
                'file' => UploadedFile::fake()->create('IMG_0001.heic', 80, 'image/heic'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, MessageAttachment::withoutGlobalScopes()->count());
        $this->assertNotNull($user);
    }

    public function test_php_upload_is_rejected(): void
    {
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token, $message] = $this->authWithMessage();

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->post('/api/mobile/v1/messages/'.$message->id.'/attachments', [
                'file' => UploadedFile::fake()->create('shell.php', 12, 'application/x-php'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, MessageAttachment::withoutGlobalScopes()->count());
    }

    public function test_attachment_cannot_be_uploaded_to_another_workspace_message(): void
    {
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);
        [$userA, $workspaceA, $tokenA, $messageA] = $this->authWithMessage('a@example.com');
        [$userB, $workspaceB, $tokenB, $messageB] = $this->authWithMessage('b@example.com');

        $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->post('/api/mobile/v1/messages/'.$messageB->id.'/attachments', [
                'file' => UploadedFile::fake()->image('stole.jpg'),
            ])
            ->assertNotFound();

        $this->assertSame(0, MessageAttachment::withoutGlobalScopes()->count());
        $this->assertNotNull($userA);
        $this->assertNotNull($workspaceB);
        $this->assertNotNull($tokenB);
        $this->assertNotNull($messageA);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: string, 3: Message}
     */
    private function authWithMessage(string $email = 'chat-image@example.com'): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل',
            'phone' => '+966500000011',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => '📎',
        ]);

        return [$user, $workspace, $login->json('data.token'), $message];
    }
}
