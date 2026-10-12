<?php

namespace App\Services\Subscription\Contracts;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\Subscription\SubscriptionPaymentVerification;

/**
 * Abstraction for commercial subscription activation / billing.
 *
 * integration_mode:
 * - "local" — no-charge activation via SubscriptionService. Bound only in local/testing.
 * - "unconfigured" — production/staging stand-in. Refuses activation.
 * - "hyperpay_pending" — contract ready; no invented HyperPay API/signature.
 */
interface SubscriptionBillingProviderInterface
{
    /**
     * Activate (or switch to) a plan for the workspace.
     *
     * @param  array<string, mixed>  $options
     */
    public function activatePlan(Workspace $workspace, Plan $plan, array $options = []): Subscription;

    /**
     * Human-readable mode for checkout / admin UI (e.g. "local").
     */
    public function integrationMode(): string;

    /**
     * Verify a server-to-server subscription webhook. Never trust client fields.
     *
     * @param  array<string, string>  $headers
     */
    public function verifySubscriptionWebhook(array $headers, array $payload, ?string $rawBody = null): SubscriptionPaymentVerification;
}
