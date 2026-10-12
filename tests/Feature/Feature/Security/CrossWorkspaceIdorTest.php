<?php

namespace Tests\Feature\Feature\Security;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailCampaign;
use App\Models\EmailContact;
use App\Models\EmailContactGroup;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrossWorkspaceIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $sharedUser;

    private Workspace $workspaceA;

    private Workspace $workspaceB;

    private string $tokenA;

    private string $tokenB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);

        $this->sharedUser = User::factory()->create(['email' => 'idor-shared@example.com']);
        $this->workspaceA = $this->attachWorkspace($this->sharedUser, 'IDOR A');
        $this->workspaceB = $this->attachWorkspace($this->sharedUser, 'IDOR B');
        foreach ([$this->workspaceA, $this->workspaceB] as $workspace) {
            foreach (['email', 'whatsapp', 'ai', 'customers', 'products'] as $feature) {
                WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                    ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                    ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
                );
            }
            $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->first();
            if ($plan) {
                Subscription::withoutGlobalScopes()->firstOrCreate(
                    ['workspace_id' => $workspace->id, 'plan_id' => $plan->id, 'status' => 'active'],
                    [
                        'starts_at' => now(),
                        'current_period_start' => now(),
                        'current_period_end' => now()->addMonth(),
                    ]
                );
            }
        }

        $this->tokenA = $this->loginToken($this->sharedUser, $this->workspaceA);
        $this->tokenB = $this->loginToken($this->sharedUser, $this->workspaceB);
    }

    public function test_mobile_email_message_cross_workspace_returns_not_found(): void
    {
        $accountA = $this->emailAccount($this->workspaceA);
        $accountB = $this->emailAccount($this->workspaceB);
        $messageB = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'email_account_id' => $accountB->id,
            'folder' => 'inbox',
            'type' => 'inbound',
            'subject' => 'Secret B',
            'sender' => 'from-b@example.com',
            'recipient' => 'to-b@example.com',
            'body' => 'B body',
        ]);

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->getJson('/api/mobile/v1/emails/'.$messageB->id)
            ->assertNotFound();

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->postJson('/api/mobile/v1/emails/'.$messageB->id.'/read')
            ->assertNotFound();

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->postJson('/api/mobile/v1/emails/'.$messageB->id.'/star')
            ->assertNotFound();

        $this->assertNull(EmailMessage::withoutGlobalScopes()
            ->whereKey($messageB->id)
            ->where('workspace_id', $this->workspaceA->id)
            ->value('id'));
        $this->assertSame($accountA->id, $accountA->fresh()->id);
    }

    public function test_mobile_contact_and_group_cross_workspace_returns_not_found(): void
    {
        $contactB = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Contact B',
            'email' => 'contact-b@example.com',
            'normalized_email' => 'contact-b@example.com',
        ]);
        $groupB = EmailContactGroup::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Group B',
        ]);

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->getJson('/api/mobile/v1/contacts/'.$contactB->id)
            ->assertNotFound();
        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->patchJson('/api/mobile/v1/contacts/'.$contactB->id, ['name' => 'Hacked'])
            ->assertNotFound();
        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->patchJson('/api/mobile/v1/contact-groups/'.$groupB->id, ['name' => 'Hacked'])
            ->assertNotFound();
        $this->assertSame('Contact B', $contactB->fresh()->name);
    }

    public function test_mobile_customer_and_catalog_cross_workspace_returns_not_found(): void
    {
        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Customer B',
            'phone' => '0500000002',
        ]);
        $categoryB = ChatCatalogCategory::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Cat B',
            'slug' => 'cat-b-'.Str::lower(Str::random(4)),
            'sort_order' => 1,
        ]);
        $itemB = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'category_id' => $categoryB->id,
            'name' => 'Item B',
            'slug' => 'item-b-'.Str::lower(Str::random(4)),
            'status' => 'active',
        ]);

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->getJson('/api/mobile/v1/customers/'.$customerB->id)
            ->assertNotFound();
        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->getJson('/api/mobile/v1/catalog/'.$itemB->id)
            ->assertNotFound();
        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->patchJson('/api/mobile/v1/catalog/categories/'.$categoryB->id, ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_mobile_ai_endpoints_reject_foreign_conversation_id(): void
    {
        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'AI Customer B',
            'phone' => '0500000099',
        ]);
        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'customer_id' => $customerB->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]);
        Message::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'conversation_id' => $conversationB->id,
            'customer_id' => $customerB->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hello B',
        ]);

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->postJson('/api/mobile/v1/ai/suggest-reply', [
                'conversation_id' => $conversationB->id,
            ])
            ->assertNotFound();

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->postJson('/api/mobile/v1/ai/summarize-conversation', [
                'conversation_id' => $conversationB->id,
            ])
            ->assertNotFound();
    }

    public function test_notification_mark_read_is_workspace_scoped(): void
    {
        $notificationBId = (string) Str::uuid();
        $this->sharedUser->notifications()->create([
            'id' => $notificationBId,
            'type' => 'App\\Notifications\\TestNotification',
            'data' => ['message' => 'Workspace B only'],
            'workspace_id' => $this->workspaceB->id,
        ]);

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->postJson('/api/mobile/v1/notifications/'.$notificationBId.'/read')
            ->assertNotFound();

        $this->assertNull($this->sharedUser->notifications()->whereKey($notificationBId)->value('read_at'));
    }

    public function test_forged_workspace_header_cannot_read_other_workspace_data(): void
    {
        $soloUser = User::factory()->create(['email' => 'idor-solo@example.com']);
        $soloWorkspace = $this->attachWorkspace($soloUser, 'IDOR Solo');
        $soloToken = $this->loginToken($soloUser, $soloWorkspace);
        $contactB = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Header Forge B',
            'email' => 'forge-b@example.com',
            'normalized_email' => 'forge-b@example.com',
        ]);

        $response = $this->withToken($soloToken)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceB->id)
            ->getJson('/api/mobile/v1/contacts/'.$contactB->id);

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_email_campaign_cross_workspace_returns_not_found(): void
    {
        $accountB = $this->emailAccount($this->workspaceB);
        $campaignB = EmailCampaign::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'email_account_id' => $accountB->id,
            'user_id' => $this->sharedUser->id,
            'subject' => 'Subject B',
            'body' => '<p>B</p>',
            'status' => 'draft',
        ]);

        $this->asWorkspace($this->tokenA, $this->workspaceA)
            ->getJson('/api/mobile/v1/email/campaigns/'.$campaignB->id)
            ->assertNotFound();
    }

    private function emailAccount(Workspace $workspace): EmailAccount
    {
        return EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbox '.$workspace->name,
            'email' => 'inbox-'.$workspace->id.'@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'username' => 'user@example.com',
            'password' => 'secret',
            'is_active' => true,
        ]);
    }

    private function attachWorkspace(User $user, string $name): Workspace
    {
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $workspace;
    }

    private function loginToken(User $user, Workspace $workspace): string
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return (string) $login->json('data.token');
    }

    private function asWorkspace(string $token, Workspace $workspace): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
