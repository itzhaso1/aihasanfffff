<?php

namespace App\Jobs;

use App\Services\Subscription\SubscriptionWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSubscriptionWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $headers,
        public readonly array $payload,
        public readonly ?string $rawBody = null,
    ) {}

    public function handle(SubscriptionWebhookProcessor $processor): void
    {
        $processor->process(
            providerName: $this->provider,
            headers: $this->headers,
            payload: $this->payload,
            rawBody: $this->rawBody,
        );
    }
}
