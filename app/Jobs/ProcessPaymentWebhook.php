<?php

namespace App\Jobs;

use App\Services\Payment\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentWebhook implements ShouldQueue
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

    /**
     * Execute the job.
     */
    public function handle(PaymentService $paymentService): void
    {
        $paymentService->processWebhook(
            providerName: $this->provider,
            headers: $this->headers,
            payload: $this->payload,
            rawBody: $this->rawBody,
        );
    }
}
