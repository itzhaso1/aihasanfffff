<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(private readonly FeatureAccessService $featureAccessService) {}

    public function index(): View
    {
        $workspace = $this->currentWorkspace();
        $currentSubscription = Subscription::query()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing', 'past_due', 'expired'])
            ->latest('id')
            ->first();

        return view('workspace.dashboard', [
            'workspace' => $workspace,
            'currentSubscription' => $currentSubscription,
            'entitlements' => $this->featureAccessService->entitlementsSnapshot($workspace),
            'checkoutSessions' => SubscriptionCheckoutSession::query()
                ->with('plan')
                ->latest('id')
                ->limit(5)
                ->get(),
            'subscriptionHistory' => Subscription::query()
                ->with('plan')
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorize('update', $workspace);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $previous = is_string($settings['logo_path'] ?? null) ? $settings['logo_path'] : null;
        $settings['logo_path'] = $request->file('logo')->store('workspaces/'.$workspace->id.'/branding', 'public');
        $workspace->settings = $settings;
        $workspace->save();

        if ($previous && $previous !== $settings['logo_path']) {
            Storage::disk('public')->delete($previous);
        }

        return back()->with('success', 'تم تحديث شعار المساحة.');
    }

    public function destroyLogo(): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorize('update', $workspace);

        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $previous = is_string($settings['logo_path'] ?? null) ? $settings['logo_path'] : null;

        if ($previous) {
            Storage::disk('public')->delete($previous);
            unset($settings['logo_path']);
            $workspace->settings = $settings;
            $workspace->save();
        }

        return back()->with('success', 'تم استعادة شعار حاسم الافتراضي.');
    }
}
