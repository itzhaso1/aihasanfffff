<?php

namespace App\Jobs;

use App\Models\WhatsAppOutboundMessage;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $phoneNumberId,
        public readonly string $to,
        public readonly string $message,
        public readonly ?int $outboundMessageId = null,
        public readonly ?int $workspaceId = null,
    ) {}

    public function handle(): void
    {
        $outbound = $this->outboundMessage();
        if ($outbound && ! $outbound->workspace_id) {
            throw \App\Exceptions\MissingWorkspaceContextException::forWrite(
                WhatsAppOutboundMessage::class,
                'send'
            );
        }

        if ($outbound?->workspace_id) {
            app(WorkspaceContext::class)->runForId((int) $outbound->workspace_id, function () use ($outbound): void {
                $this->send($outbound);
            });

            return;
        }

        return;
    }

    private function send(?WhatsAppOutboundMessage $outbound): void
    {

        if ($outbound) {
            $outbound->forceFill([
                'status' => WhatsAppOutboundMessage::STATUS_SENDING,
                'attempts' => (int) $outbound->attempts + 1,
            ])->save();
        }

        $token = config('services.whatsapp.token');
        if (! $token) {
            Log::warning('WhatsApp token missing; skipping outbound send.', [
                'phone_number_id' => $this->phoneNumberId,
                'to' => $this->to,
                'outbound_message_id' => $this->outboundMessageId,
            ]);

            $this->markFailed($outbound, 'WhatsApp permanent token is not configured.');

            return;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            config('whatsapp.api_version', 'v20.0'),
            $this->phoneNumberId,
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->to,
            'type' => 'text',
            'text' => [
                'body' => $this->message,
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            $body = $response->json();
            if (! is_array($body)) {
                $body = ['raw' => $response->body()];
            }

            if ($response->successful()) {
                $providerMessageId = data_get($body, 'messages.0.id');

                if ($outbound) {
                    $outbound->forceFill([
                        'status' => WhatsAppOutboundMessage::STATUS_SENT,
                        'provider_message_id' => is_string($providerMessageId) ? $providerMessageId : null,
                        'provider_response' => $body,
                        'last_error' => null,
                        'sent_at' => now(),
                        'failed_at' => null,
                    ])->save();
                }

                return;
            }

            $error = (string) (data_get($body, 'error.message') ?: $response->body() ?: 'WhatsApp Graph API error');
            $this->markFailed($outbound, $error, $body);

            if ($response->serverError() || $response->status() === 429) {
                throw new \RuntimeException($error);
            }
        } catch (Throwable $exception) {
            $this->markFailed($outbound, $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $outbound = $this->outboundMessage();
        if (! $outbound) {
            return;
        }

        if ($outbound->workspace_id) {
            app(WorkspaceContext::class)->runForId((int) $outbound->workspace_id, function () use ($outbound, $exception): void {
                $outbound->forceFill([
                    'status' => WhatsAppOutboundMessage::STATUS_FAILED,
                    'last_error' => $exception?->getMessage() ?: $outbound->last_error,
                    'failed_at' => $outbound->failed_at ?? now(),
                ])->save();
            });

            return;
        }

        $outbound->forceFill([
            'status' => WhatsAppOutboundMessage::STATUS_FAILED,
            'last_error' => $exception?->getMessage() ?: $outbound->last_error,
            'failed_at' => $outbound->failed_at ?? now(),
        ])->save();
    }

    private function outboundMessage(): ?WhatsAppOutboundMessage
    {
        if (! $this->outboundMessageId) {
            return null;
        }

        $query = WhatsAppOutboundMessage::withoutGlobalScopes()->whereKey($this->outboundMessageId);
        if ($this->workspaceId !== null) {
            $query->where('workspace_id', $this->workspaceId);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>|null  $providerResponse
     */
    private function markFailed(?WhatsAppOutboundMessage $outbound, string $error, ?array $providerResponse = null): void
    {
        if (! $outbound) {
            return;
        }

        $outbound->forceFill([
            'status' => WhatsAppOutboundMessage::STATUS_FAILED,
            'last_error' => $error,
            'provider_response' => $providerResponse ?? $outbound->provider_response,
            'failed_at' => now(),
        ])->save();
    }
}
