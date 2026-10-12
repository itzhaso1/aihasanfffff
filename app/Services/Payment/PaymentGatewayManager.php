<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Providers\LocalPaymentGateway;
use App\Services\Payment\Providers\StripePaymentGateway;

class PaymentGatewayManager
{
    public function resolve(?PaymentGateway $gateway = null): PaymentGatewayInterface
    {
        return $this->resolveByName($gateway?->provider ?? (string) config('payment.default_provider', 'local'));
    }

    public function resolveByName(string $providerName): PaymentGatewayInterface
    {
        return match (strtolower($providerName)) {
            'stripe' => app(StripePaymentGateway::class),
            default => app(LocalPaymentGateway::class),
        };
    }
}
