<?php

namespace App\Services\Meta;

use App\Services\Meta\Facebook\FacebookWebhookService;
use App\Services\Meta\Instagram\InstagramWebhookService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

class MetaWebhookRouter
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
        private readonly InstagramWebhookService $instagramWebhookService,
        private readonly FacebookWebhookService $facebookWebhookService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function dispatch(array $payload, array $headers): void
    {
        $object = is_string($payload['object'] ?? null) ? (string) $payload['object'] : null;

        if ($object === 'instagram') {
            Log::info('meta.webhook.instagram.received', array_merge(
                [
                    'received_at' => now()->toIso8601String(),
                    'provider' => 'meta',
                    'status' => 'accepted',
                ],
                $this->instagramWebhookMetadata($payload),
            ));

            $this->instagramWebhookService->processWebhook($payload, $headers);

            return;
        }

        if ($object === 'page') {
            Log::info('meta.webhook.facebook.received', [
                'received_at' => now()->toIso8601String(),
                'provider' => 'meta',
                'status' => 'accepted',
                'object' => $object,
            ]);

            $this->facebookWebhookService->processWebhook($payload, $headers);

            return;
        }

        if ($this->isWhatsAppPayload($payload, $object)) {
            $this->whatsAppService->processWebhook($payload, $headers);

            return;
        }

        Log::debug('meta.webhook.unrecognized_payload', [
            'object' => $object,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isWhatsAppPayload(array $payload, ?string $object): bool
    {
        if ($object === 'whatsapp_business_account') {
            return true;
        }

        $entry = $payload['entry'][0] ?? null;
        if (! is_array($entry)) {
            return false;
        }

        $change = $entry['changes'][0] ?? null;
        if (! is_array($change)) {
            return false;
        }

        $value = $change['value'] ?? null;
        if (! is_array($value)) {
            return false;
        }

        if (isset($value['metadata']['phone_number_id']) || isset($value['messages'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string|list<string|null>>
     */
    private function instagramWebhookMetadata(array $payload): array
    {
        $metadata = [
            'object' => 'instagram',
            'entry_count' => is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0,
        ];

        $entry = $payload['entry'][0] ?? null;
        if (! is_array($entry)) {
            return $metadata;
        }

        if (isset($entry['changes']) && is_array($entry['changes'])) {
            $metadata['change_count'] = count($entry['changes']);
            $metadata['change_fields'] = array_values(array_filter(array_map(
                static fn ($change) => is_array($change) && is_string($change['field'] ?? null)
                    ? $change['field']
                    : null,
                $entry['changes'],
            )));
        }

        if (isset($entry['messaging']) && is_array($entry['messaging'])) {
            $metadata['messaging_count'] = count($entry['messaging']);
        }

        return $metadata;
    }
}
