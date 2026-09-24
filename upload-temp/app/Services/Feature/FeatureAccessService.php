<?php

namespace App\Services\Feature;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAddon;
use App\Models\WorkspaceFeatureFlag;
use App\Models\WorkspaceUsageMeter;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class FeatureAccessService
{
    /**
     * Map commercial tier aliases onto seeded plan codes.
     *
     * @var array<string, array<int, string>>
     */
    public const TIER_PLAN_CODES = [
        'starter' => ['starter', 'company_starter', 'store_starter', 'company_basic', 'store_basic', 'individual_free'],
        'pro' => ['pro', 'company_pro', 'store_pro', 'individual_pro'],
        'business' => ['business', 'company_business', 'store_business'],
        'enterprise' => ['enterprise', 'company_enterprise', 'store_enterprise'],
    ];

    public function hasFeature(User $user, Workspace $workspace, string $feature): bool
    {
        if (! $this->isActiveMember($user, $workspace)) {
            return false;
        }

        return $this->workspaceHasFeature($workspace, $feature);
    }

    public function workspaceHasFeature(Workspace $workspace, string $feature): bool
    {
        $featureKeys = $this->featureKeyCandidates($feature);

        foreach ($featureKeys as $featureKey) {
            $override = WorkspaceFeatureFlag::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('feature_key', $featureKey)
                ->first();

            if ($override) {
                return (bool) $override->enabled;
            }
        }

        $typeDefaults = config("workspace.features_by_type.{$workspace->type}", []);
        $allowedByType = false;
        foreach ($featureKeys as $featureKey) {
            if (in_array($featureKey, $typeDefaults, true)) {
                $allowedByType = true;
                break;
            }
        }
        if (! $allowedByType) {
            return false;
        }

        $enabledFeatures = $this->enabledFeatureSet($workspace);
        foreach ($featureKeys as $featureKey) {
            if (in_array($featureKey, $enabledFeatures, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Final feature set = plan features + add-on grants.
     *
     * @return array<int, string>
     */
    public function enabledFeatureSet(Workspace $workspace): array
    {
        $plan = $this->plan($workspace);
        if (! $plan) {
            return [];
        }

        $original = is_array($plan->features) ? array_map('strval', $plan->features) : [];
        $features = $original;

        // Add-on grants (features listed in plan_addons.grants.features).
        foreach ($this->activeAddonGrants($workspace) as $grant) {
            $grantedFeatures = $grant['features'] ?? [];
            if (is_array($grantedFeatures)) {
                $features = array_merge($features, array_map('strval', $grantedFeatures));
            }
        }

        return array_values(array_unique($features));
    }

    public function canUse(User $user, Workspace $workspace, string $feature, ?string $meter = null, int|float $amount = 1): bool
    {
        if (! $this->hasFeature($user, $workspace, $feature)) {
            return false;
        }

        if ($meter === null) {
            return true;
        }

        return ! $this->wouldExceedLimit($workspace, $meter, $amount);
    }

    public function assertFeature(User $user, Workspace $workspace, string $feature): void
    {
        if (! $this->hasFeature($user, $workspace, $feature)) {
            throw new FeatureNotAvailableException(
                feature: $feature,
                requiredPlan: $this->suggestedPlanForFeature($feature),
                message: __('هذه الميزة (:feature) غير متاحة في باقتك الحالية. قم بالترقية للمتابعة.', ['feature' => $feature]),
            );
        }
    }

    public function assertCanUse(User $user, Workspace $workspace, string $feature, ?string $meter = null, int|float $amount = 1): void
    {
        $this->assertFeature($user, $workspace, $feature);

        if ($meter !== null) {
            $this->assertWithinLimit($workspace, $meter, $amount);
        }
    }

    /**
     * @return array{limit:int|float|null,used:int|float,remaining:int|float|null,reset_at:?string,overage:string}
     */
    public function checkLimit(Workspace $workspace, string $meter): array
    {
        $limit = $this->limitValue($workspace, $meter);
        $used = $this->currentUsage($workspace, $meter);
        $resetAt = $this->periodEnd($workspace);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'reset_at' => $resetAt?->toIso8601String(),
            'overage' => $this->overageBehavior($workspace, $meter),
        ];
    }

    public function remaining(Workspace $workspace, string $meter): int|float|null
    {
        return $this->checkLimit($workspace, $meter)['remaining'];
    }

    public function consumeUsage(Workspace $workspace, string $meter, int|float $amount = 1, bool $enforce = true): WorkspaceUsageMeter
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Usage amount cannot be negative.');
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $meter, $amount, $enforce): WorkspaceUsageMeter {
            return DB::transaction(function () use ($workspace, $meter, $amount, $enforce): WorkspaceUsageMeter {
                if ($enforce) {
                    $this->assertWithinLimit($workspace, $meter, $amount);
                }

                $periodStart = $this->periodStart($workspace) ?? now()->startOfMonth();
                $periodEnd = $this->periodEnd($workspace) ?? now()->endOfMonth();
                $periodStartDate = $periodStart->toDateString();
                $periodEndDate = $periodEnd->toDateString();

                /** @var WorkspaceUsageMeter|null $row */
                $row = WorkspaceUsageMeter::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('meter_key', $meter)
                    ->whereDate('period_start', $periodStartDate)
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    try {
                        $row = WorkspaceUsageMeter::withoutGlobalScopes()->create([
                            'workspace_id' => $workspace->id,
                            'meter_key' => $meter,
                            'period_start' => $periodStartDate,
                            'period_end' => $periodEndDate,
                            'used' => 0,
                            'metadata' => [],
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        $row = WorkspaceUsageMeter::withoutGlobalScopes()
                            ->where('workspace_id', $workspace->id)
                            ->where('meter_key', $meter)
                            ->whereDate('period_start', $periodStartDate)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $row = WorkspaceUsageMeter::withoutGlobalScopes()
                        ->whereKey($row->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $row->forceFill([
                    'used' => (float) $row->used + (float) $amount,
                ])->save();

                return WorkspaceUsageMeter::withoutGlobalScopes()->findOrFail($row->id);
            });
        });
    }

    public function forgetWorkspaceFeatureCache(int $workspaceId): void
    {
        if ($workspaceId <= 0) {
            return;
        }

        cache()->forget('workspace.'.$workspaceId.'.enabled_features');
    }

    public function plan(Workspace $workspace): ?Plan
    {
        $subscription = $this->subscription($workspace);

        return $subscription?->plan;
    }

    public function subscription(Workspace $workspace): ?Subscription
    {
        // Entitlements stay available during past_due grace. paused/cancelled/expired do not.
        return Subscription::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, bool>
     */
    public function overrides(Workspace $workspace): array
    {
        return WorkspaceFeatureFlag::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->mapWithKeys(fn (WorkspaceFeatureFlag $flag): array => [
                $flag->feature_key => (bool) $flag->enabled,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function entitlementsSnapshot(Workspace $workspace): array
    {
        $plan = $this->plan($workspace);
        $features = $this->enabledFeatureSet($workspace);
        $limits = is_array($plan?->limits) ? $plan->limits : [];
        $meters = [];

        foreach (array_keys(config('plans.meters', [])) as $meter) {
            $meters[$meter] = $this->checkLimit($workspace, $meter);
        }

        $comparison = [];
        foreach (config('plans.comparison_rows', []) as $row) {
            $key = (string) ($row['key'] ?? '');
            $comparison[] = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? $key),
                'enabled' => $key !== '' && $this->workspaceHasFeature($workspace, $key),
            ];
        }

        return [
            'plan' => $plan ? [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'tier' => $this->resolveTier($plan),
                'billing_period' => $plan->billing_period,
                'price' => $plan->price,
                'currency' => $plan->currency,
            ] : null,
            'features' => $features,
            'comparison' => $comparison,
            'limits' => $limits,
            'overrides' => $this->overrides($workspace),
            'meters' => $meters,
            'subscription_status' => $this->subscription($workspace)?->status,
        ];
    }

    public function assertWithinLimit(Workspace $workspace, string $meter, int|float $amount = 1): void
    {
        if ($this->wouldExceedLimit($workspace, $meter, $amount)) {
            $check = $this->checkLimit($workspace, $meter);
            $behavior = $check['overage'];

            if ($behavior === 'pay_as_you_go' || $behavior === 'extra_credits') {
                // Soft allow — callers may bill separately; still record intent.
                return;
            }

            throw new UsageLimitExceededException(
                meter: $meter,
                limit: (float) ($check['limit'] ?? 0),
                used: (float) $check['used'],
                overageBehavior: $behavior,
            );
        }
    }

    public function wouldExceedLimit(Workspace $workspace, string $meter, int|float $amount = 1): bool
    {
        $limit = $this->limitValue($workspace, $meter);
        if ($limit === null) {
            return false;
        }

        $used = $this->currentUsage($workspace, $meter);

        return ($used + $amount) > $limit;
    }

    public function limitValue(Workspace $workspace, string $meter): int|float|null
    {
        $plan = $this->plan($workspace);
        $limits = is_array($plan?->limits) ? $plan->limits : [];

        $base = null;
        if (array_key_exists($meter, $limits)) {
            $value = $limits[$meter];
            $base = ($value === null || $value === '' || $value === -1) ? null : (float) $value;
        } else {
            $aliases = config("plans.meter_aliases.{$meter}", []);
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $limits)) {
                    $value = $limits[$alias];
                    $base = ($value === null || $value === '' || $value === -1) ? null : (float) $value;
                    break;
                }
            }
        }

        $addonBoost = 0.0;
        foreach ($this->activeAddonGrants($workspace) as $grant) {
            $limitGrants = $grant['limits'] ?? [];
            if (is_array($limitGrants) && array_key_exists($meter, $limitGrants)) {
                $addonBoost += (float) $limitGrants[$meter] * max(1, (int) ($grant['quantity'] ?? 1));
            }
            if (($grant['meter_key'] ?? null) === $meter) {
                $addonBoost += (float) ($grant['meter_quantity'] ?? 0) * max(1, (int) ($grant['quantity'] ?? 1));
            }
        }

        if ($base === null && $addonBoost <= 0) {
            return null;
        }

        return (float) ($base ?? 0) + $addonBoost;
    }

    public function currentUsage(Workspace $workspace, string $meter): float
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $meter): float {
            $periodStart = $this->periodStart($workspace);
            if (! $periodStart) {
                $periodStart = now()->startOfMonth();
            }

            $row = WorkspaceUsageMeter::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('meter_key', $meter)
                ->whereDate('period_start', $periodStart->toDateString())
                ->first();

            if ($row) {
                return (float) $row->used;
            }

            // Live counters for resource-based meters that are not period-resetting inventory.
            return match ($meter) {
                'products' => (float) $workspace->products()->count(),
                'team_members', 'users' => (float) $workspace->users()->wherePivot('status', 'active')->count(),
                'customers' => (float) $workspace->customers()->count(),
                default => 0.0,
            };
        });
    }

    public function overageBehavior(Workspace $workspace, string $meter): string
    {
        $plan = $this->plan($workspace);
        $rules = is_array($plan?->overage_rules) ? $plan->overage_rules : [];

        return (string) ($rules[$meter] ?? config("plans.meters.{$meter}.overage", 'hard_block'));
    }

    public function periodStart(Workspace $workspace): ?CarbonInterface
    {
        $subscription = $this->subscription($workspace);

        return $subscription?->current_period_start?->copy()->startOfDay()
            ?? now()->startOfMonth();
    }

    public function periodEnd(Workspace $workspace): ?CarbonInterface
    {
        $subscription = $this->subscription($workspace);

        return $subscription?->current_period_end
            ?? now()->endOfMonth();
    }

    public function suggestedPlanForFeature(string $feature): ?string
    {
        $candidates = $this->featureKeyCandidates($feature);

        foreach (['starter', 'pro', 'business', 'enterprise'] as $tier) {
            $plan = Plan::query()
                ->where('is_active', true)
                ->where(function ($query) use ($tier): void {
                    $query->where('tier', $tier)
                        ->orWhereIn('code', self::TIER_PLAN_CODES[$tier] ?? []);
                })
                ->orderBy('sort_order')
                ->orderBy('price')
                ->first();

            if (! $plan) {
                continue;
            }

            $features = is_array($plan->features) ? $plan->features : [];
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $features, true)) {
                    return strtoupper($tier);
                }
            }
        }

        // Fallback to seed defaults only if DB has no matching plan yet.
        $matrix = config('plans.feature_matrix', []);
        foreach (['starter', 'pro', 'business', 'enterprise'] as $tier) {
            $features = $matrix[$tier]['features'] ?? [];
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $features, true)) {
                    return strtoupper($tier);
                }
            }
        }

        return 'PRO';
    }

    public function resolveTier(Plan $plan): string
    {
        if (filled($plan->tier)) {
            return (string) $plan->tier;
        }

        $map = config('plans.legacy_code_tier_map', []);
        if (isset($map[$plan->code])) {
            return (string) $map[$plan->code];
        }

        return $this->inferTier((string) $plan->code);
    }

    /**
     * @return array<int, string>
     */
    private function featureKeyCandidates(string $feature): array
    {
        $keys = [$feature];
        $aliases = config("plans.feature_aliases.{$feature}", []);
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $keys[] = (string) $alias;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeAddonGrants(Workspace $workspace): array
    {
        if (! class_exists(WorkspaceAddon::class)) {
            return [];
        }

        return WorkspaceAddon::withoutGlobalScopes()
            ->with('addon')
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->get()
            ->map(function (WorkspaceAddon $row): array {
                $grants = is_array($row->addon?->grants) ? $row->addon->grants : [];

                return [
                    'features' => $grants['features'] ?? [],
                    'limits' => $grants['limits'] ?? [],
                    'quantity' => (int) $row->quantity,
                    'meter_key' => $row->addon?->meter_key,
                    'meter_quantity' => (int) ($row->addon?->quantity ?? 0),
                ];
            })
            ->all();
    }

    private function isActiveMember(User $user, Workspace $workspace): bool
    {
        return $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function inferTier(string $code): string
    {
        $map = config('plans.legacy_code_tier_map', []);
        if (isset($map[$code])) {
            return (string) $map[$code];
        }

        foreach (self::TIER_PLAN_CODES as $tier => $codes) {
            if (in_array($code, $codes, true) || str_contains($code, $tier)) {
                return $tier;
            }
        }

        return 'starter';
    }
}
