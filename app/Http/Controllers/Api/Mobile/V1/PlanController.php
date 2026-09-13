<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\Plan;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class PlanController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function current(): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        return $this->ok($this->featureAccessService->entitlementsSnapshot($workspace));
    }

    public function index(): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        /** @var Collection<int, Plan> $plans */
        $plans = $this->subscriptionService->availablePlans($workspace->type);
        $comparisonRows = config('plans.comparison_rows', []);

        $planPayload = $plans->map(function (Plan $plan): array {
            $features = is_array($plan->features) ? array_values(array_map('strval', $plan->features)) : [];

            return [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->display_name_ar ?: $plan->name,
                'description' => $plan->description,
                'tier' => $plan->tier,
                'billing_period' => $plan->billing_period,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'features' => $features,
                'limits' => is_array($plan->limits) ? $plan->limits : [],
            ];
        })->values();

        $comparison = collect($comparisonRows)->map(function (array $row) use ($plans): array {
            $key = (string) ($row['key'] ?? '');
            $byPlan = [];
            foreach ($plans as $plan) {
                $features = is_array($plan->features) ? array_map('strval', $plan->features) : [];
                $byPlan[$plan->code] = $key !== '' && in_array($key, $features, true);
            }

            return [
                'key' => $key,
                'label' => (string) ($row['label'] ?? $key),
                'plans' => $byPlan,
            ];
        })->values();

        return $this->ok([
            'plans' => $planPayload,
            'comparison_rows' => $comparisonRows,
            'comparison' => $comparison,
        ]);
    }
}
