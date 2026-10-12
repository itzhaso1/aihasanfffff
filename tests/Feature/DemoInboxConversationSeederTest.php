<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoInboxConversationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoInboxConversationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_renders_demo_whatsapp_thread_for_company_workspace(): void
    {
        [$owner, $workspace] = $this->createCompanyWorkspace('متجر تجريبي');

        $this->seed(DemoInboxConversationSeeder::class);

        $conversation = $this->inWorkspace($workspace, function (): Conversation {
            return Conversation::query()
                ->where('external_id', DemoInboxConversationSeeder::WHATSAPP_EXTERNAL_ID)
                ->firstOrFail();
        });

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', [
                'conversation' => $conversation->id,
            ]))
            ->assertOk()
            ->assertSee('نورة العتيبي')
            ->assertSee('خالد المطيري')
            ->assertSee('سارة الدوسري')
            ->assertSee('السلام عليكم، عندكم عباية سوداء مقاس 52؟')
            ->assertSee('ووش وقت التوصيل تقريباً؟')
            ->assertSee('WhatsApp');
    }

    public function test_demo_seeder_does_not_duplicate_or_leak_into_other_workspaces(): void
    {
        [, $workspace] = $this->createCompanyWorkspace(DemoInboxConversationSeeder::PREFERRED_WORKSPACE_NAME);
        [$otherOwner, $otherWorkspace] = $this->createCompanyWorkspace('شركة أخرى');

        $this->seed(DemoInboxConversationSeeder::class);
        $this->seed(DemoInboxConversationSeeder::class);

        $this->inWorkspace($workspace, function (): void {
            $this->assertSame(3, Conversation::query()->count());
            $this->assertSame(12, Message::query()->count());
        });

        $this->inWorkspace($otherWorkspace, function (): void {
            $this->assertSame(0, Conversation::query()->count());
            $this->assertSame(0, Message::query()->count());
        });

        $this->actingAs($otherOwner)
            ->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.conversations.index'))
            ->assertOk()
            ->assertDontSee('نورة العتيبي');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompanyWorkspace(string $name): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'name' => $name,
            'owner_user_id' => $user->id,
            'type' => 'company',
            'status' => 'active',
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
