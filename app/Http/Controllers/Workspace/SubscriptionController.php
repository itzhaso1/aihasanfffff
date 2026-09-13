<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\SubscriptionLifecycleService;
use App\Services\Subscription\SubscriptionService;
use App\Support\Subscription\SubscriptionWorkspacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly FeatureAccessService $featureAccessService,
        private readonly SubscriptionLifecycleService $subscriptionLifecycle,
        private readonly SubscriptionWorkspacePresenter $presenter,
    ) {}

    public function index(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        $user = $request->user();
        $entitlements = $this->featureAccessService->entitlementsSnapshot($workspace);
        $availablePlans = $this->subscriptionService->availablePlans($workspace->type);
        $currentSubscription = $this->subscriptionService->current($workspace);
        $latestSubscription = $this->subscriptionService->latest($workspace);
        $displaySubscription = $currentSubscription ?? $latestSubscription;
        $currentPlan = $currentSubscription?->plan;
        $canManageBilling = $user !== null && $user->can('manageBilling', [Subscription::class, $workspace]);
        $recommendedTier = strtolower((string) (session('upgrade_required.required_plan') ?? ''));

        $planCards = $availablePlans->values()->map(function ($plan, $index) use ($currentSubscription, $currentPlan, $recommendedTier) {
            $tier = strtolower((string) $plan->tier);

            return [
                'plan' => $plan,
                'is_current' => $currentSubscription !== null && (int) $currentSubscription->plan_id === (int) $plan->id,
                'is_recommended' => $recommendedTier !== '' && $tier === $recommendedTier,
                'is_popular' => $tier === 'business',
                'status_label' => $currentSubscription !== null && (int) $currentSubscription->plan_id === (int) $plan->id
                    ? $this->presenter->statusLabel($currentSubscription->status)
                    : null,
                'cta' => $this->presenter->cardCta($currentSubscription, $currentPlan, $plan),
                'features' => $this->presenter->visibleFeatures($plan),
                'limits' => $this->presenter->visibleLimits($plan),
                'period_label' => $this->presenter->periodLabel($plan->billing_period),
                'visual' => $this->presenter->cardVisual($plan, $index),
            ];
        });

        return view('workspace.subscriptions.index', [
            'workspace' => $workspace,
            'currentSubscription' => $currentSubscription,
            'displaySubscription' => $displaySubscription,
            'canManageBilling' => $canManageBilling,
            'subscriptions' => Subscription::query()->with('plan')->latest('id')->paginate(10),
            'availablePlans' => $availablePlans,
            'planCards' => $planCards,
            'comparisonRows' => $this->presenter->comparisonFromPlans($availablePlans),
            'checkoutSessions' => SubscriptionCheckoutSession::query()
                ->with(['plan', 'activatedSubscription'])
                ->latest('id')
                ->paginate(10, ['*'], 'checkouts'),
            'entitlements' => $entitlements,
            'statusLabel' => fn (?string $status): string => $this->presenter->statusLabel($status),
            'periodLabel' => fn (?string $period): string => $this->presenter->periodLabel($period),
            'checkoutDisplayStatus' => fn (SubscriptionCheckoutSession $session): array => $this->presenter->checkoutDisplayStatus($session),
            'trialRemainingDays' => $this->presenter->trialRemainingDays($displaySubscription),
        ]);
    }

    /**
     * Comparison section lives on the same subscriptions page.
     */
    public function compare(): RedirectResponse
    {
        return redirect()->route('workspace.subscriptions.index', ['#compare']);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $plan = $this->subscriptionService->resolveActivatablePlan((int) $validated['plan_id']);
        $current = $this->subscriptionService->current($workspace);

        if ($current && (int) $current->plan_id === (int) $plan->id) {
            return redirect()
                ->route('workspace.subscriptions.index')
                ->with('error', __('هذه باقتك الحالية. لا يمكن إعادة شرائها.'));
        }

        try {
            $checkoutSession = $this->subscriptionService->createCheckoutSession(
                workspace: $workspace,
                plan: $plan,
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('workspace.subscriptions.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.subscriptions.checkout.show', $checkoutSession)
            ->with('success', __('تم تجهيز عملية الاشتراك. أكمل الدفع لتفعيل الباقة.'));
    }

    public function showCheckout(SubscriptionCheckoutSession $checkoutSession): View
    {
        $workspace = $this->currentWorkspace();
        $this->assertSameWorkspace($checkoutSession->workspace_id);
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        $checkoutSession->load(['plan', 'activatedSubscription']);

        return view('workspace.subscriptions.checkout', [
            'checkoutSession' => $checkoutSession,
            'isExpired' => $this->presenter->isCheckoutExpired($checkoutSession),
            'isVerifiedPaid' => $this->presenter->isVerifiedPaid($checkoutSession),
            'periodLabel' => $this->presenter->periodLabel($checkoutSession->plan?->billing_period),
            'canManageBilling' => true,
        ]);
    }

    public function returnFromProvider(SubscriptionCheckoutSession $checkoutSession): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->assertSameWorkspace($checkoutSession->workspace_id);
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        return redirect()
            ->route('workspace.subscriptions.checkout.show', $checkoutSession)
            ->with('error', __('العودة من بوابة الدفع لا تفعّل الاشتراك. بانتظار تحقق السيرفر.'));
    }

    public function confirmCheckoutPayment(Request $request, SubscriptionCheckoutSession $checkoutSession): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->assertSameWorkspace($checkoutSession->workspace_id);
        $this->authorize('manageBilling', [Subscription::class, $workspace]);

        $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        return redirect()
            ->route('workspace.subscriptions.checkout.show', $checkoutSession)
            ->with('error', __('لا يمكن تفعيل الاشتراك من تأكيد الواجهة. بانتظار تحقق الدفع من بوابة الدفع.'));
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        $this->assertSameWorkspace($subscription->workspace_id);
        $this->authorize('delete', $subscription);

        $this->subscriptionLifecycle->cancel($subscription);

        return redirect()->route('workspace.subscriptions.index')->with('success', __('تم إلغاء الاشتراك.'));
    }
}
