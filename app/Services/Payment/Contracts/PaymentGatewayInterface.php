<?php

namespace App\Services\Payment\Contracts;

interface PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{provider_payment_id:string,payment_link:string,payload:array<string,mixed>}
     */
    public function createPaymentLink(string $reference, float $amount, string $currency, array $metadata = []): array;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array{verified:bool,event_id:?string,status:?string,reference:?string,amount:?float,currency:?string,payload:array<string,mixed>,reason:?string}
     */
    public function verifyWebhook(array $headers, array $payload, ?string $rawBody = null): array;
}
