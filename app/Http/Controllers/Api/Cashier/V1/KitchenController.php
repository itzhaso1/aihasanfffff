<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Resources\Cashier\OrderResource;
use App\Models\Order;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KitchenController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $orders = Order::query()
            ->with(['items', 'table:id,name', 'customer:id,name,phone'])
            ->whereIn('source', ['pos', 'qr_menu'])
            ->whereNotNull('dining_table_id')
            ->whereNull('pos_cashier_invoice_id')
            ->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready'])
            ->latest('id')
            ->limit(50)
            ->get();

        return $this->ok([
            'orders' => OrderResource::collection($orders),
            'statuses' => [
                'new' => 'جديد',
                'accepted' => 'تم القبول',
                'preparing' => 'قيد التجهيز',
                'ready' => 'جاهز',
            ],
        ]);
    }

    private function ensurePos(\App\Models\Workspace $workspace): void
    {
        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpResponseException(
                $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                    'pos_enabled' => false,
                    'plans_url' => url('/workspace/billing'),
                ])
            );
        }
    }
}
