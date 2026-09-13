<?php

namespace App\Support\Subscription;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use Illuminate\Support\Collection;

/**
 * Presentation helpers for workspace subscription UI.
 * Does not activate subscriptions or change billing rules.
 */
final class SubscriptionWorkspacePresenter
{
    /**
     * @return array{kind:string,label:string,can_submit:bool}
     */
    public function cardCta(?Subscription $effective, ?Plan $currentPlan, Plan $target): array
    {
        if ($effective && (int) $effective->plan_id === (int) $target->id) {
            return [
                'kind' => 'current',
                'label' => __('باقتك الحالية'),
                'can_submit' => false,
            ];
        }

        if (! $effective || ! $currentPlan) {
            return [
                'kind' => 'choose',
                'label' => __('متابعة إلى الدفع'),
                'can_submit' => true,
            ];
        }

        $currentPrice = (float) $currentPlan->price;
        $targetPrice = (float) $target->price;

        if ($targetPrice > $currentPrice) {
            return [
                'kind' => 'upgrade',
                'label' => __('متابعة إلى الدفع'),
                'can_submit' => true,
            ];
        }

        if ($targetPrice < $currentPrice) {
            return [
                'kind' => 'downgrade',
                'label' => __('متابعة إلى الدفع'),
                'can_submit' => true,
            ];
        }

        return [
            'kind' => 'choose',
            'label' => __('متابعة إلى الدفع'),
            'can_submit' => true,
        ];
    }

    /**
     * Visual accents only. Does not change pricing, entitlements, or checkout rules.
     *
     * @return array{icon:string,button:string,icon_wrap:string,tier_badge:string,tier_label:string}
     */
    public function cardVisual(Plan $plan, int $index = 0): array
    {
        $tier = strtolower((string) $plan->tier);
        $accents = [
            'starter' => [
                'icon' => 'rocket',
                'tier_label' => 'Starter',
                'tier_badge' => 'bg-blue-50 text-blue-600',
                'button' => 'bg-[#3B82F6] hover:bg-[#2563EB] focus-visible:ring-[#3B82F6]',
                'icon_wrap' => 'bg-blue-50 text-blue-500',
            ],
            'pro' => [
                'icon' => 'crown',
                'tier_label' => 'Pro',
                'tier_badge' => 'bg-amber-50 text-amber-600',
                'button' => 'bg-[#F59E0B] hover:bg-[#D97706] focus-visible:ring-[#F59E0B]',
                'icon_wrap' => 'bg-amber-50 text-amber-500',
            ],
            'business' => [
                'icon' => 'briefcase',
                'tier_label' => 'Business',
                'tier_badge' => 'bg-[#E8F8F4] text-[#067e6b]',
                'button' => 'bg-[#06C2A4] hover:bg-[#04a98e] focus-visible:ring-[#06C2A4]',
                'icon_wrap' => 'bg-[#E8F8F4] text-[#06C2A4]',
            ],
            'enterprise' => [
                'icon' => 'building',
                'tier_label' => 'Enterprise',
                'tier_badge' => 'bg-violet-50 text-violet-600',
                'button' => 'bg-[#7C3AED] hover:bg-[#6D28D9] focus-visible:ring-[#7C3AED]',
                'icon_wrap' => 'bg-violet-50 text-violet-500',
            ],
        ];

        $fallback = [
            [
                'icon' => 'rocket',
                'tier_label' => 'Starter',
                'tier_badge' => 'bg-blue-50 text-blue-600',
                'button' => 'bg-[#3B82F6] hover:bg-[#2563EB] focus-visible:ring-[#3B82F6]',
                'icon_wrap' => 'bg-blue-50 text-blue-500',
            ],
            [
                'icon' => 'crown',
                'tier_label' => 'Pro',
                'tier_badge' => 'bg-amber-50 text-amber-600',
                'button' => 'bg-[#F59E0B] hover:bg-[#D97706] focus-visible:ring-[#F59E0B]',
                'icon_wrap' => 'bg-amber-50 text-amber-500',
            ],
            [
                'icon' => 'briefcase',
                'tier_label' => 'Business',
                'tier_badge' => 'bg-[#E8F8F4] text-[#067e6b]',
                'button' => 'bg-[#06C2A4] hover:bg-[#04a98e] focus-visible:ring-[#06C2A4]',
                'icon_wrap' => 'bg-[#E8F8F4] text-[#06C2A4]',
            ],
            [
                'icon' => 'building',
                'tier_label' => 'Enterprise',
                'tier_badge' => 'bg-violet-50 text-violet-600',
                'button' => 'bg-[#7C3AED] hover:bg-[#6D28D9] focus-visible:ring-[#7C3AED]',
                'icon_wrap' => 'bg-violet-50 text-violet-500',
            ],
        ];

        $visual = $accents[$tier] ?? $fallback[$index % 4];
        if (! isset($visual['tier_label']) || $visual['tier_label'] === '') {
            $visual['tier_label'] = $plan->name;
        }

        return $visual;
    }

    /**
     * @return array{label:string,active:bool}
     */
    public function checkoutDisplayStatus(SubscriptionCheckoutSession $session): array
    {
        if ($session->subscription_status === 'activated' && $session->payment_status === 'paid') {
            return ['label' => __('مفعل'), 'active' => true];
        }

        if ($session->checkout_status === 'expired') {
            return ['label' => __('منتهي'), 'active' => false];
        }

        if ($session->checkout_status === 'awaiting_payment') {
            return ['label' => __('بانتظار الدفع'), 'active' => false];
        }

        return ['label' => (string) $session->checkout_status, 'active' => false];
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            Subscription::STATUS_ACTIVE => __('نشط'),
            Subscription::STATUS_TRIALING => __('تجريبي'),
            Subscription::STATUS_PAST_DUE => __('متأخر الدفع'),
            Subscription::STATUS_PAUSED => __('موقوف'),
            Subscription::STATUS_CANCELLED => __('ملغى'),
            Subscription::STATUS_EXPIRED => __('منتهي'),
            default => $status ?: '—',
        };
    }

    public function periodLabel(?string $period): string
    {
        return match ($period) {
            'monthly' => __('شهرياً'),
            'yearly' => __('سنوياً'),
            'lifetime' => __('مدى الحياة'),
            default => $period ?: '—',
        };
    }

    public function featureLabel(string $key): string
    {
        $label = config('plans.commercial_features.'.$key);

        return is_string($label) && $label !== '' ? $label : $key;
    }

    public function limitLabel(string $key): string
    {
        $label = config('plans.limit_fields.'.$key)
            ?? config('plans.meters.'.$key.'.label');

        return is_string($label) && $label !== '' ? $label : $key;
    }

    public function formatLimit(mixed $value): string
    {
        if ($value === null || $value === '' || $value === -1 || $value === '-1') {
            return __('غير محدود');
        }

        if ((float) $value === 0.0) {
            return __('غير مشمول');
        }

        return number_format((float) $value);
    }

    public function trialRemainingDays(?Subscription $subscription): ?int
    {
        if (! $subscription || $subscription->status !== Subscription::STATUS_TRIALING || ! $subscription->trial_ends_at) {
            return null;
        }

        if ($subscription->trial_ends_at->isPast()) {
            return 0;
        }

        return max(0, (int) $subscription->trial_ends_at->diffInDays(now()));
    }

    public function isCheckoutExpired(SubscriptionCheckoutSession $session): bool
    {
        return $session->checkout_status === 'expired'
            || ($session->expires_at !== null && $session->expires_at->isPast());
    }

    public function isVerifiedPaid(SubscriptionCheckoutSession $session): bool
    {
        return $session->payment_status === 'paid'
            && $session->subscription_status === 'activated';
    }

    /**
     * @param  Collection<int, Plan>  $plans
     * @return list<array{key:string,label:string,availability:array<int,bool>}>
     */
    public function comparisonFromPlans(Collection $plans): array
    {
        $rows = config('plans.comparison_rows', []);
        $comparison = [];

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $availability = [];
            foreach ($plans as $plan) {
                $features = is_array($plan->features) ? array_map('strval', $plan->features) : [];
                $availability[$plan->id] = in_array($key, $features, true);
            }

            $comparison[] = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? $this->featureLabel($key)),
                'availability' => $availability,
            ];
        }

        return $comparison;
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    public function visibleFeatures(Plan $plan): array
    {
        $features = is_array($plan->features) ? array_values(array_map('strval', $plan->features)) : [];
        $items = [];

        foreach ($features as $key) {
            $items[] = [
                'key' => $key,
                'label' => $this->featureLabel($key),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{key:string,label:string,value:string}>
     */
    public function visibleLimits(Plan $plan): array
    {
        $limits = is_array($plan->limits) ? $plan->limits : [];
        $items = [];

        foreach (config('plans.limit_fields', []) as $key => $label) {
            if (! array_key_exists($key, $limits)) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'label' => is_string($label) ? $label : $this->limitLabel((string) $key),
                'value' => $this->formatLimit($limits[$key]),
            ];
        }

        return $items;
    }
}
