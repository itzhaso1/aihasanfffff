<?php

declare(strict_types=1);

namespace Tests\Feature\Workforce;

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\Models\AiEmployee;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkforceParticipantType;
use App\Http\Resources\Mobile\ConversationResource;
use App\Http\Resources\Mobile\MessageResource;
use App\Http\Resources\Workforce\WorkforceParticipantResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Workforce\WorkforceInboxAttribution;
use App\Services\Workforce\WorkforceParticipant;
use App\Services\Workforce\WorkforceParticipantDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class WorkforceParticipantReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_resource_exposes_only_the_allowlisted_fields(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker('Independent Human');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Identity',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-IDENTITY-123',
            'address' => 'Private Address 1',
        ]);

        $participant = $this->inWorkspace(
            $workspace,
            fn (): ?WorkforceParticipant => $this->directory()->resolve(
                $owner,
                $workspace,
                WorkforceParticipantType::IndependentWorker,
                (int) $engagement->id,
            ),
        );

        $this->assertInstanceOf(WorkforceParticipant::class, $participant);

        $payload = (new WorkforceParticipantResource($participant))->toArray(Request::create('/'));

        $this->assertSame(WorkforceParticipant::ALLOWED_KEYS, array_keys($payload));
        $this->assertSame(WorkforceParticipantType::IndependentWorker->value, $payload['type']);
        $this->assertSame($engagement->id, $payload['source_id']);
        $this->assertSame($engagement->id, $payload['engagement_id']);
        $this->assertSame($profile->id, $payload['worker_profile_id']);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('legal_name', $payload);
        $this->assertArrayNotHasKey('identity_number', $payload);
        $this->assertArrayNotHasKey('address', $payload);
        $this->assertArrayNotHasKey('instructions', $payload);
        $this->assertArrayNotHasKey('tool_permissions', $payload);
        $this->assertSame([], array_diff(array_keys($payload), WorkforceParticipant::ALLOWED_KEYS));
    }

    public function test_independent_worker_resolution_fails_closed_without_the_workspace_engagement(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Company A');
        [$ownerB, $workspaceB] = $this->createCompany('Company B');
        [, $profile] = $this->createWorker('Multi Company Worker');
        $engagementA = $this->createEngagement($workspaceA, $ownerA, $profile);
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profile);

        $resolved = $this->inWorkspace($workspaceA, function () use (
            $ownerA,
            $workspaceA,
            $engagementA,
            $engagementB,
        ): array {
            return [
                $this->directory()->resolveIndependentWorker($ownerA, $workspaceA, null),
                $this->directory()->resolveIndependentWorker($ownerA, $workspaceA, (int) $engagementB->id),
                $this->directory()->resolveIndependentWorker($ownerA, $workspaceA, (int) $engagementA->id),
            ];
        });

        $this->assertNull($resolved[0]);
        $this->assertNull($resolved[1]);
        $this->assertInstanceOf(WorkforceParticipant::class, $resolved[2]);
        $this->assertSame($engagementA->id, $resolved[2]->engagementId);
        $this->assertSame($workspaceA->id, $resolved[2]->workspaceId);
        $this->assertSame(WorkerEngagementStatus::Active->value, $resolved[2]->status);
    }

    public function test_matching_display_names_do_not_change_participant_type(): void
    {
        [$owner, $workspace] = $this->createCompany('Shared Name Co');
        $owner->update(['name' => 'Shared Name']);
        [, $profile] = $this->createWorker('Shared Name');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $aiEmployee = $this->createAiEmployee($workspace, 'Shared Name');
        $staff = WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $resolved = $this->inWorkspace($workspace, function () use (
            $owner,
            $workspace,
            $staff,
            $engagement,
            $aiEmployee,
        ): array {
            return [
                $this->directory()->resolve(
                    $owner,
                    $workspace,
                    WorkforceParticipantType::CompanyStaff,
                    (int) $staff->id,
                ),
                $this->directory()->resolve(
                    $owner,
                    $workspace,
                    WorkforceParticipantType::IndependentWorker,
                    (int) $engagement->id,
                ),
                $this->directory()->resolve(
                    $owner,
                    $workspace,
                    WorkforceParticipantType::AiEmployee,
                    (int) $aiEmployee->id,
                ),
            ];
        });

        $this->assertSame(WorkforceParticipantType::CompanyStaff, $resolved[0]?->type);
        $this->assertSame($staff->id, $resolved[0]?->workspaceUserId);
        $this->assertSame(WorkforceParticipantType::IndependentWorker, $resolved[1]?->type);
        $this->assertSame($engagement->id, $resolved[1]?->engagementId);
        $this->assertSame(WorkforceParticipantType::AiEmployee, $resolved[2]?->type);
        $this->assertSame($aiEmployee->id, $resolved[2]?->aiEmployeeId);
    }

    public function test_inbox_attribution_uses_stored_ids_and_ignores_the_ai_enabled_flag(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $aiEmployee = $this->createAiEmployee($workspace, 'Inbox AI');
        $customer = $this->createCustomer($workspace, 'Attribution Customer');

        $enabledOnly = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:enabled-only',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $withAiEmployee = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:ai-employee',
            'status' => 'open',
            'ai_enabled' => false,
            'ai_employee_id' => $aiEmployee->id,
        ]);
        $staffAssigned = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:staff',
            'status' => 'open',
            'ai_enabled' => true,
            'assigned_user_id' => $owner->id,
        ]);

        $this->assertNull(WorkforceInboxAttribution::typeForConversation($enabledOnly));
        $this->assertSame(
            WorkforceParticipantType::AiEmployee,
            WorkforceInboxAttribution::typeForConversation($withAiEmployee),
        );
        $this->assertSame(
            WorkforceParticipantType::CompanyStaff,
            WorkforceInboxAttribution::typeForConversation($staffAssigned),
        );

        $enabledPayload = (new ConversationResource($enabledOnly))->toArray(Request::create('/'));
        $aiPayload = (new ConversationResource($withAiEmployee))->toArray(Request::create('/'));

        $this->assertTrue($enabledPayload['ai_enabled']);
        $this->assertNull($enabledPayload['assigned_participant_type']);
        $this->assertFalse($aiPayload['ai_enabled']);
        $this->assertSame(WorkforceParticipantType::AiEmployee->value, $aiPayload['assigned_participant_type']);
    }

    public function test_historical_messages_keep_the_stored_engagement_after_a_later_engagement(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('First Company');
        [$ownerB, $workspaceB] = $this->createCompany('Second Company');
        [$worker, $profile] = $this->createWorker('Historical Worker');
        $firstEngagement = $this->createEngagement($workspaceA, $ownerA, $profile);
        $conversation = $this->createConversation($workspaceA, 'Historical Customer');
        $conversation->forceFill([
            'assigned_user_id' => $worker->id,
            'assigned_worker_engagement_id' => $firstEngagement->id,
        ])->save();

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $worker->id,
            'worker_engagement_id' => $firstEngagement->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'Sent under the first engagement',
            'ai_generated' => false,
        ]);

        $laterEngagement = $this->createEngagement($workspaceB, $ownerB, $profile);

        $message->refresh();
        $conversation->refresh();

        $this->assertSame($firstEngagement->id, $message->worker_engagement_id);
        $this->assertSame($firstEngagement->id, $conversation->assigned_worker_engagement_id);
        $this->assertSame($firstEngagement->id, WorkforceInboxAttribution::storedEngagementId($message));
        $this->assertSame($firstEngagement->id, WorkforceInboxAttribution::storedEngagementId($conversation));
        $this->assertSame(
            WorkforceParticipantType::IndependentWorker,
            WorkforceInboxAttribution::typeForMessage($message),
        );
        $this->assertNotSame($laterEngagement->id, $message->worker_engagement_id);

        $payload = (new MessageResource($message))->toArray(Request::create('/'));
        $this->assertSame(WorkforceParticipantType::IndependentWorker->value, $payload['participant_type']);
        $this->assertSame($firstEngagement->id, $payload['worker_engagement_id']);
    }

    public function test_listing_participants_does_not_create_worker_profiles_or_grant_access_from_type(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $before = WorkerProfile::query()->count();

        $this->inWorkspace($workspace, function () use ($owner, $workspace): void {
            $this->directory()->forWorkspace($owner, $workspace);
        });

        $this->assertSame($before, WorkerProfile::query()->count());

        $this->expectException(AuthorizationException::class);
        $this->inWorkspace($workspace, function () use ($owner): void {
            $this->directory()->forWorkspace($owner, Workspace::factory()->create([
                'owner_user_id' => $owner->id,
                'type' => 'company',
                'name' => 'Other Context',
            ]));
        });
    }

    public function test_company_inbox_does_not_render_an_ai_employee_badge_from_ai_enabled_alone(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $conversation = $this->createConversation($workspace, 'Enabled Only Customer');
        $conversation->forceFill(['ai_enabled' => true, 'ai_employee_id' => null])->save();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertDontSee('AI Employee')
            ->assertDontSee('عامل مستقل')
            ->assertSee('Enabled Only Customer');
    }

    private function directory(): WorkforceParticipantDirectory
    {
        return app(WorkforceParticipantDirectory::class);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Test Workspace'): array
    {
        $owner = User::factory()->create(['name' => $name.' Owner']);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent professional.',
            'sales_experience_level' => 'senior',
        ]);

        return [$user, $profile];
    }

    private function createEngagement(
        Workspace $workspace,
        User $owner,
        WorkerProfile $profile,
    ): WorkerEngagement {
        return WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Independent Specialist',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);
    }

    private function createAiEmployee(Workspace $workspace, string $name): AiEmployee
    {
        $draft = app(EmployeeFactory::class)->fromArray([
            'name' => $name,
            'role' => 'customer_support',
            'instructions' => 'PRIVATE-AI-INSTRUCTIONS',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi'],
            'skills' => ['customer_support'],
        ]);

        return app(EmployeeService::class)->create($workspace, $draft);
    }

    private function createCustomer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'email' => 'private-'.$workspace->id.'-'.uniqid().'@example.test',
            'phone' => '050000'.uniqid(),
        ]);
    }

    private function createConversation(Workspace $workspace, string $customerName): Conversation
    {
        $customer = $this->createCustomer($workspace, $customerName);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:'.$workspace->id.':'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
        ]);
    }
}
