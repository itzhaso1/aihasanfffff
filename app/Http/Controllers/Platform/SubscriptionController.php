<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $subscriptions = Subscription::withoutGlobalScopes()
            ->with(['plan', 'workspace'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('platform.subscriptions.index', compact('subscriptions'));
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

        return redirect()->route('platform.subscriptions.index')->with('success', 'تم تحديث الاشتراك.');
    }
}
