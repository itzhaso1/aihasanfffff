<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerMobileEmailStoryCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_worker_with_email_view_can_read_email_but_cannot_send(): void
    {
        [$owner, $workspace, $fixtures] = $this->createEmailWorkspace();
        [$worker, $profile] = $this->createWorker();
        $this->createEngagement($workspace, $owner, $profile, ['email.view']);

        Sanctum::actingAs($worker);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->postJson('/api/mobile/v1/emails', [
                'email_account_id' => $fixtures['account']->id,
                'to' => 'recipient@example.test',
                'subject' => 'Worker send',
                'body' => 'Not allowed',
            ])
            ->assertForbidden();
    }

    public function test_worker_without_email_view_receives_a_permission_message(): void
    {
        [$owner, $workspace] = $this->createEmailWorkspace();
        [$worker, $profile] = $this->createWorker();
        $this->createEngagement($workspace, $owner, $profile, []);

        Sanctum::actingAs($worker);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك صلاحيات الوصول إلى البريد.');
    }

    public function test_email_capability_is_isolated_between_workspaces(): void
    {
        [$ownerA, $workspaceA] = $this->createEmailWorkspace('Email A');
        [, $workspaceB] = $this->createEmailWorkspace('Email B');
        [$worker, $profile] = $this->createWorker();
        $this->createEngagement($workspaceA, $ownerA, $profile, ['email.view']);

        Sanctum::actingAs($worker);

        $this->withHeaders($this->workspaceHeader($workspaceA))
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertOk();
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك صلاحيات الوصول إلى البريد.');
    }

    public function test_worker_with_stories_view_can_read_updates_but_cannot_publish(): void
    {
        [$owner, $workspace] = $this->createWorkspace('Stories Workspace');
        [$worker, $profile] = $this->createWorker();
        $this->createEngagement($workspace, $owner, $profile, ['stories.view']);

        Sanctum::actingAs($worker);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/mobile/v1/stories')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->post('/api/mobile/v1/stories', [
                'type' => 'text',
                'body_text' => 'Not allowed',
            ])
            ->assertForbidden();
    }

    public function test_worker_without_stories_view_receives_a_permission_message(): void
    {
        [$owner, $workspace] = $this->createWorkspace('No Stories Access');
        [$worker, $profile] = $this->createWorker();
        $this->createEngagement($workspace, $owner, $profile, []);

        Sanctum::actingAs($worker);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/mobile/v1/stories')
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك صلاحيات الوصول إلى التحديثات.');
    }

    /**
     * @return array{0: User, 1: Workspace, 2: array{account: EmailAccount, message: EmailMessage}}
     */
    private function createEmailWorkspace(string $name = 'Email Workspace'): array
    {
        [$owner, $workspace] = $this->createWorkspace($name);

        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'email'],
            [
                'workspace_id' => $workspace->id,
                'feature_key' => 'email',
                'enabled' => true,
                'source' => 'manual',
            ],
        );

        $plan = Plan::query()->create([
            'code' => 'worker_email_'.uniqid(),
            'name' => 'Worker Email Plan',
            'tier' => 'pro',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['email'],
            'limits' => ['email_sends' => 1000],
        ]);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'email' => 'support-'.strtolower(str_replace(' ', '-', $name)).'@example.test',
            'password' => 'secret',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
        ]);

        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'folder' => 'inbox',
            'type' => 'inbound',
            'subject' => 'Worker access',
            'sender' => 'sender@example.test',
            'recipient' => $account->email,
            'body' => 'Visible to authorized worker.',
        ]);

        return [$owner, $workspace, ['account' => $account, 'message' => $message]];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspace(string $name): array
    {
        $owner = User::factory()->create();
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
    private function createWorker(string $name = 'Capability Worker'): array
    {
        $worker = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'nationality' => 'Saudi',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);

        return [$worker, $profile];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function createEngagement(
        Workspace $workspace,
        User $owner,
        WorkerProfile $profile,
        array $capabilities,
    ): WorkerEngagement {
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Capability worker',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);

        foreach ($capabilities as $capability) {
            WorkerEngagementPermission::query()->create([
                'worker_engagement_id' => $engagement->id,
                'capability_key' => $capability,
                'allowed' => true,
            ]);
        }

        return $engagement;
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
