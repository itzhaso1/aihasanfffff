<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Models\Order;
use App\Models\PosOrderReturn;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\PosReturnService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReturnController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly PosReturnService $posReturnService,
    ) {}

    public function store(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        if (! in_array($order->source, ['pos', 'qr_menu'], true)) {
            return $this->fail('المرتجعات متاحة لطلبات الكاشير فقط.', 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'mark_refunded' => ['nullable', 'boolean'],
        ]);

        try {
            $return = $this->posReturnService->createReturn($workspace, $order, $validated, $user);
            if (! empty($validated['mark_refunded'])) {
                $return = $this->posReturnService->markRefunded($return, $user);
            }
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'return_id' => $return->id,
            'status' => $return->status,
            'total' => (float) $return->total,
        ], message: 'تم تسجيل المرتجع.', status: 201);
    }

    public function markRefunded(Request $request, PosOrderReturn $return): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);

        try {
            $updated = $this->posReturnService->markRefunded($return, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'return_id' => $updated->id,
            'status' => $updated->status,
        ], message: 'تم تسجيل الاسترداد.');
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
