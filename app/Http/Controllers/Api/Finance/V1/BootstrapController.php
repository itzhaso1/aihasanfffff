<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceExpenseCategory;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->clientActor($request, $workspace, 'finance.view');
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $setting = FinanceSetting::forWorkspaceId((int) $workspace->id);

        return $this->ok([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'finance_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
            ],
            'permissions' => $this->financePermissionMap($user, $workspace),
            'settings' => $setting ? $this->presenter->settings($setting) : null,
            'catalog' => [
                'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'email', 'phone'])
                    ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'email' => $row->email, 'phone' => $row->phone])
                    ->all(),
                'products' => Product::query()->orderBy('name')->limit(500)->get(['id', 'name', 'price', 'currency', 'sku'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'price' => $this->presenter->money($row->price ?? 0),
                        'currency' => $row->currency,
                        'sku' => $row->sku,
                    ])->all(),
                'tax_rates' => FinanceTaxRate::query()->where('is_active', true)->orderByDesc('is_default')->get(['id', 'name', 'type', 'rate', 'code'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'type' => $row->type,
                        'rate' => $this->presenter->money($row->rate ?? 0),
                        'code' => $row->code,
                    ])->all(),
                'suppliers' => FinanceSupplier::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all(),
                'expense_categories' => FinanceExpenseCategory::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all(),
                'treasury_accounts' => FinanceTreasuryAccount::query()->where('is_active', true)->orderBy('type')->get(['id', 'name', 'type'])
                    ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'type' => $row->type])->all(),
                'contracts' => Contract::query()->latest('id')->limit(200)->get(['id', 'title', 'contract_number'])
                    ->map(fn ($row) => [
                        'id' => $row->id,
                        'name' => $row->contract_number ? ($row->contract_number.' · '.$row->title) : $row->title,
                    ])->all(),
                'projects' => FinanceProject::query()->orderBy('name')->limit(200)->get(['id', 'name'])
                    ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->all(),
            ],
        ]);
    }
}
