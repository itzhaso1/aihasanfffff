<?php

namespace App\Services\Subscription;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionCheckoutSession;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
use App\Support\Subscription\SubscriptionBillingGuard;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SubscriptionService
{
    private const RUNNING_GUARD = 'running';

    public function current(Workspace $workspace): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_PAST_DUE,
            ])
            ->latest('id')
            ->first();
    }

    public function latest(Workspace $workspace): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->first();
    }

    /**
     * Trusted internal activation (local/testing provider, seeders, tests).
     * HTTP controllers must not call this to honor a client "I paid" claim.
     */
    public function activatePlan(Workspace $workspace, Plan $plan): Subscription
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan): Subscription {
            return DB::transaction(function () use ($workspace, $plan): Subscription {
                $this->lockWorkspaceForSubscription($workspace);
                $this->cancelRunningSubscriptions($workspace);

                return $this->createRunningSubscription([
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'starts_at' => now(),
                    'current_period_start' => now(),
                    'current_period_end' => $this->nextPeriodEnd($plan->billing_period),
                    'metadata' => [
                        'proration' => 'none',
                        'note' => 'Plan activated without proration.',
                    ],
                ]);
            });
        });
    }

    /**
     * Operator grant from Platform Admin. Complimentary activation — not a tenant payment claim.
     *
     * @param  array{status?:string,current_period_end?:mixed,trial_ends_at?:mixed}  $options
     */
    public function grantByPlatformAdmin(Workspace $workspace, Plan $plan, array $options = []): Subscription
    {
        $status = (string) ($options['status'] ?? Subscription::STATUS_ACTIVE);
        if (! in_array($status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)) {
            $status = Subscription::STATUS_ACTIVE;
        }

        $periodEnd = isset($options['current_period_end']) && $options['current_period_end']
            ? Carbon::parse($options['current_period_end'])
            : now()->addYear();

        $trialEndsAt = null;
        if ($status === Subscription::STATUS_TRIALING) {
            $trialEndsAt = isset($options['trial_ends_at']) && $options['trial_ends_at']
                ? Carbon::parse($options['trial_ends_at'])
                : $periodEnd->copy();
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan, $status, $periodEnd, $trialEndsAt): Subscription {
            return DB::transaction(function () use ($workspace, $plan, $status, $periodEnd, $trialEndsAt): Subscription {
                $this->lockWorkspaceForSubscription($workspace);
                $this->cancelRunningSubscriptions($workspace);

                return $this->createRunningSubscription([
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => $status,
                    'starts_at' => now(),
                    'trial_ends_at' => $trialEndsAt,
                    'current_period_start' => now(),
                    'current_period_end' => $periodEnd,
                    'provider' => 'platform_admin',
                    'metadata' => [
                        'activated_via' => 'platform_admin',
                        'granted_at' => now()->toIso8601String(),
                        'proration' => 'none',
                    ],
                ]);
            });
        });
    }

    /**
     * Switch to a higher plan. Cancels the running subscription and activates the new one.
     * No proration is calculated — documented in subscription metadata.
     */
    public function upgrade(Workspace $workspace, Plan $plan): Subscription
    {
        return $this->switchPlan($workspace, $plan, 'upgrade');
    }

    /**
     * Switch to a lower plan. Cancels the running subscription and activates the new one.
     * No proration / credit is issued — documented in subscription metadata.
     */
    public function downgrade(Workspace $workspace, Plan $plan): Subscription
    {
        return $this->switchPlan($workspace, $plan, 'downgrade');
    }

    public function markPastDue(Subscription $subscription, ?\DateTimeInterface $graceEndsAt = null): Subscription
    {
        $subscription->forceFill([
            'status' => Subscription::STATUS_PAST_DUE,
            'grace_ends_at' => $graceEndsAt ?? now()->addDays(3),
            'failed_payment_count' => (int) $subscription->failed_payment_count + 1,
            'metadata' => array_merge(
                is_array($subscription->metadata) ? $subscription->metadata : [],
                ['marked_past_due_at' => now()->toIso8601String()]
            ),
        ])->save();

        return $subscription->refresh();
    }

    public function pause(Subscription $subscription, ?\DateTimeInterface $graceEndsAt = null): Subscription
    {
        $subscription->forceFill([
            'status' => Subscription::STATUS_PAUSED,
            'paused_at' => now(),
            'grace_ends_at' => $graceEndsAt,
            'metadata' => array_merge(
                is_array($subscription->metadata) ? $subscription->metadata : [],
                ['paused_via' => 'subscription_service']
            ),
        ])->save();

        return $subscription->refresh();
    }

    public function resume(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'status' => Subscription::STATUS_ACTIVE,
            'paused_at' => null,
            'grace_ends_at' => null,
            'metadata' => array_merge(
                is_array($subscription->metadata) ? $subscription->metadata : [],
                ['resumed_at' => now()->toIso8601String()]
            ),
        ])->save();

        return $subscription->refresh();
    }

    /**
     * Reactivate a cancelled / expired / paused subscription onto its current plan period.
     */
    public function reactivate(Subscription $subscription): Subscription
    {
        $plan = $subscription->plan;
        $periodEnd = $plan
            ? $this->nextPeriodEnd($plan->billing_period)
            : now()->addMonth();

        return DB::transaction(function () use ($subscription, $periodEnd): Subscription {
            $workspace = Workspace::query()->findOrFail($subscription->workspace_id);
            $this->lockWorkspaceForSubscription($workspace);
            $this->cancelRunningSubscriptions($workspace, exceptSubscriptionId: (int) $subscription->id);

            $subscription->forceFill([
                'status' => Subscription::STATUS_ACTIVE,
                'running_guard' => self::RUNNING_GUARD,
                'starts_at' => $subscription->starts_at ?? now(),
                'cancelled_at' => null,
                'paused_at' => null,
                'ends_at' => null,
                'grace_ends_at' => null,
                'current_period_start' => now(),
                'current_period_end' => $periodEnd,
                'metadata' => array_merge(
                    is_array($subscription->metadata) ? $subscription->metadata : [],
                    [
                        'reactivated_at' => now()->toIso8601String(),
                        'proration' => 'none',
                    ]
                ),
            ])->save();

            return $subscription->refresh();
        });
    }

    public function createCheckoutSession(Workspace $workspace, Plan $plan): SubscriptionCheckoutSession
    {
        $ttlHours = max(1, (int) config('subscription.checkout_ttl_hours', 12));
        $provider = $this->resolveServerCheckoutProvider();
        $providerCheckoutId = 'subchk_'.Str::lower(Str::random(24));

        $merchantReference = 'subref_'.Str::lower((string) Str::ulid());

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan, $ttlHours, $provider, $providerCheckoutId, $merchantReference): SubscriptionCheckoutSession {
            $session = SubscriptionCheckoutSession::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'checkout_status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'subscription_status' => 'pending_activation',
                'payment_provider' => $provider,
                'provider_checkout_id' => $providerCheckoutId,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'expires_at' => now()->addHours($ttlHours),
                'metadata' => [
                    'integration_mode' => 'awaiting_provider_verification',
                    'merchant_reference' => $merchantReference,
                    'expected_amount' => (string) $plan->price,
                    'expected_currency' => strtoupper((string) $plan->currency),
                    'note' => 'Activation requires trusted provider verification or webhook. Frontend confirmation is not accepted.',
                    'proration' => 'none',
                ],
            ]);

            if ($provider === 'hyperpay') {
                $remote = app(HyperPaySubscriptionBillingProvider::class)->prepareRemoteCheckout($session);
                $metadata = is_array($session->metadata) ? $session->metadata : [];
                $metadata['merchant_reference'] = $remote->merchantReference ?: $merchantReference;
                $metadata['hyperpay_remote_status'] = $remote->status;
                $metadata['hyperpay_remote_reason'] = $remote->reason;
                $session->forceFill(['metadata' => $metadata])->save();
            }

            SubscriptionBillingAttempt::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'checkout_session_id' => $session->id,
                'purpose' => SubscriptionBillingAttempt::PURPOSE_CHECKOUT,
                'status' => SubscriptionBillingAttempt::STATUS_PENDING,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'payment_provider' => $provider,
                'provider_checkout_id' => $providerCheckoutId,
                'expires_at' => $session->expires_at,
            ]);

            Log::info('subscription.checkout.created', [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'checkout_session_id' => $session->id,
                'amount' => (string) $plan->price,
                'currency' => $plan->currency,
                'payment_provider' => $provider,
            ]);

            return $session;
        });
    }

    /**
     * Activate only after a trusted backend verification source.
     * Frontend confirmation / client payment_status is never a valid source.
     */
    public function completeCheckoutAndActivate(
        Workspace $workspace,
        SubscriptionCheckoutSession $checkoutSession,
        string $verificationSource,
        ?string $paymentReference = null
    ): Subscription {
        if (! SubscriptionBillingGuard::isTrustedVerificationSource($verificationSource)) {
            Log::warning('subscription.activation.untrusted_source', [
                'workspace_id' => $workspace->id,
                'checkout_session_id' => $checkoutSession->id,
                'source' => $verificationSource,
            ]);

            throw UntrustedSubscriptionActivationException::untrustedSource($verificationSource);
        }

        return DB::transaction(function () use ($workspace, $checkoutSession, $verificationSource, $paymentReference): Subscription {
            /** @var SubscriptionCheckoutSession|null $fresh */
            $fresh = SubscriptionCheckoutSession::withoutGlobalScopes()
                ->where('id', $checkoutSession->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                throw new \RuntimeException('Checkout session was not found.');
            }

            if ((int) $fresh->workspace_id !== (int) $workspace->id) {
                throw new \RuntimeException('Checkout session does not belong to this workspace.');
            }

            if (
                $fresh->payment_status === 'paid'
                && $fresh->subscription_status === 'activated'
                && $fresh->activated_subscription_id
            ) {
                return Subscription::withoutGlobalScopes()->findOrFail($fresh->activated_subscription_id);
            }

            if ($fresh->expires_at && $fresh->expires_at->isPast()) {
                $fresh->update(['checkout_status' => 'expired']);
                throw new \RuntimeException('Checkout session has expired.');
            }

            if ($fresh->checkout_status === 'cancelled' || $fresh->checkout_status === 'expired') {
                throw new \RuntimeException('Checkout session is not payable anymore.');
            }

            $this->lockWorkspaceForSubscription($workspace);
            $this->cancelRunningSubscriptions($workspace);

            $subscription = app(WorkspaceContext::class)->runFor($workspace, fn () => $this->createRunningSubscription([
                'workspace_id' => $workspace->id,
                'plan_id' => $fresh->plan_id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => $this->nextPeriodEnd($fresh->plan->billing_period),
                'provider' => $fresh->payment_provider,
                'provider_subscription_id' => 'sub_'.Str::lower(Str::random(24)),
                'metadata' => [
                    'checkout_session_id' => $fresh->id,
                    'payment_reference' => $paymentReference,
                    'activated_via' => $verificationSource,
                    'proration' => 'none',
                ],
            ]));

            $storedReference = $paymentReference ?: ($verificationSource === 'local_billing_provider'
                ? 'pay_'.Str::lower(Str::random(12))
                : $paymentReference);

            $fresh->update([
                'checkout_status' => 'completed',
                'payment_status' => 'paid',
                'subscription_status' => 'activated',
                'paid_at' => now(),
                'payment_reference' => $storedReference,
                'activated_subscription_id' => $subscription->id,
            ]);

            SubscriptionBillingAttempt::withoutGlobalScopes()
                ->where('checkout_session_id', $fresh->id)
                ->where('status', SubscriptionBillingAttempt::STATUS_PENDING)
                ->update([
                    'status' => SubscriptionBillingAttempt::STATUS_PAID,
                    'subscription_id' => $subscription->id,
                    'verified_at' => now(),
                    'payment_reference' => $storedReference,
                ]);

            Log::info('subscription.activated', [
                'workspace_id' => $workspace->id,
                'subscription_id' => $subscription->id,
                'checkout_session_id' => $fresh->id,
                'plan_id' => $fresh->plan_id,
                'source' => $verificationSource,
            ]);

            return $subscription;
        });
    }

    public function availablePlans(?string $workspaceType = null): Collection
    {
        $officialCodes = ['starter', 'pro', 'business', 'enterprise'];

        $query = Plan::query()->where('is_active', true);

        if ($this->plansHaveColumn('is_public')) {
            $query->where('is_public', true);
        }

        if ($this->plansHaveColumn('is_official')) {
            $query->where(function ($q) use ($officialCodes): void {
                $q->where('is_official', true)
                    ->orWhereIn('code', $officialCodes);
            });
        } else {
            $query->whereIn('code', $officialCodes);
        }

        // Official catalog is shared across all workspace types — ignore workspace_type filter.
        if ($this->plansHaveColumn('sort_order')) {
            $query->orderBy('sort_order');
        }

        return $query->orderBy('price')->get();
    }

    /**
     * Public/official catalog only. Inactive and internal plans are not activatable.
     */
    public function resolveActivatablePlan(int $planId): Plan
    {
        $officialCodes = ['starter', 'pro', 'business', 'enterprise'];

        $query = Plan::query()
            ->whereKey($planId)
            ->where('is_active', true);

        if ($this->plansHaveColumn('is_public')) {
            $query->where('is_public', true);
        }

        if ($this->plansHaveColumn('is_official')) {
            $query->where(function ($q) use ($officialCodes): void {
                $q->where('is_official', true)
                    ->orWhereIn('code', $officialCodes);
            });
        } else {
            $query->whereIn('code', $officialCodes);
        }

        return $query->firstOrFail();
    }

    /**
     * Cancel running + activate new plan. No fake proration.
     */
    private function switchPlan(Workspace $workspace, Plan $plan, string $direction): Subscription
    {
        if (app()->bound(SubscriptionBillingProviderInterface::class)) {
            $provider = app(SubscriptionBillingProviderInterface::class);
            $subscription = $provider->activatePlan($workspace, $plan, [
                'direction' => $direction,
                'proration' => 'none',
            ]);

            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            $metadata['switch_direction'] = $direction;
            $metadata['proration'] = 'none';
            $metadata['proration_note'] = 'No proration: previous subscription cancelled and new plan activated immediately.';
            $subscription->forceFill(['metadata' => $metadata])->save();

            return $subscription->refresh();
        }

        if (! SubscriptionBillingGuard::localProviderAllowed()) {
            throw UntrustedSubscriptionActivationException::providerUnconfigured();
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan, $direction): Subscription {
            return DB::transaction(function () use ($workspace, $plan, $direction): Subscription {
                $this->lockWorkspaceForSubscription($workspace);
                $this->cancelRunningSubscriptions($workspace);

                return $this->createRunningSubscription([
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'starts_at' => now(),
                    'current_period_start' => now(),
                    'current_period_end' => $this->nextPeriodEnd($plan->billing_period),
                    'metadata' => [
                        'switch_direction' => $direction,
                        'proration' => 'none',
                        'proration_note' => 'No proration: previous subscription cancelled and new plan activated immediately.',
                    ],
                ]);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRunningSubscription(array $attributes): Subscription
    {
        $subscription = Subscription::withoutGlobalScopes()->create([
            ...$attributes,
            'running_guard' => self::RUNNING_GUARD,
        ]);

        app(FeatureAccessService::class)->forgetWorkspaceFeatureCache((int) $subscription->workspace_id);

        return $subscription;
    }

    private function lockWorkspaceForSubscription(Workspace $workspace): void
    {
        Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();
    }

    private function cancelRunningSubscriptions(Workspace $workspace, ?int $exceptSubscriptionId = null): void
    {
        $query = Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PAST_DUE,
                Subscription::STATUS_PAUSED,
            ]);

        if ($exceptSubscriptionId !== null) {
            $query->where('id', '!=', $exceptSubscriptionId);
        }

        $query->lockForUpdate()->get();

        $updateQuery = Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PAST_DUE,
                Subscription::STATUS_PAUSED,
            ]);

        if ($exceptSubscriptionId !== null) {
            $updateQuery->where('id', '!=', $exceptSubscriptionId);
        }

        $updateQuery->update([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'running_guard' => null,
        ]);

        app(FeatureAccessService::class)->forgetWorkspaceFeatureCache((int) $workspace->id);
    }

    private function resolveServerCheckoutProvider(): string
    {
        $configured = strtolower(trim((string) config('subscription.checkout_provider', 'hyperpay')));

        if ($configured === 'local') {
            if (! SubscriptionBillingGuard::localProviderAllowed()) {
                Log::error('subscription.billing.local_checkout_blocked', [
                    'environment' => app()->environment(),
                ]);

                throw UntrustedSubscriptionActivationException::localProviderBlocked();
            }

            return 'local';
        }

        return $configured !== '' ? $configured : 'hyperpay';
    }

    private function nextPeriodEnd(string $billingPeriod): Carbon
    {
        return match ($billingPeriod) {
            'yearly' => now()->addYear(),
            'lifetime' => now()->addYears(100),
            default => now()->addMonth(),
        };
    }

    private function plansHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('plans', $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
