<?php

namespace App\Support\Subscription;

/**
 * Server-side HyperPay checkout preparation result.
 * Does not invent a remote checkout ID or payment widget.
 */
final class HyperPayRemoteCheckoutResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?string $merchantReference,
        public readonly array $meta = [],
    ) {}

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
