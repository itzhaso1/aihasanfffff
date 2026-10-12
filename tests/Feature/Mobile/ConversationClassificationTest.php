<?php

namespace Tests\Feature\Mobile;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationClassificationService;
use App\Services\Conversation\ConversationService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConversationClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_classification_is_workspace_scoped_and_blocks_later_signals(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember();
        [, $other] = $this->makeMember('other-class@example.com');
        $conversation = $this->makeConversation($workspace);
        $foreign = $this->makeConversation($other);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->patchJson("/api/mobile/v1/conversations/{$conversation->id}/classification", [
                'customer_priority' => 'important',
                'payment_status' => 'paid',
                'review_status' => 'needs_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.customer_priority', 'important')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.review_status', 'needs_review')
            ->assertJsonPath('data.classification_source', 'manual');

        app(WorkspaceContext::class)->runFor($workspace, function () use ($conversation, $workspace): void {
            app(ConversationService::class)->addMessage($conversation->fresh(), [
                'workspace_id' => $workspace->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'مرحبا فقط',
                'customer_id' => $conversation->customer_id,
            ]);
        });

        $this->assertSame('manual', $conversation->fresh()->classification_source);
        $this->assertSame('important', $conversation->fresh()->customer_priority);

        $blocked = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->patchJson("/api/mobile/v1/conversations/{$foreign->id}/classification", [
                'customer_priority' => 'important',
                'payment_status' => 'paid',
                'review_status' => 'needs_review',
            ]);
        $this->assertContains($blocked->status(), [403, 404]);
    }

    public function test_list_filters_combine_priority_payment_and_review(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('filter-class@example.com');
        $match = $this->makeConversation($workspace);
        $other = $this->makeConversation($workspace);
        $match->forceFill([
            'customer_priority' => 'important',
            'payment_status' => 'paid',
            'review_status' => 'needs_review',
        ])->save();
        $other->forceFill([
            'customer_priority' => 'normal',
            'payment_status' => 'unpaid',
            'review_status' => 'no_review_needed',
        ])->save();

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/conversations?customer_priority=important&payment_status=paid&review_status=needs_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_inbound_escalation_sets_review_without_touching_a_manual_decision(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('signal-class@example.com');
        $conversation = $this->makeConversation($workspace);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($conversation, $workspace): void {
            app(ConversationService::class)->addMessage($conversation, [
                'workspace_id' => $workspace->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'عندي شكوى وما انحلت',
                'customer_id' => $conversation->customer_id,
            ]);
        });

        $fresh = $conversation->fresh();
        $this->assertSame('important', $fresh->customer_priority);
        $this->assertSame('needs_review', $fresh->review_status);
        $this->assertSame('system', $fresh->classification_source);
    }

    public function test_invalid_ai_enum_is_rejected_and_manual_is_not_overwritten(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace] = $this->makeMember('ai-class@example.com');
        $conversation = $this->makeConversation($workspace);
        $service = app(ConversationClassificationService::class);

        try {
            $service->applyAiSuggestion($conversation, [
                'customer_priority' => 'vip',
                'payment_status' => 'paid',
                'review_status' => 'needs_review',
            ]);
            $this->fail('invalid enum was accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_priority', $exception->errors());
        }

        $service->applyManual($conversation, $user, [
            'customer_priority' => 'normal',
            'payment_status' => 'unpaid',
            'review_status' => 'no_review_needed',
        ]);

        try {
            $service->applyAiSuggestion($conversation->fresh(), [
                'customer_priority' => 'important',
                'payment_status' => 'paid',
                'review_status' => 'needs_review',
            ]);
            $this->fail('manual classification was overwritten');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('classification', $exception->errors());
        }

        $this->assertSame('manual', $conversation->fresh()->classification_source);
        $this->assertSame('normal', $conversation->fresh()->customer_priority);
    }

    private function makeConversation(Workspace $workspace): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل',
            'phone' => '+9665'.random_int(10000000, 99999999),
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email = 'class@example.com'): array
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
}
