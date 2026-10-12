<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $hasMoneyBucket = Schema::hasColumn('payments', 'money_bucket');

        $platformRevenueQuery = Payment::withoutGlobalScopes()->where('status', 'paid');
        $merchantGmvQuery = Payment::withoutGlobalScopes()->where('status', 'paid');

        if ($hasMoneyBucket) {
            $platformRevenueQuery->where('money_bucket', 'platform_revenue');
            $merchantGmvQuery->where('money_bucket', 'merchant_gmv');
        } else {
            // Without bucket column we cannot honestly split — report unavailable zeros for revenue split.
            $platformRevenueQuery->whereRaw('1 = 0');
            $merchantGmvQuery->whereRaw('1 = 0');
        }

        $subscriptionStatuses = [
            'trial' => 0,
            'active' => 0,
            'past_due' => 0,
            'paused' => 0,
            'cancelled' => 0,
            'expired' => 0,
        ];

        if (Schema::hasColumn('subscriptions', 'status')) {
            $counts = Subscription::withoutGlobalScopes()
                ->select('status', DB::raw('count(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            foreach ($subscriptionStatuses as $status => $_) {
                $subscriptionStatuses[$status] = (int) ($counts[$status] ?? 0);
            }
        }

        $planDistribution = [];
        if (Schema::hasTable('plans') && Schema::hasTable('subscriptions')) {
            $officialCodes = ['starter', 'pro', 'business', 'enterprise'];
            $plans = Plan::query()
                ->whereIn('code', $officialCodes)
                ->when(
                    Schema::hasColumn('plans', 'is_official'),
                    fn ($q) => $q->where('is_official', true)
                )
                ->get(['id', 'code', 'name', 'display_name_ar']);

            $activePlanCounts = Subscription::withoutGlobalScopes()
                ->whereIn('status', ['trial', 'active', 'past_due'])
                ->select('plan_id', DB::raw('count(*) as aggregate'))
                ->groupBy('plan_id')
                ->pluck('aggregate', 'plan_id');

            foreach ($plans as $plan) {
                $planDistribution[] = [
                    'code' => $plan->code,
                    'name' => $plan->display_name_ar ?: $plan->name,
                    'count' => (int) ($activePlanCounts[$plan->id] ?? 0),
                ];
            }
        }

        $merchantVerification = [
            'pending_review' => 0,
            'approved' => 0,
            'rejected' => 0,
            'suspended' => 0,
            'documents_required' => 0,
            'not_requested' => 0,
        ];

        if (Schema::hasTable('merchant_profiles')) {
            $vCounts = MerchantProfile::query()
                ->select('verification_status', DB::raw('count(*) as aggregate'))
                ->groupBy('verification_status')
                ->pluck('aggregate', 'verification_status');

            foreach ($merchantVerification as $status => $_) {
                $merchantVerification[$status] = (int) ($vCounts[$status] ?? 0);
            }
        }

        $providerOnboarding = [
            'not_started' => 0,
            'pending' => 0,
            'active' => 0,
            'failed' => 0,
        ];

        if (Schema::hasTable('merchant_profiles') && Schema::hasColumn('merchant_profiles', 'provider_onboarding_status')) {
            $pCounts = MerchantProfile::query()
                ->select('provider_onboarding_status', DB::raw('count(*) as aggregate'))
                ->groupBy('provider_onboarding_status')
                ->pluck('aggregate', 'provider_onboarding_status');

            foreach ($providerOnboarding as $status => $_) {
                $providerOnboarding[$status] = (int) ($pCounts[$status] ?? 0);
            }
        }

        $stats = [
            'users' => User::query()->count(),
            'workspaces' => Workspace::query()->count(),
            'plans' => Plan::query()->count(),
            'subscriptions' => Subscription::withoutGlobalScopes()->count(),
            'orders' => Order::withoutGlobalScopes()->count(),
            'payments_paid' => Payment::withoutGlobalScopes()->where('status', 'paid')->count(),
            'platform_revenue_amount' => (float) (clone $platformRevenueQuery)->sum('amount'),
            'platform_revenue_count' => (int) (clone $platformRevenueQuery)->count(),
            'merchant_gmv_amount' => (float) (clone $merchantGmvQuery)->sum('amount'),
            'merchant_gmv_count' => (int) (clone $merchantGmvQuery)->count(),
            'money_bucket_available' => $hasMoneyBucket,
            'subscription_statuses' => $subscriptionStatuses,
            'plan_distribution' => $planDistribution,
            'merchant_verification' => $merchantVerification,
            'provider_onboarding' => $providerOnboarding,
            'merchants_approved' => $merchantVerification['approved'],
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'admin' => auth('platform_admin')->user(),
                    'stats' => $stats,
                ],
            ]);
        }

        return view('platform.dashboard', ['stats' => $stats]);
    }
}
