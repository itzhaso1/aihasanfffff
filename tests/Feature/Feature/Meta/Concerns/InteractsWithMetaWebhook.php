<?php

namespace Tests\Feature\Feature\Meta\Concerns;

use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use Illuminate\Testing\TestResponse;

trait InteractsWithMetaWebhook
{
    /**
     * @return array{0: Workspace, 1: WhatsAppPhoneNumber}
     */
    protected function workspaceWithWhatsAppPhone(string $phoneNumberId): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_'.uniqid(),
            'display_name' => 'Test WABA',
            'status' => 'connected',
        ]);
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+966500000000',
            'status' => 'connected',
        ]);

        return [$workspace, $phone];
    }

    /**
     * @return array<string, mixed>
     */
    protected function whatsAppInboundPayload(string $phoneNumberId, string $messageId): array
    {
        return [
            'entry' => [[
                'id' => 'evt-'.$messageId,
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => '966500009007',
                            'text' => ['body' => 'hello-meta-webhook'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postMetaWebhook(array $payload, string $secret, string $path = '/meta-webhook'): TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw);
    }
}
