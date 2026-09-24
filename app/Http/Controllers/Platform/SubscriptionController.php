<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim($request->string('search')->toString());

        $subscriptions = Subscription::withoutGlobalScopes()
            ->with(['plan', 'workspace.owner'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->whereHas('workspace', function ($workspaceQuery) use ($search): void {
                        $workspaceQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('slug', 'like', '%'.$search.'%');
                    })->orWhereHas('workspace.owner', function ($ownerQuery) use ($search): void {
                        $ownerQuery
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('platform.subscriptions.index', compact('subscriptions', 'search'));
    }

    public function create(Request $request): View
    {
        $workspaceId = $request->integer('workspace_id') ?: null;

        return view('platform.subscriptions.create', [
            'workspaces' => Workspace::query()
                ->with('owner')
                ->orderByDesc('id')
                ->get(['id', 'name', 'slug', 'type', 'owner_user_id']),
            'plans' => Plan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get(['id', 'name', 'code', 'tier', 'workspace_type', 'display_name_ar']),
            'selectedWorkspaceId' => $workspaceId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', 'in:trialing,active'],
            'current_period_end' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        $workspace = Workspace::query()->findOrFail((int) $payload['workspace_id']);
        $plan = Plan::query()->whereKey((int) $payload['plan_id'])->where('is_active', true)->firstOrFail();

        $this->subscriptionService->grantByPlatformAdmin($workspace, $plan, [
            'status' => $payload['status'],
            'current_period_end' => $payload['current_period_end'] ?? null,
            'trial_ends_at' => $payload['trial_ends_at'] ?? null,
        ]);

        return redirect()
            ->route('platform.subscriptions.index')
            ->with('success', 'تم منح الاشتراك لمساحة العمل «'.$workspace->name.'».');
    }

    public function edit(Subscription $subscription): View
    {
        return view('platform.subscriptions.edit', [
            'subscription' => $subscription->load(['workspace', 'plan']),
            'plans' => Plan::query()->orderBy('name')->get(['id', 'name', 'workspace_type']),
        ]);
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $payload = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', 'in:trialing,active,past_due,paused,cancelled,expired'],
            'current_period_end' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        $subscription->update($payload);

        app(FeatureAccessService::class)
            ->forgetWorkspaceFeatureCache((int) $subscription->workspace_id);

        return redirect()->route('platform.subscriptions.index')->with('success', 'تم تحديث الاشتراك.');
    }
}
