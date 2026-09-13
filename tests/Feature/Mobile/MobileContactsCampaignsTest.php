<?php

namespace Tests\Feature\Mobile;

use App\Jobs\Email\ProcessEmailCampaignJob;
use App\Models\EmailAccount;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailContact;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MobileContactsCampaignsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_create_duplicate_returns_422(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('dup-contact@example.com');

        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Existing',
            'email' => 'same@example.com',
            'normalized_email' => 'same@example.com',
        ]);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/contacts', [
                'name' => 'Another',
                'email' => 'same@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_contact_search_finds_by_company_and_name(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('search-contact@example.com');

        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'أحمد العتيبي',
            'email' => 'ahmed@example.com',
            'normalized_email' => 'ahmed@example.com',
            'company' => 'شركة النور',
            'phone' => '0500000001',
        ]);
        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'سارة',
            'email' => 'sara@example.com',
            'normalized_email' => 'sara@example.com',
            'company' => 'أخرى',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/contacts?q='.urlencode('النور'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('أحمد العتيبي', $data[0]['name']);
    }

    public function test_group_create_and_assign_members(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('group-contact@example.com');

        $contact = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عضو',
            'email' => 'member@example.com',
            'normalized_email' => 'member@example.com',
        ]);

        $create = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/contact-groups', [
                'name' => 'عملاء VIP',
                'description' => 'مجموعة تجريبية',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'عملاء VIP');

        $groupId = $create->json('data.id');

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/contact-groups/{$groupId}/members", [
                'contact_ids' => [$contact->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.contacts_count', 1);
    }

    public function test_campaign_entitlement_blocks_when_over_limit(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('campaign-limit@example.com');
        $this->attachEmailPlan($workspace, emailSendsLimit: 0);

        $account = $this->makeEmailAccount($workspace);

        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'مستلم',
            'email' => 'r1@example.com',
            'normalized_email' => 'r1@example.com',
        ]);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/email/campaigns', [
                'email_account_id' => $account->id,
                'subject' => 'عرض',
                'body' => 'محتوى',
                'all_contacts' => true,
                'confirm_all' => true,
            ])
            ->assertStatus(402)
            ->assertJsonPath('success', false);
    }

    public function test_campaign_creates_recipients_and_queues_job(): void
    {
        $this->seed(FoundationSeeder::class);
        Bus::fake([ProcessEmailCampaignJob::class]);

        [$user, $workspace, $token] = $this->authMember('campaign-queue@example.com');
        $this->attachEmailPlan($workspace, emailSendsLimit: 100);

        $account = $this->makeEmailAccount($workspace);

        $c1 = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'أ',
            'email' => 'a@example.com',
            'normalized_email' => 'a@example.com',
        ]);
        $c2 = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'ب',
            'email' => 'b@example.com',
            'normalized_email' => 'b@example.com',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/email/campaigns', [
                'email_account_id' => $account->id,
                'subject' => 'حملة تجريبية',
                'body' => 'نص الحملة',
                'contact_ids' => [$c1->id, $c2->id],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.recipient_count', 2);

        $campaignId = $response->json('data.id');
        $this->assertDatabaseCount('email_campaign_recipients', 2);
        $this->assertSame(
            2,
            EmailCampaignRecipient::query()->where('email_campaign_id', $campaignId)->count(),
        );

        Bus::assertDispatched(ProcessEmailCampaignJob::class, function (ProcessEmailCampaignJob $job) use ($campaignId): bool {
            return $job->campaignId === (int) $campaignId;
        });
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
    {
        [$user, $workspace] = $this->makeMember($email);
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, $login->json('data.token')];
    }

    /**
     * @return array{0:User,1:Workspace}
     */
    private function makeMember(string $email): array
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

        return [$user, $workspace];
    }

    private function attachEmailPlan(Workspace $workspace, int $emailSendsLimit): void
    {
        $plan = Plan::query()->create([
            'code' => 'mobile_email_'.uniqid(),
            'name' => 'Mobile Email Plan',
            'tier' => 'pro',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['email', 'dashboard', 'subscription'],
            'limits' => ['email_sends' => $emailSendsLimit],
        ]);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function makeEmailAccount(Workspace $workspace): EmailAccount
    {
        return EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Support',
            'email' => 'support@example.test',
            'password' => 'secret',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
        ]);
    }
}
