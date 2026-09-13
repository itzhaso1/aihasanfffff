<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Subscription\UntrustedSubscriptionActivationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateSubscriptionCheckoutRequest;
use App\Http\Resources\Api\Subscription\SubscriptionCheckoutResource;
use App\Http\Resources\Api\Subscription\SubscriptionPlanResource;
use App\Http\Resources\Api\Subscription\SubscriptionResource;
use App\Http\Resources\Api\Subscription\SubscriptionUsageResource;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\SubscriptionLifecycleService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Subscription\SubscriptionWorkspacePresenter;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionLifecycleService $subscriptionLifecycle,
        private readonly FeatureAccessService $featureAccessService,
        private readonly SubscriptionWorkspacePresenter $presenter,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $this->authorize('viewAny', Subscription::class);

        $subscription = $this->subscriptionService->current($workspace);
        if ($subscription) {
            $this->authorize('view', $subscription);
            $subscription->loadMissing('plan');
        }

        return response()->json([
            'data' => [
                'current' => $subscription
                    ? (new SubscriptionResource($subscription))->resolve()
                    : null,
                'plans' => SubscriptionPlanResource::collection(
                    $this->subscriptionService->availablePlans($workspace->type)
                )->resolve(),
            ],
        ]);
    }

    public function plans(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $this->authorize('viewAny', Subscription::class);

        return response()->json([
            'data' => SubscriptionPlanResource::collection(
                $this->subscriptionService->availablePlans($workspace->type)
            )->resolve(),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $this->authorize('viewAny', Subscription::class);

        $subscription = $this->visibleSubscription($workspace);
        if ($subscription) {
            $this->authorize('view', $subscription);
        }

        return response()->json([
            'data' => [
                'subscription' => $subscription
                    ? (new SubscriptionResource($subscription))->resolve()
                    : null,
                'entitlements' => (new SubscriptionUsageResource(
                    $this->entitlementsPayload($workspace)
                ))->resolve(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        return response()->json([
            'message' => 'Direct subscription activation is not allowed. Complete checkout with a verified payment.',
        ], 405);
    }

    public function checkout(CreateSubscriptionCheckoutRequest $request): JsonResponse
    {
        return $this->startCheckout($request, includeChangeKind: false);
    }

    public function changePlan(CreateSubscriptionCheckoutRequest $request): JsonResponse
    {
        return $this->startCheckout($request, includeChangeKind: true);
    }

    public function showCheckout(SubscriptionCheckoutSession $checkoutSession): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        abort_unless((int) $checkoutSession->workspace_id === (int) $workspace->id, 404);
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        $checkoutSession->loadMissing('plan');

        return response()->json([
            'data' => (new SubscriptionCheckoutResource($checkoutSession))->resolve(),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        $subscription = $this->visibleSubscription($workspace);

        if (! $subscription) {
            return response()->json([
                'message' => __('لا يوجد اشتراك لإلغائه.'),
            ], 422);
        }

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return response()->json([
                'data' => (new SubscriptionResource($subscription))->resolve(),
                'message' => __('الاشتراك ملغى بالفعل.'),
            ]);
        }

        try {
            $subscription = $this->subscriptionLifecycle->cancel($subscription);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => __('لا يمكن إلغاء الاشتراك في حالته الحالية.'),
            ], 422);
        }

        return response()->json([
            'data' => (new SubscriptionResource($subscription->loadMissing('plan')))->resolve(),
            'message' => __('تم إلغاء الاشتراك.'),
        ]);
    }

    private function startCheckout(CreateSubscriptionCheckoutRequest $request, bool $includeChangeKind): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        try {
            $plan = $this->subscriptionService->resolveActivatablePlan((int) $request->validated('plan_id'));
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => __('الباقة غير متاحة للاشتراك.'),
            ], 404);
        }

        $current = $this->subscriptionService->current($workspace);
        $currentPlan = $current?->plan;

        if ($current && (int) $current->plan_id === (int) $plan->id) {
            return response()->json([
                'message' => __('هذه باقتك الحالية. لا يمكن إعادة شرائها.'),
            ], 422);
        }

        try {
            $checkout = $this->subscriptionService->createCheckoutSession($workspace, $plan)->loadMissing('plan');
        } catch (UntrustedSubscriptionActivationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $payload = (new SubscriptionCheckoutResource($checkout))->resolve();

        if ($includeChangeKind) {
            $payload['change_kind'] = $this->presenter->cardCta($current, $currentPlan, $plan)['kind'];
        }

        return response()->json([
            'data' => $payload,
            'message' => __('تم تجهيز عملية الاشتراك. أكمل الدفع لتفعيل الباقة.'),
        ], 201);
    }

    private function requireWorkspace(): Workspace
    {
        $workspace = $this->workspaceContext->workspace();
        abort_unless($workspace, 422, 'Workspace not resolved.');

        return $workspace;
    }

    private function visibleSubscription(Workspace $workspace): ?Subscription
    {
        $subscription = $this->subscriptionService->current($workspace)
            ?? $this->subscriptionService->latest($workspace);

        return $subscription?->loadMissing('plan');
    }

    /**
     * @return array<string, mixed>
     */
    private function entitlementsPayload(Workspace $workspace): array
    {
        $snapshot = $this->featureAccessService->entitlementsSnapshot($workspace);

        return [
            'features' => $snapshot['features'] ?? [],
            'limits' => $snapshot['limits'] ?? [],
            'meters' => $snapshot['meters'] ?? [],
            'subscription_status' => $snapshot['subscription_status'] ?? null,
        ];
    }
}
