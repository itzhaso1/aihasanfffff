<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Models\Plan;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class PlanController extends CashierController
{
    use ResolvesCashierWorkspace;

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

        $planPayload = $plans->map(function (Plan $plan): array {
            $features = is_array($plan->features) ? array_values(array_map('strval', $plan->features)) : [];

            return [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
                'features' => $features,
                'includes_pos' => in_array('pos', $features, true),
            ];
        })->values();

        return $this->ok([
            'plans' => $planPayload,
            'plans_url' => url('/workspace/billing'),
        ]);
    }
}
