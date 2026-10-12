<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Email\EmailTemplateLibrary;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ChatBusinessPresentationLeftoverIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
    }

    public function test_chat_web_and_inbox_work_without_pos_javascript(): void
    {
        [$owner, $workspace] = $this->companyWorkspace();
        $this->enableWorkspaceFeatures($workspace, ['conversations']);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Leftover Isolation Customer',
            'phone' => '0503333444',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'external_id' => 'leftover-isolation:inbox',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.dashboard'))
            ->assertOk();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Leftover Isolation Customer');

        $appJs = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString('pos-feedback', $appJs);
        $this->assertStringNotContainsString('posCartUi', $appJs);
        $this->assertStringNotContainsString('HasoPosFeedback', $appJs);
        $this->assertFileDoesNotExist(resource_path('js/pos-feedback.js'));
    }

    public function test_chat_realtime_keeps_inbox_channels_and_rejects_pos_broadcast(): void
    {
        [$owner, $workspace] = $this->companyWorkspace();

        $channels = (string) file_get_contents(base_path('routes/channels.php'));
        $realtime = (string) file_get_contents(resource_path('js/inbox-realtime.js'));

        $this->assertStringContainsString('workspace.{workspaceId}.conversations', $channels);
        $this->assertStringContainsString('workspace.{workspaceId}.conversation.{conversationId}', $channels);
        $this->assertStringNotContainsString('.pos', $channels);
        $this->assertStringContainsString('workspace.${workspaceId}.conversations', $realtime);
        $this->assertStringNotContainsString('.pos', $realtime);
        $this->assertFileDoesNotExist(app_path('Events/OrderUpdated.php'));
        $this->assertFileDoesNotExist(app_path('Events/OrderCancelled.php'));

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.pos',
            ])
            ->assertForbidden();
    }

    public function test_chat_email_library_keeps_industry_finance_templates(): void
    {
        $categories = EmailTemplateLibrary::categories();

        $this->assertArrayHasKey('finance', $categories);
        $this->assertArrayHasKey('construction', $categories);
        $this->assertTrue(EmailTemplateLibrary::exists('finance_welcome'));
        $this->assertTrue(View::exists('emails.library.finance.welcome'));
        $this->assertTrue(View::exists('emails.library.finance.invoice'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function companyWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
