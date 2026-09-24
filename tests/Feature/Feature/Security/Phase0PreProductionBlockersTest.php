<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\Order\OrderService;
use App\Services\Order\WorkspaceOrderNumberGenerator;
use App\Services\Subscription\SubscriptionService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class Phase0PreProductionBlockersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_whatsapp_meta_ids_are_unique_per_workspace_not_globally(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('WA A');
        [$ownerB, $workspaceB] = $this->makeWorkspace('WA B');
        $this->enableWorkspaceFeature($workspaceA, 'whatsapp');
        $this->enableWorkspaceFeature($workspaceB, 'whatsapp');

        WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'business_account_id' => 'meta-business-1',
            'display_name' => 'A',
            'status' => 'connected',
        ]);
        WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'business_account_id' => 'meta-business-1',
            'display_name' => 'B',
            'status' => 'connected',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'business_account_id' => 'meta-business-1',
            'display_name' => 'A duplicate',
            'status' => 'connected',
        ]);
    }

    public function test_whatsapp_phone_number_id_is_scoped_per_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Phone A');
        [$ownerB, $workspaceB] = $this->makeWorkspace('Phone B');

        $accountA = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'business_account_id' => 'biz-a',
            'display_name' => 'A',
            'status' => 'connected',
        ]);
        $accountB = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'business_account_id' => 'biz-b',
            'display_name' => 'B',
            'status' => 'connected',
        ]);

        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'whats_app_account_id' => $accountA->id,
            'phone_number_id' => 'phone-meta-1',
            'display_phone_number' => '+1001',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'whats_app_account_id' => $accountB->id,
            'phone_number_id' => 'phone-meta-1',
            'display_phone_number' => '+1002',
            'status' => 'connected',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'whats_app_account_id' => $accountA->id,
            'phone_number_id' => 'phone-meta-1',
            'display_phone_number' => '+1003',
            'status' => 'connected',
        ]);
    }

    public function test_subscription_activation_leaves_single_running_subscription(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Sub');
        $planA = $this->makePlan('starter-a');
        $planB = $this->makePlan('pro-b');

        $service = app(SubscriptionService::class);
        $service->activatePlan($workspace, $planA);
        $service->activatePlan($workspace, $planB);

        $running = Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'paused'])
            ->get();

        $this->assertCount(1, $running);
        $this->assertSame((int) $planB->id, (int) $running->first()->plan_id);
        $this->assertSame('running', $running->first()->running_guard);
    }

    public function test_running_guard_blocks_duplicate_active_rows_at_database_level(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Guard');
        $plan = $this->makePlan('guard-plan');

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'running_guard' => 'running',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'running_guard' => 'running',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    public function test_order_numbers_are_unique_per_workspace_under_load(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Orders');
        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'price' => 10,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);

        $numbers = [];
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $product, &$numbers): void {
            $service = app(OrderService::class);
            for ($i = 0; $i < 25; $i++) {
                $order = $service->create([
                    'workspace_id' => $workspace->id,
                    'items' => [[
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 10,
                    ]],
                ]);
                $numbers[] = $order->order_number;
            }
        });

        $this->assertCount(25, array_unique($numbers));
        $this->assertSame(25, Order::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_pos_and_commerce_order_sequences_do_not_collide(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Seq');
        $generator = app(WorkspaceOrderNumberGenerator::class);

        $commerce = $generator->nextCommerceNumber($workspace->id);
        $pos = $generator->nextPosNumber($workspace->id);

        $this->assertStringStartsWith('ORD-', $commerce);
        $this->assertStringStartsWith('POS-', $pos);
        $this->assertNotSame($commerce, $pos);
    }

    public function test_member_cannot_manage_email_accounts(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Email Auth');
        $this->enableWorkspaceFeature($workspace, 'email');
        $member = $this->attachMember($workspace, 'member');

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.accounts.store'), $this->mailboxPayload())
            ->assertForbidden();
    }

    public function test_manager_can_manage_email_accounts_but_member_cannot_use_private_hosts(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Email Manager');
        $this->enableWorkspaceFeature($workspace, 'email');
        $manager = $this->attachMember($workspace, 'manager');

        $this->actingAs($manager)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.accounts.store'), $this->mailboxPayload([
                'imap_host' => '127.0.0.1',
                'smtp_host' => '127.0.0.1',
            ]))
            ->assertSessionHasErrors(['imap_host']);
    }

    public function test_member_cannot_connect_whatsapp_channel(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('WA Auth');
        $this->enableWorkspaceFeature($workspace, 'whatsapp');
        $member = $this->attachMember($workspace, 'member');

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('workspace.channels.whatsapp.connect'), ['code' => 'fake-code'])
            ->assertForbidden();
    }

    public function test_member_cannot_update_ai_settings(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('AI Auth');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $member = $this->attachMember($workspace, 'member');

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.ai-settings.update'), [
                'name' => 'Hacked',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'max_tokens' => 100,
                'temperature' => 0.2,
            ])
            ->assertForbidden();
    }

    public function test_signed_attachment_download_requires_authentication(): void
    {
        Storage::fake('local');
        [$owner, $workspace] = $this->makeWorkspace('Attach');
        $attachment = $this->makeMessageAttachment($workspace);

        $signed = URL::temporarySignedRoute(
            'mobile.v1.attachments.download',
            now()->addMinutes(5),
            [
                'attachment' => $attachment->id,
                'workspace' => $workspace->id,
            ],
        );

        $this->getJson($signed)->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name): array
    {
        $user = User::factory()->create();
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

        return [$user, $workspace];
    }

    private function attachMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function makePlan(string $code): Plan
    {
        return Plan::query()->create([
            'code' => $code.'_'.uniqid(),
            'name' => strtoupper($code),
            'tier' => 'pro',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 99,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['subscription'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mailboxPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Support',
            'email' => 'support@example.com',
            'password' => 'secret-pass',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
        ], $overrides);
    }

    private function makeMessageAttachment(Workspace $workspace): MessageAttachment
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Cust',
            'phone' => '0500000000',
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
            'content' => 'hello',
        ]);
        $path = 'mobile-attachments/'.$workspace->id.'/'.$message->id.'/file.txt';
        Storage::disk('local')->put($path, 'bytes');

        return MessageAttachment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'file.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 5,
            'kind' => 'file',
        ]);
    }
}
