<?php

namespace Tests\Feature\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Enums\WorkforceParticipantType;
use App\Events\Realtime\MessageCreated;
use App\Http\Resources\Mobile\MessageResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Conversation\ConversationService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EmployeeIdentityDeepAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_baraa_sending_message_does_not_inherit_hasan_worker_engagement_or_identity(): void
    {
        Event::fake([MessageCreated::class]);

        // 1. Create Workspace owner Hasan and Employee Baraa
        $hasan = User::factory()->create([
            'name' => 'حسن صاحب الشركة',
            'email' => 'hasan@example.com',
        ]);

        $baraa = User::factory()->create([
            'name' => 'براء الموظف',
            'email' => 'baraa@example.com',
        ]);

        $workspace = Workspace::factory()->create([
            'name' => 'مساحة العمل الرسمية',
            'owner_user_id' => $hasan->id,
        ]);

        $workspace->users()->attach([
            $hasan->id => ['membership_role' => 'owner', 'status' => 'active'],
            $baraa->id => ['membership_role' => 'agent', 'status' => 'active'],
        ]);

        // Create a worker profile and engagement for Hasan
        $hasanProfile = $this->createWorkerProfile($hasan, 'حسن كابتن العمل');

        $hasanEngagement = WorkerEngagement::query()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $hasanProfile->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'العميل عبدالله',
            'phone' => '+966512345678',
        ]);

        // Conversation assigned to Hasan's worker engagement and Hasan's user
        $conversation = Conversation::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Assigned,
            'assigned_user_id' => $hasan->id,
            'assigned_worker_engagement_id' => $hasanEngagement->id,
            'responder_name' => 'حسن كابتن العمل',
        ]);

        $conversationService = app(ConversationService::class);

        // 2. Baraa sends a message through ConversationService (Web & Service Layer)
        $baraaMessage = $conversationService->addMessage(
            $conversation,
            [
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'مرحباً يا عبدالله، معك براء لمتابعة طلبك.',
            ],
            $baraa,
        );

        // CRITICAL ASSERTION: Baraa's message MUST have Baraa's user_id and MUST NOT inherit Hasan's worker_engagement_id
        $this->assertSame($baraa->id, $baraaMessage->user_id);
        $this->assertNull($baraaMessage->worker_engagement_id, 'Baraa without his own engagement must NOT inherit Hasan assigned_worker_engagement_id');

        // 3. Verify MessageCreated event payload for Baraa
        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($baraa, $baraaMessage) {
            $payload = $event->broadcastWith();

            $this->assertSame($baraaMessage->id, $payload['id']);
            $this->assertSame('براء الموظف', $payload['sender_name']);
            $this->assertSame('براء الموظف', $payload['user_name']);
            $this->assertSame($baraa->id, $payload['user_id']);
            $this->assertNull($payload['worker_engagement_id']);
            $this->assertSame(WorkforceParticipantType::CompanyStaff->value, $payload['participant_type']);

            return true;
        });

        // 4. Verify Mobile API Resource serialization
        $mobilePayload = app(WorkspaceContext::class)->runForId((int) $workspace->id, function () use ($baraaMessage) {
            return (new MessageResource($baraaMessage->fresh(['user', 'customer', 'workerEngagement'])))->toArray(Request::create('/'));
        });

        $this->assertSame('براء الموظف', $mobilePayload['sender_name']);
        $this->assertSame('براء الموظف', $mobilePayload['user_name']);
        $this->assertSame($baraa->id, $mobilePayload['user_id']);
        $this->assertNull($mobilePayload['worker_engagement_id']);

        // 5. Hasan sends a message
        $hasanMessage = $conversationService->addMessage(
            $conversation,
            [
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'مرحباً، معك حسن صاحب المنشأة.',
            ],
            $hasan,
        );

        $this->assertSame($hasan->id, $hasanMessage->user_id);
        // Because Hasan is the actor and owns the assigned engagement, it attributes to his engagement
        $this->assertSame($hasanEngagement->id, $hasanMessage->worker_engagement_id);

        $hasanMobilePayload = app(WorkspaceContext::class)->runForId((int) $workspace->id, function () use ($hasanMessage) {
            return (new MessageResource($hasanMessage->fresh(['user', 'customer', 'workerEngagement.workerProfile'])))->toArray(Request::create('/'));
        });

        $this->assertSame('حسن كابتن العمل', $hasanMobilePayload['sender_name']);
        $this->assertSame($hasanEngagement->id, $hasanMobilePayload['worker_engagement_id']);
    }

    public function test_baraa_with_own_engagement_uses_his_own_engagement_not_hasan(): void
    {
        // Setup Hasan (owner) and Baraa (independent worker with his own profile)
        $hasan = User::factory()->create(['name' => 'حسن']);
        $baraa = User::factory()->create(['name' => 'براء']);

        $workspace = Workspace::factory()->create(['owner_user_id' => $hasan->id]);

        $workspace->users()->attach([
            $hasan->id => ['membership_role' => 'owner', 'status' => 'active'],
            $baraa->id => ['membership_role' => 'agent', 'status' => 'active'],
        ]);

        $hasanProfile = $this->createWorkerProfile($hasan, 'حسن المشرف');
        $hasanEngagement = WorkerEngagement::query()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $hasanProfile->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $baraaProfile = $this->createWorkerProfile($baraa, 'براء الدعم الفني');
        $baraaEngagement = WorkerEngagement::query()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $baraaProfile->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سارة',
            'phone' => '+966599999999',
        ]);

        // Conversation assigned to Hasan's engagement
        $conversation = Conversation::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => false,
            'assigned_user_id' => $hasan->id,
            'assigned_worker_engagement_id' => $hasanEngagement->id,
        ]);

        // Baraa replies
        $conversationService = app(ConversationService::class);
        $message = $conversationService->addMessage(
            $conversation,
            [
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'أهلاً بك، أتابع طلبك الآن.',
            ],
            $baraa,
        );

        // Must attribute to Baraa's engagement, NEVER Hasan's engagement!
        $this->assertSame($baraa->id, $message->user_id);
        $this->assertSame($baraaEngagement->id, $message->worker_engagement_id);
        $this->assertNotSame($hasanEngagement->id, $message->worker_engagement_id);

        $mobilePayload = app(WorkspaceContext::class)->runForId((int) $workspace->id, function () use ($message) {
            return (new MessageResource($message->fresh(['user', 'customer', 'workerEngagement.workerProfile'])))->toArray(Request::create('/'));
        });

        $this->assertSame('براء الدعم الفني', $mobilePayload['sender_name']);
        $this->assertSame($baraaEngagement->id, $mobilePayload['worker_engagement_id']);
    }

    private function createWorkerProfile(User $user, string $displayName): WorkerProfile
    {
        return WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'nationality' => 'Saudi',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);
    }
}
