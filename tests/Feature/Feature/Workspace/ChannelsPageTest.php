<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\EmailAccount;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_channels_page_lists_supported_channels_and_live_statuses(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_001',
            'app_id' => 'app_001',
            'display_name' => 'Primary WABA',
            'status' => 'connected',
            'metadata' => [],
        ]);

        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'phone_001',
            'display_phone_number' => '+966500000001',
            'verified_name' => 'Primary Number',
            'status' => 'connected',
        ]);

        EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Mail',
            'email' => 'support@example.test',
            'password' => 'secret',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.channels.index'))
            ->assertOk()
            ->assertSee('Channels')
            ->assertSee('WhatsApp')
            ->assertSee('Facebook Messenger')
            ->assertSee('Instagram')
            ->assertSee('Email')
            ->assertSee('Reconnect WhatsApp')
            ->assertSee(route('workspace.channels.whatsapp.connect'), false);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
