<?php

namespace App\Http\Resources\Api\Subscription;

use App\Support\Subscription\SubscriptionWorkspacePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SubscriptionCheckoutSession */
class SubscriptionCheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = app(SubscriptionWorkspacePresenter::class);

        return [
            'id' => $this->id,
            'plan' => $this->whenLoaded('plan', fn () => new SubscriptionPlanResource($this->plan)),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'checkout_status' => $this->checkout_status,
            'payment_status' => $this->payment_status,
            'subscription_status' => $this->subscription_status,
            'payment_provider' => $this->payment_provider,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $presenter->isCheckoutExpired($this->resource),
            'is_verified_paid' => $presenter->isVerifiedPaid($this->resource),
            'activated_subscription_id' => $this->activated_subscription_id,
            'reference' => $this->provider_checkout_id,
        ];
    }
}
