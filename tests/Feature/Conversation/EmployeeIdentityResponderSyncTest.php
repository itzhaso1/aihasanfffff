<?php

namespace Tests\Feature\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Conversation\ConversationResponderService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmployeeIdentityResponderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_complete_employee_identity_separation_and_sync_scenario(): void
    {
        // 0. Setup Workspace, Owner (Manager), Employee Hasan, Employee Baraa, and Customer
        $owner = User::factory()->create(['name' => 'المدير العام']);
        $hasan = User::factory()->create(['name' => 'حسن الحسينات']);
        $baraa = User::factory()->create(['name' => 'براء']);

        $workspace = Workspace::factory()->create([
            'name' => 'مساحة الاختبار',
            'owner_user_id' => $owner->id,
        ]);

        $workspace->users()->attach([
            $owner->id => ['membership_role' => 'owner', 'status' => 'active'],
            $hasan->id => ['membership_role' => 'agent', 'status' => 'active'],
            $baraa->id => ['membership_role' => 'agent', 'status' => 'active'],
        ]);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'أحمد العميل',
            'phone' => '+966500000001',
        ]);

        $conversation = Conversation::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Assigned,
        ]);

        $handoffService = app(ConversationHandoffService::class);
        $responderService = app(ConversationResponderService::class);

        // 1. Assign conversation to Hasan (تعيين المحادثة لحسن)
        $conversation = $handoffService->assign($conversation, $hasan, actor: $owner);
        $conversation->refresh();

        $this->assertSame($hasan->id, $conversation->assigned_user_id);
        $this->assertSame($hasan->name, $conversation->assignedUser->name);
        $this->assertSame($hasan->name, $conversation->currentResponder()['name']);

        // 2 & 3. Baraa opens conversation. Hasan must remain assigned manager (ظهور حسن كمسؤول)
        $this->actingAs($baraa);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $openResponse = $this->get(route('workspace.conversations.index', ['conversation' => $conversation->id]));
        $openResponse->assertOk();
        $openResponse->assertSee('المسؤول: حسن الحسينات');
        $this->assertFalse(Cache::has("conversation:{$conversation->id}:active_typing"));

        // 4. Baraa starts typing (ظهور براء كموظف نشط فقط إذا بدأ الكتابة فعلياً)
        $typingResponse = $this->postJson(route('workspace.conversations.typing', $conversation), [
            'is_typing' => true,
        ]);
        $typingResponse->assertOk();
        $this->assertTrue(Cache::has("conversation:{$conversation->id}:active_typing"));

        // Active responder now reflects Baraa typing, while assigned employee remains Hasan!
        $liveResponder = $conversation->fresh()->currentResponder();
        $this->assertSame('human_active', $liveResponder['status']);
        $this->assertSame('براء', $liveResponder['name']);
        $this->assertTrue($liveResponder['is_typing']);
        $this->assertSame('الموظف يكتب الآن...', $liveResponder['label']);

        // Assigned employee in DB remains strictly Hasan!
        $this->assertSame($hasan->id, $conversation->fresh()->assigned_user_id);
        $this->assertSame('حسن الحسينات', $conversation->fresh()->assignedUser->name);

        // 5 & 6. Baraa sends a message (إرسال براء رسالة والتأكد من ظهور براء كمرسل للرسالة)
        $sendResponse = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'content' => 'مرحباً، معك براء من فريق الدعم.',
            'direction' => 'outbound',
        ]);
        $sendResponse->assertOk();

        // Typing indicator is cleared automatically on send
        $this->assertFalse(Cache::has("conversation:{$conversation->id}:active_typing"));

        // Verify message record in DB has Baraa as sender
        $lastMessage = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->latest('id')->first();
        $this->assertNotNull($lastMessage);
        $this->assertSame($baraa->id, $lastMessage->user_id);
        $this->assertSame('براء', $lastMessage->user->name);

        // Verify conversation assigned user STILL remains Hasan!
        $conversation->refresh();
        $this->assertSame($hasan->id, $conversation->assigned_user_id);
        $this->assertSame('حسن الحسينات', $conversation->assignedUser->name);

        // Verify lastMessageSender helper
        $senderInfo = $conversation->lastMessageSender();
        $this->assertSame('human', $senderInfo['type']);
        $this->assertSame($baraa->id, $senderInfo['id']);
        $this->assertSame('براء', $senderInfo['name']);

        // 7 & 8. Transfer responsibility from Hasan to Baraa (نقل المسؤولية من حسن إلى براء)
        $conversation = $handoffService->assign($conversation, $baraa, actor: $owner);
        $conversation->refresh();

        $this->assertSame($baraa->id, $conversation->assigned_user_id);
        $this->assertSame('براء', $conversation->assignedUser->name);
        $this->assertSame('براء', $conversation->currentResponder()['name']);

        // Verify previous messages still preserve their historical author
        $this->assertSame($baraa->id, $lastMessage->fresh()->user_id);

        // 9. Open conversation as Manager, Hasan, and Baraa - all see consistent assigned user and sender
        $this->actingAs($owner);
        $managerView = $this->get(route('workspace.conversations.index', ['conversation' => $conversation->id]));
        $managerView->assertOk();
        $managerView->assertSee('المسؤول: براء');

        $this->actingAs($hasan);
        $hasanView = $this->get(route('workspace.conversations.index', ['conversation' => $conversation->id]));
        $hasanView->assertOk();
        $hasanView->assertSee('المسؤول: براء');

        // Mobile API verification
        $this->actingAs($baraa);
        $apiResponse = $this->getJson("/api/mobile/v1/conversations/{$conversation->id}", [
            'X-Workspace-Id' => (string) $workspace->id,
        ]);
        $apiResponse->assertOk();
        $apiResponse->assertJsonPath('data.assigned_user.name', 'براء');
        $apiResponse->assertJsonPath('data.assigned_user_id', $baraa->id);
        $apiResponse->assertJsonPath('data.last_message_sender.name', 'براء');
    }
}
