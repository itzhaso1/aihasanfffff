<?php

namespace App\Services\Subscription;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionCheckoutSession;
use App\Models\Workspace;
use App\Support\Subscription\SubscriptionBillingGuard;
use App\Support\Subscription\SubscriptionStateMachine;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionLifecycleService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function expireTrials(): int
    {
        return $this->runScheduled(function (): int {
            $expired = 0;

            Subscription::withoutGlobalScopes()
                ->where('status', Subscription::STATUS_TRIALING)
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', now())
                ->orderBy('id')
                ->chunkById(100, function ($subscriptions) use (&$expired): void {
                    foreach ($subscriptions as $subscription) {
                        if ($this->activateVerifiedPendingCheckout($subscription)) {
                            continue;
                        }

                        $this->expire($subscription, 'trial_ended');
                        $expired++;
                    }
                });

            return $expired;
        });
    }

    public function processRenewals(): int
    {
        return $this->runScheduled(function (): int {
            $this->expireCheckouts();

            $created = 0;
            $leadDays = max(0, (int) config('subscription.renewal_lead_days', 3));
            $horizon = now()->addDays($leadDays);

            Subscription::withoutGlobalScopes()
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                ->whereNotNull('current_period_end')
                ->where('current_period_end', '<=', $horizon)
                ->orderBy('id')
                ->chunkById(100, function ($subscriptions) use (&$created): void {
                    foreach ($subscriptions as $subscription) {
                        if ($this->createRenewalAttemptIfNeeded($subscription)) {
                            $created++;
                        }

                        if (
                            $subscription->status === Subscription::STATUS_ACTIVE
                            && $subscription->current_period_end
                            && $subscription->current_period_end->isPast()
                        ) {
                            $this->markPastDue($subscription, 'period_ended_without_verified_payment');
                        }
                    }
                });

            return $created;
        });
    }

    public function processDunning(): int
    {
        return $this->runScheduled(function (): int {
            $this->expireCheckouts();

            $expired = 0;

            Subscription::withoutGlobalScopes()
                ->where('status', Subscription::STATUS_PAST_DUE)
                ->whereNotNull('grace_ends_at')
                ->where('grace_ends_at', '<', now())
                ->orderBy('id')
                ->chunkById(100, function ($subscriptions) use (&$expired): void {
                    foreach ($subscriptions as $subscription) {
                        $this->expire($subscription, 'grace_ended');
                        $expired++;
                    }
                });

            return $expired;
        });
    }

    public function expireCheckouts(): int
    {
        return $this->runScheduled(function (): int {
            $updated = 0;

            SubscriptionCheckoutSession::withoutGlobalScopes()
                ->whereIn('checkout_status', ['initiated', 'awaiting_payment'])
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->orderBy('id')
                ->chunkById(100, function ($sessions) use (&$updated): void {
                    foreach ($sessions as $session) {
                        $session->update(['checkout_status' => 'expired']);
                        SubscriptionBillingAttempt::withoutGlobalScopes()
                            ->where('checkout_session_id', $session->id)
                            ->where('status', SubscriptionBillingAttempt::STATUS_PENDING)
                            ->update([
                                'status' => SubscriptionBillingAttempt::STATUS_EXPIRED,
                                'failed_at' => now(),
                                'failure_reason' => 'checkout_expired',
                                'active_period_guard' => null,
                            ]);
                        $updated++;
                    }
                });

            return $updated;
        });
    }

    public function markPastDue(Subscription $subscription, string $reason, ?\DateTimeInterface $graceEndsAt = null): Subscription
    {
        SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_PAST_DUE);

        $graceDays = max(1, (int) config('subscription.grace_days', 3));

        $updated = $this->subscriptions->markPastDue(
            $subscription,
            $graceEndsAt ?? now()->addDays($graceDays)
        );

        Log::info('subscription.past_due', [
            'subscription_id' => $updated->id,
            'workspace_id' => $updated->workspace_id,
            'reason' => $reason,
            'grace_ends_at' => optional($updated->grace_ends_at)?->toIso8601String(),
            'failed_payment_count' => $updated->failed_payment_count,
        ]);

        return $updated;
    }

    public function expire(Subscription $subscription, string $reason): Subscription
    {
        SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_EXPIRED);

        $subscription->forceFill([
            'status' => Subscription::STATUS_EXPIRED,
            'ends_at' => now(),
            'metadata' => array_merge(
                is_array($subscription->metadata) ? $subscription->metadata : [],
                [
                    'expired_at' => now()->toIso8601String(),
                    'expired_reason' => $reason,
                ]
            ),
        ])->save();

        $fresh = $subscription->refresh();

        Log::info('subscription.expired', [
            'subscription_id' => $fresh->id,
            'workspace_id' => $fresh->workspace_id,
            'reason' => $reason,
        ]);

        if ($reason === 'trial_ended') {
            Log::info('subscription.trial_expired', [
                'subscription_id' => $fresh->id,
                'workspace_id' => $fresh->workspace_id,
            ]);
        }

        return $fresh;
    }

    public function cancel(Subscription $subscription): Subscription
    {
        SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_CANCELLED);

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'ends_at' => now(),
        ])->save();

        return $subscription->refresh();
    }

    public function pause(Subscription $subscription, ?\DateTimeInterface $graceEndsAt = null): Subscription
    {
        SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_PAUSED);

        return $this->subscriptions->pause($subscription, $graceEndsAt);
    }

    public function resume(Subscription $subscription): Subscription
    {
        SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_ACTIVE);

        return $this->subscriptions->resume($subscription);
    }

    public function activateVerifiedCheckout(
        Workspace $workspace,
        SubscriptionCheckoutSession $checkoutSession,
        string $verificationSource,
        ?string $paymentReference = null
    ): Subscription {
        return $this->subscriptions->completeCheckoutAndActivate(
            $workspace,
            $checkoutSession,
            $verificationSource,
            $paymentReference
        );
    }

    public function renewFromVerifiedAttempt(
        SubscriptionBillingAttempt $attempt,
        string $verificationSource,
        ?string $paymentReference = null
    ): Subscription {
        if (! SubscriptionBillingGuard::isTrustedVerificationSource($verificationSource)) {
            throw UntrustedSubscriptionActivationException::untrustedSource($verificationSource);
        }

        return DB::transaction(function () use ($attempt, $verificationSource, $paymentReference): Subscription {
            /** @var SubscriptionBillingAttempt|null $fresh */
            $fresh = SubscriptionBillingAttempt::withoutGlobalScopes()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || ! $fresh->subscription_id) {
                throw new \RuntimeException('Renewal attempt is not linked to a subscription.');
            }

            if ($fresh->status === SubscriptionBillingAttempt::STATUS_PAID && $fresh->subscription_id) {
                return Subscription::withoutGlobalScopes()->findOrFail($fresh->subscription_id);
            }

            $subscription = Subscription::withoutGlobalScopes()
                ->whereKey($fresh->subscription_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $subscription->workspace_id !== (int) $fresh->workspace_id) {
                throw new \RuntimeException('Renewal attempt workspace mismatch.');
            }

            $plan = Plan::query()->findOrFail($fresh->plan_id);
            $this->assertAttemptMatchesPlan($fresh, $plan);

            if ($subscription->status === Subscription::STATUS_PAST_DUE) {
                SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_ACTIVE);
            } elseif ($subscription->status !== Subscription::STATUS_ACTIVE) {
                SubscriptionStateMachine::assertCanTransition($subscription->status, Subscription::STATUS_ACTIVE);
            }

            $periodEnd = $this->nextPeriodEnd($plan->billing_period);

            $subscription->forceFill([
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $periodEnd,
                'grace_ends_at' => null,
                'failed_payment_count' => 0,
                'cancelled_at' => null,
                'ends_at' => null,
                'metadata' => array_merge(
                    is_array($subscription->metadata) ? $subscription->metadata : [],
                    [
                        'renewed_at' => now()->toIso8601String(),
                        'renewed_via' => $verificationSource,
                    ]
                ),
            ])->save();

            $fresh->update([
                'status' => SubscriptionBillingAttempt::STATUS_PAID,
                'verified_at' => now(),
                'payment_reference' => $paymentReference,
            ]);

            Log::info('subscription.renewal.succeeded', [
                'subscription_id' => $subscription->id,
                'workspace_id' => $subscription->workspace_id,
                'attempt_id' => $fresh->id,
                'period_end' => $periodEnd->toIso8601String(),
            ]);

            return $subscription->refresh();
        });
    }

    public function applyVerifiedPaymentFailure(
        SubscriptionCheckoutSession $checkout,
        ?Subscription $subscription,
        string $reason
    ): void {
        $checkout->update([
            'payment_status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        SubscriptionBillingAttempt::withoutGlobalScopes()
            ->where('checkout_session_id', $checkout->id)
            ->whereIn('status', [SubscriptionBillingAttempt::STATUS_PENDING, SubscriptionBillingAttempt::STATUS_PROCESSING])
            ->update([
                'status' => SubscriptionBillingAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $reason,
                'active_period_guard' => null,
            ]);

        if ($subscription && SubscriptionStateMachine::canTransition($subscription->status, Subscription::STATUS_PAST_DUE)) {
            $this->markPastDue($subscription, 'verified_payment_failed');
        }

        Log::info('subscription.renewal.failed', [
            'workspace_id' => $checkout->workspace_id,
            'checkout_session_id' => $checkout->id,
            'subscription_id' => $subscription?->id,
            'reason' => $reason,
        ]);
    }

    private function activateVerifiedPendingCheckout(Subscription $subscription): bool
    {
        $checkout = SubscriptionCheckoutSession::withoutGlobalScopes()
            ->where('workspace_id', $subscription->workspace_id)
            ->where('plan_id', $subscription->plan_id)
            ->where('payment_status', 'paid')
            ->where('subscription_status', 'pending_activation')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if (! $checkout) {
            return false;
        }

        $workspace = Workspace::query()->find($subscription->workspace_id);
        if (! $workspace) {
            return false;
        }

        try {
            $this->activateVerifiedCheckout(
                $workspace,
                $checkout,
                'provider_webhook',
                $checkout->payment_reference
            );
        } catch (\Throwable $exception) {
            Log::warning('subscription.trial.pending_activation_failed', [
                'subscription_id' => $subscription->id,
                'checkout_session_id' => $checkout->id,
            ]);

            return false;
        }

        return true;
    }

    private function createRenewalAttemptIfNeeded(Subscription $subscription): bool
    {
        $plan = $subscription->plan;
        if (! $plan || $plan->billing_period === 'lifetime') {
            return false;
        }

        $periodKey = $subscription->current_period_end instanceof Carbon
            ? $subscription->current_period_end->toDateString()
            : (string) $subscription->current_period_end;

        try {
            return (bool) DB::transaction(function () use ($subscription, $plan, $periodKey): bool {
                Subscription::withoutGlobalScopes()
                    ->whereKey($subscription->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = SubscriptionBillingAttempt::withoutGlobalScopes()
                    ->where('subscription_id', $subscription->id)
                    ->where('purpose', SubscriptionBillingAttempt::PURPOSE_RENEWAL)
                    ->where('period_key', $periodKey)
                    ->whereIn('status', [
                        SubscriptionBillingAttempt::STATUS_PENDING,
                        SubscriptionBillingAttempt::STATUS_PROCESSING,
                        SubscriptionBillingAttempt::STATUS_PAID,
                    ])
                    ->exists();

                if ($existing) {
                    return false;
                }

                SubscriptionBillingAttempt::withoutGlobalScopes()->create([
                    'workspace_id' => $subscription->workspace_id,
                    'plan_id' => $plan->id,
                    'subscription_id' => $subscription->id,
                    'purpose' => SubscriptionBillingAttempt::PURPOSE_RENEWAL,
                    'status' => SubscriptionBillingAttempt::STATUS_PENDING,
                    'amount' => $plan->price,
                    'currency' => $plan->currency,
                    'payment_provider' => (string) config('subscription.checkout_provider', 'hyperpay'),
                    'provider_checkout_id' => 'subrnw_'.Str::lower(Str::random(24)),
                    'period_key' => $periodKey,
                    'active_period_guard' => $periodKey,
                    'expires_at' => $subscription->current_period_end,
                    'metadata' => [
                        'note' => 'Renewal attempt created. Activation requires verified provider payment.',
                    ],
                ]);

                Log::info('subscription.renewal.started', [
                    'subscription_id' => $subscription->id,
                    'workspace_id' => $subscription->workspace_id,
                    'period_key' => $periodKey,
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function assertAttemptMatchesPlan(SubscriptionBillingAttempt $attempt, Plan $plan): void
    {
        if ((string) $attempt->currency !== (string) $plan->currency) {
            throw new \RuntimeException('Billing attempt currency does not match the plan.');
        }

        if (round((float) $attempt->amount, 2) !== round((float) $plan->price, 2)) {
            throw new \RuntimeException('Billing attempt amount does not match the plan.');
        }
    }

    private function nextPeriodEnd(string $billingPeriod): Carbon
    {
        return match ($billingPeriod) {
            'yearly' => now()->addYear(),
            'lifetime' => now()->addYears(100),
            default => now()->addMonth(),
        };
    }

    private function runScheduled(callable $callback): mixed
    {
        return app(WorkspaceContext::class)->runBypassingTenantScope(
            'subscription-lifecycle-scheduler',
            $callback,
        );
    }
}
