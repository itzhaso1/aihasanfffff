<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanAddon;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::query()
            ->orderBy('sort_order')
            ->orderBy('price')
            ->orderBy('id')
            ->paginate(30);

        $matrixPlans = $this->commercialMatrixPlans();
        $comparisonRows = config('plans.comparison_rows', []);
        $addons = PlanAddon::query()->where('is_active', true)->orderBy('name')->get();

        return view('platform.plans.index', compact('plans', 'matrixPlans', 'comparisonRows', 'addons'));
    }

    public function create(): View
    {
        return view('platform.plans.create', [
            'commercialFeatures' => config('plans.commercial_features', []),
            'limitFields' => config('plans.limit_fields', []),
            'addons' => PlanAddon::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatePayload($request);
        Plan::query()->create($payload);

        return redirect()->route('platform.plans.index')->with('success', 'تم إنشاء الباقة.');
    }

    public function edit(Plan $plan): View
    {
        return view('platform.plans.edit', [
            'plan' => $plan,
            'commercialFeatures' => config('plans.commercial_features', []),
            'limitFields' => config('plans.limit_fields', []),
            'addons' => PlanAddon::query()->orderBy('name')->get(),
            'featuresJson' => json_encode($plan->features ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'permissionsJson' => json_encode($plan->permissions ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'limitsJson' => json_encode($plan->limits ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'overageJson' => json_encode($plan->overage_rules ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validatePayload($request, $plan));

        return redirect()->route('platform.plans.index')->with('success', 'تم تحديث الباقة.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        $plan->delete();

        return redirect()->route('platform.plans.index')->with('success', 'تم حذف الباقة.');
    }

    /**
     * @return array<string, Plan|null>
     */
    private function commercialMatrixPlans(): array
    {
        $result = [];
        foreach (['starter', 'pro', 'business', 'enterprise'] as $tier) {
            $result[$tier] = Plan::query()
                ->where('is_active', true)
                ->where(function ($query) use ($tier): void {
                    $query->where('tier', $tier)
                        ->orWhereIn('code', FeatureAccessService::TIER_PLAN_CODES[$tier] ?? []);
                })
                ->whereIn('workspace_type', ['company', 'store'])
                ->orderByDesc('is_public')
                ->orderBy('sort_order')
                ->orderBy('price')
                ->first();
        }

        return $result;
    }

    private function validatePayload(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'display_name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tier' => ['nullable', 'in:starter,pro,business,enterprise'],
            'workspace_type' => ['required', 'in:individual,company,store'],
            'billing_period' => ['required', 'in:monthly,yearly,lifetime'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'currency' => ['required', 'string', 'size:3'],
            'price' => ['required', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'is_official' => ['nullable', 'boolean'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
            'features_json' => ['nullable', 'string'],
            'permissions_json' => ['nullable', 'string'],
            'limits_json' => ['nullable', 'string'],
            'overage_json' => ['nullable', 'string'],
            'limits' => ['nullable', 'array'],
        ]);

        // Prefer checkbox features when submitted; JSON only when checkboxes empty and JSON provided.
        $featuresFromCheckboxes = array_values(array_filter((array) $request->input('features', [])));
        $featuresFromJson = json_decode((string) $request->input('features_json', '[]'), true);
        $features = $featuresFromCheckboxes !== []
            ? $featuresFromCheckboxes
            : (is_array($featuresFromJson) ? $featuresFromJson : []);

        $permissionsFromCheckboxes = array_values(array_filter((array) $request->input('permissions', [])));
        $permissionsFromJson = json_decode((string) $request->input('permissions_json', '[]'), true);
        $permissions = $permissionsFromCheckboxes !== []
            ? $permissionsFromCheckboxes
            : (is_array($permissionsFromJson) ? $permissionsFromJson : []);

        $limitsFromFields = [];
        foreach ((array) $request->input('limits', []) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $limitsFromFields[(string) $key] = is_numeric($value) ? (0 + $value) : $value;
        }
        $limitsFromJson = json_decode((string) $request->input('limits_json', '{}'), true);
        $limits = $limitsFromFields !== []
            ? $limitsFromFields
            : (is_array($limitsFromJson) ? $limitsFromJson : []);

        $overage = json_decode((string) $request->input('overage_json', '{}'), true);

        return [
            'code' => $data['code'] ?: Str::slug($data['workspace_type'].'-'.$data['name']),
            'name' => $data['name'],
            'display_name_ar' => $data['display_name_ar'] ?? null,
            'description' => $data['description'] ?? null,
            'tier' => $data['tier'] ?? null,
            'workspace_type' => $data['workspace_type'],
            'billing_period' => $data['billing_period'],
            'trial_days' => (int) ($data['trial_days'] ?? 14),
            'currency' => strtoupper($data['currency']),
            'price' => $data['price'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
            'is_public' => $request->boolean('is_public'),
            'is_official' => $request->boolean('is_official'),
            'features' => is_array($features) ? array_values(array_unique($features)) : [],
            'permissions' => is_array($permissions) ? array_values(array_unique($permissions)) : [],
            'limits' => is_array($limits) ? $limits : [],
            'overage_rules' => is_array($overage) ? $overage : [],
        ];
    }
}
