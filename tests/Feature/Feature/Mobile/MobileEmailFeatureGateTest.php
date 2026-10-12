<?php

namespace Tests\Feature\Feature\Mobile;

use App\Jobs\Email\ProcessEmailCampaignJob;
use App\Models\EmailAccount;
use App\Models\EmailCampaign;
use App\Models\EmailContact;
use App\Models\EmailContactGroup;
use App\Models\EmailMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileEmailFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_email_feature_enabled_authorized_owner_can_access_representative_endpoints(): void
    {
        [$owner, $workspace, $fixtures] = $this->createEmailWorkspace(emailEnabled: true);
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/mobile/v1/emails/accounts')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)
            ->getJson('/api/mobile/v1/contacts')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)
            ->getJson('/api/mobile/v1/contact-groups')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)
            ->getJson('/api/mobile/v1/email/campaigns/'.$fixtures['campaign']->id)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[DataProvider('mobileEmailReadEndpointsProvider')]
    public function test_email_feature_disabled_blocks_read_endpoints_despite_owner_permissions(string $method, string $uri): void
    {
        [$owner, $workspace, $fixtures] = $this->createEmailWorkspace(emailEnabled: false);
        Sanctum::actingAs($owner);

        $this->assertTrue(app(WorkspaceAccess::class)->canUseEmail($owner, $workspace));

        $uri = str_replace(
            ['{campaign}', '{contact}', '{group}', '{emailMessage}'],
            [(string) $fixtures['campaign']->id, (string) $fixtures['contact']->id, (string) $fixtures['group']->id, (string) $fixtures['message']->id],
            $uri,
        );

        $this->withHeaders($this->workspaceHeader($workspace))
            ->json($method, $uri)
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'email');
    }

    #[DataProvider('mobileEmailWriteEndpointsProvider')]
    public function test_email_feature_disabled_blocks_write_endpoints_despite_owner_permissions(string $method, string $uri, array $payload): void
    {
        [$owner, $workspace, $fixtures] = $this->createEmailWorkspace(emailEnabled: false);
        Sanctum::actingAs($owner);

        $this->assertTrue(app(WorkspaceAccess::class)->canUseEmail($owner, $workspace));

        $uri = str_replace(
            ['{campaign}', '{contact}', '{group}', '{emailMessage}'],
            [(string) $fixtures['campaign']->id, (string) $fixtures['contact']->id, (string) $fixtures['group']->id, (string) $fixtures['message']->id],
            $uri,
        );

        $payload = $this->replacePayloadPlaceholders($payload, $fixtures);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->json($method, $uri, $payload)
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'email');
    }

    #[DataProvider('mobileEmailReadEndpointsProvider')]
    public function test_email_feature_enabled_member_without_permission_gets_403(string $method, string $uri): void
    {
        [$owner, $workspace, $fixtures] = $this->createEmailWorkspace(emailEnabled: true);
        $member = $this->attachMember($workspace, 'member');
        Sanctum::actingAs($member);

        $this->assertFalse(app(WorkspaceAccess::class)->canUseEmail($member, $workspace));
        $this->assertTrue(app(WorkspaceAccess::class)->canUseEmail($owner, $workspace));

        $uri = str_replace(
            ['{campaign}', '{contact}', '{group}', '{emailMessage}'],
            [(string) $fixtures['campaign']->id, (string) $fixtures['contact']->id, (string) $fixtures['group']->id, (string) $fixtures['message']->id],
            $uri,
        );

        $this->withHeaders($this->workspaceHeader($workspace))
            ->json($method, $uri)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_non_member_is_rejected_by_workspace_membership_guard(): void
    {
        [, $workspace] = $this->createEmailWorkspace(emailEnabled: true);
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        [, $workspace] = $this->createEmailWorkspace(emailEnabled: true);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/mobile/v1/emails/inbox')
            ->assertUnauthorized();
    }

    public function test_disabled_feature_blocks_contact_create_without_side_effects(): void
    {
        [$owner, $workspace] = $this->createEmailWorkspace(emailEnabled: false);
        Sanctum::actingAs($owner);

        $before = EmailContact::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();

        $this->withHeaders($this->workspaceHeader($workspace))
            ->postJson('/api/mobile/v1/contacts', [
                'name' => 'Blocked Contact',
                'email' => 'blocked@example.com',
            ])
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available');

        $this->assertSame(
            $before,
            EmailContact::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_disabled_feature_blocks_campaign_create_without_dispatching_job(): void
    {
        Bus::fake([ProcessEmailCampaignJob::class]);

        [$owner, $workspace, $fixtures] = $this->createEmailWorkspace(emailEnabled: false);
        Sanctum::actingAs($owner);

        $before = EmailCampaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();

        $this->withHeaders($this->workspaceHeader($workspace))
            ->postJson('/api/mobile/v1/email/campaigns', [
                'email_account_id' => $fixtures['account']->id,
                'subject' => 'Blocked Campaign',
                'body' => 'Should not queue',
                'all_contacts' => true,
                'confirm_all' => true,
            ])
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available');

        $this->assertSame(
            $before,
            EmailCampaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(),
        );
        Bus::assertNotDispatched(ProcessEmailCampaignJob::class);
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function mobileEmailReadEndpointsProvider(): array
    {
        return [
            'emails accounts' => ['GET', '/api/mobile/v1/emails/accounts'],
            'emails inbox' => ['GET', '/api/mobile/v1/emails/inbox'],
            'emails sent' => ['GET', '/api/mobile/v1/emails/sent'],
            'emails drafts' => ['GET', '/api/mobile/v1/emails/drafts'],
            'email message show' => ['GET', '/api/mobile/v1/emails/{emailMessage}'],
            'contacts index' => ['GET', '/api/mobile/v1/contacts'],
            'contacts show' => ['GET', '/api/mobile/v1/contacts/{contact}'],
            'contacts recent recipients' => ['GET', '/api/mobile/v1/contacts/recent-recipients'],
            'contact groups index' => ['GET', '/api/mobile/v1/contact-groups'],
            'campaign show' => ['GET', '/api/mobile/v1/email/campaigns/{campaign}'],
        ];
    }

    /**
     * @return array<string, array{0:string,1:string,2:array<string,mixed>}>
     */
    public static function mobileEmailWriteEndpointsProvider(): array
    {
        return [
            'send email' => ['POST', '/api/mobile/v1/emails', [
                'email_account_id' => '{account_id}',
                'to' => 'to@example.com',
                'subject' => 'Hello',
                'body' => 'Body',
            ]],
            'mark read' => ['POST', '/api/mobile/v1/emails/{emailMessage}/read', []],
            'toggle star' => ['POST', '/api/mobile/v1/emails/{emailMessage}/star', []],
            'create contact' => ['POST', '/api/mobile/v1/contacts', [
                'name' => 'New Contact',
                'email' => 'new@example.com',
            ]],
            'update contact' => ['PATCH', '/api/mobile/v1/contacts/{contact}', [
                'name' => 'Updated Contact',
            ]],
            'favorite contact' => ['POST', '/api/mobile/v1/contacts/{contact}/favorite', []],
            'create contact group' => ['POST', '/api/mobile/v1/contact-groups', [
                'name' => 'VIP',
            ]],
            'update contact group' => ['PATCH', '/api/mobile/v1/contact-groups/{group}', [
                'name' => 'Updated Group',
            ]],
            'sync group members' => ['POST', '/api/mobile/v1/contact-groups/{group}/members', [
                'contact_ids' => ['{contact_id}'],
            ]],
            'create campaign' => ['POST', '/api/mobile/v1/email/campaigns', [
                'email_account_id' => '{account_id}',
                'subject' => 'Promo',
                'body' => 'Body',
                'all_contacts' => true,
                'confirm_all' => true,
            ]],
            'cancel campaign' => ['POST', '/api/mobile/v1/email/campaigns/{campaign}/cancel', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replacePayloadPlaceholders(array $payload, array $fixtures): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $json = str_replace(
            ['{account_id}', '{contact_id}', '{emailMessage}'],
            [(string) $fixtures['account']->id, (string) $fixtures['contact']->id, (string) $fixtures['message']->id],
            $json,
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{0: User, 1: Workspace, 2: array<string, mixed>}
     */
    private function createEmailWorkspace(bool $emailEnabled): array
    {
        $user = User::factory()->create(['password' => 'password']);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Mobile Email Gate Co',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'email'],
            [
                'workspace_id' => $workspace->id,
                'feature_key' => 'email',
                'enabled' => $emailEnabled,
                'source' => 'manual',
            ],
        );

        $plan = Plan::query()->create([
            'code' => 'mobile_email_gate_'.uniqid(),
            'name' => 'Mobile Email Gate Plan',
            'tier' => 'pro',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['email', 'dashboard', 'subscription'],
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
            'email' => 'support@gate.test',
            'password' => 'secret',
            'imap_host' => 'imap.gate.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.gate.test',
            'smtp_port' => 587,
        ]);

        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'folder' => 'inbox',
            'type' => 'inbound',
            'subject' => 'Gate Test',
            'sender' => 'from@gate.test',
            'recipient' => 'support@gate.test',
            'body' => 'Body',
        ]);

        $contact = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Contact',
            'email' => 'contact@gate.test',
            'normalized_email' => 'contact@gate.test',
        ]);

        $group = EmailContactGroup::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Group',
        ]);

        $campaign = EmailCampaign::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'subject' => 'Campaign',
            'body' => 'Body',
            'status' => 'draft',
        ]);

        return [$user, $workspace->fresh(), [
            'account' => $account,
            'message' => $message,
            'contact' => $contact,
            'group' => $group,
            'campaign' => $campaign,
        ]];
    }

    private function attachMember(Workspace $workspace, string $role): User
    {
        $member = User::factory()->create(['password' => 'password']);
        $workspace->users()->attach($member->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $member;
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
