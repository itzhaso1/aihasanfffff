<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OmnichannelInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_displays_channel_badges_and_filters_by_channel_source(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Instagram Customer',
            'phone' => '0500001234',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'ig_123',
            'status' => 'open',
            'ai_enabled' => true,
            'last_message_at' => now(),
            'metadata' => [
                'channel_source' => 'instagram',
                'unread_count' => 2,
            ],
        ]);

        Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'Hello from Instagram',
            'external_message_id' => 'ig_msg_1',
            'metadata' => [],
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', [
                'channel' => 'instagram',
                'conversation' => $conversation->id,
            ]))
            ->assertOk()
            ->assertSee('Instagram Customer')
            ->assertSee('Instagram')
            ->assertDontSee('Omnichannel Inbox')
            ->assertDontSee('إدارة جميع محادثات العملاء عبر جميع القنوات من مكان واحد')
            ->assertSee('المحادثات')
            ->assertDontSee('جميع الحالات')
            ->assertSee('تفاصيل العميل')
            ->assertSee('aria-controls="inbox-customer-details"', false)
            ->assertDontSee('عرض تفاصيل العميل')
            ->assertSee('Hello from Instagram')
            ->assertDontSee('external_message_id (اختياري)')
            ->assertDontSee('<select name="direction"', false)
            ->assertDontSee('<select name="message_type"', false)
            ->assertDontSee('لوحة تحكم الأعمال الذكية')
            ->assertDontSee('نظرة عامة على أعمالك');
    }

    public function test_inbox_uses_independent_layout_and_empty_state_when_no_conversation_is_selected(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index'))
            ->assertOk()
            ->assertSee('ابدأ بإدارة محادثاتك')
            ->assertSee('محادثة جديدة')
            ->assertSee('WhatsApp')
            ->assertDontSee('كل القنوات')
            ->assertDontSee('تصفية')
            ->assertDontSee('فتح مساعد حاسم')
            ->assertDontSee('لوحة تحكم الأعمال الذكية')
            ->assertSee('data-hs-shell="communication"', false)
            ->assertSee('data-hs-region', false)
            ->assertSee('data-hs-result', false)
            ->assertDontSee('hs-page-overlay', false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.customers.index'))
            ->assertOk()
            ->assertSee('العملاء')
            ->assertSee('فتح مساعد حاسم')
            ->assertDontSee('لوحة تحكم الأعمال الذكية')
            ->assertSee('data-hs-shell="communication"', false)
            ->assertSee('data-hs-region', false);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
