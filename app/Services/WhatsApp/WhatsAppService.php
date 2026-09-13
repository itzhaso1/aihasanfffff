<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WhatsAppPhoneNumber;
use App\Models\WebhookEvent;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Str;

class WhatsAppService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function processWebhook(array $payload, array $headers): void
    {
        $phoneNumberId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
        $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
        $eventId = $payload['entry'][0]['id'] ?? (string) Str::uuid();

        if (! $phoneNumberId || ! is_array($messageData)) {
            return;
        }

        $phones = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('phone_number_id', $phoneNumberId)
            ->get(['id', 'workspace_id']);

        $workspaceIds = $phones
            ->pluck('workspace_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($workspaceIds->count() !== 1) {
            return;
        }

        $phone = $phones->first();

        $event = app(WorkspaceContext::class)->runForId((int) $phone->workspace_id, function () use ($eventId, $phone, $headers, $payload): WebhookEvent {
            return WebhookEvent::withoutGlobalScopes()->firstOrCreate(
                [
                    'workspace_id' => $phone->workspace_id,
                    'provider' => 'whatsapp',
                    'external_event_id' => $eventId,
                ],
                [
                    'workspace_id' => $phone->workspace_id,
                    'event_type' => 'message',
                    'idempotency_key' => $eventId,
                    'headers' => $headers,
                    'payload' => $payload,
                    'status' => 'pending',
                ]
            );
        });

        if ($event->wasRecentlyCreated === false) {
            return;
        }

        ProcessIncomingWhatsAppMessage::dispatch(
            $phone->workspace_id,
            $messageData,
            $event->id,
            (string) $phoneNumberId,
        );
    }

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function storeIncomingMessage(int $workspaceId, array $messageData, ?string $phoneNumberId = null): Message
    {
        return app(WorkspaceContext::class)->runForId($workspaceId, function () use ($workspaceId, $messageData, $phoneNumberId): Message {
        $customerPhone = $messageData['from'] ?? 'unknown';
        $content = $messageData['text']['body'] ?? null;
        $externalMessageId = $messageData['id'] ?? null;

        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'phone' => $customerPhone,
            ],
            [
                'name' => 'Customer '.$customerPhone,
                'whatsapp' => $customerPhone,
            ]
        );

        $initialMetadata = [
            'channel_source' => 'whatsapp',
            'unread_count' => 0,
        ];
        if (is_string($phoneNumberId) && $phoneNumberId !== '') {
            $initialMetadata['phone_number_id'] = $phoneNumberId;
        }

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'external_id' => $customerPhone,
            ],
            [
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
                'status' => 'open',
                'ai_enabled' => true,
                'last_message_at' => now(),
                'metadata' => $initialMetadata,
            ]
        );

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => $content,
            'external_message_id' => $externalMessageId,
            'metadata' => $messageData,
        ]);

        $conversationMetadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $conversationMetadata['channel_source'] = 'whatsapp';
        $conversationMetadata['unread_count'] = (int) ($conversationMetadata['unread_count'] ?? 0) + 1;
        if (is_string($phoneNumberId) && $phoneNumberId !== '') {
            $conversationMetadata['phone_number_id'] = $phoneNumberId;
        }

        $conversation->update([
            'customer_id' => $conversation->customer_id ?: $customer->id,
            'channel' => 'whatsapp',
            'last_message_at' => $message->created_at,
            'metadata' => $conversationMetadata,
        ]);

        $customer->update([
            'last_conversation_at' => $message->created_at,
        ]);

        return $message;
        });
    }
}
