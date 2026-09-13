<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Requests\Pos\StorePosOrderRequest;
use App\Http\Requests\Pos\UpdatePosOrderStatusRequest;
use App\Http\Requests\Pos\UpdateTableOrderRequest;
use App\Http\Resources\Cashier\OrderResource;
use App\Models\Order;
use App\Services\Feature\FeatureAccessService;
use App\Services\Pos\PosOrderService;
use App\Services\Pos\PosOrderStatsService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrderController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly PosOrderService $posOrderService,
        private readonly PosOrderStatsService $posOrderStatsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $status = (string) $request->query('status', 'running');
        $query = Order::query()
            ->with(['items', 'table:id,name', 'customer:id,name,phone'])
            ->whereIn('source', ['pos', 'qr_menu'])
            ->latest('id');

        if ($status === 'running') {
            $query->where(function ($q): void {
                $q->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready', 'delivered'])
                    ->orWhere(function ($inner): void {
                        $inner->whereNull('table_session_id')
                            ->where('pos_status', 'completed')
                            ->whereNull('pos_cashier_invoice_id');
                    });
            })->whereNull('pos_cashier_invoice_id');
        } elseif ($status === 'menu') {
            $query->where('source', 'qr_menu')
                ->whereIn('pos_status', ['new', 'accepted', 'preparing', 'ready']);
        } elseif ($status !== 'all') {
            $query->where('pos_status', $status);
        }

        $orders = $query->paginate(min(50, max(1, (int) $request->query('per_page', 20))));

        return $this->ok([
            'orders' => OrderResource::collection($orders->getCollection()),
        ], meta: [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
        ]);
    }

    /**
     * Incremental menu/QR orders poll — mirrors Web `orders/recent-menu?after_id=`.
     */
    public function recentMenu(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $afterId = max(0, (int) $request->query('after_id', 0));

        $orders = Order::query()
            ->with(['table:id,name', 'items:id,order_id,product_name,quantity,total_amount'])
            ->where('source', 'qr_menu')
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->latest('id')
            ->limit(20)
            ->get();

        $payload = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'pos_status' => $order->pos_status,
            'table_name' => $order->table?->name,
            'dining_table_id' => $order->dining_table_id,
            'notes' => $order->notes,
            'total_amount' => (float) $order->total_amount,
            'currency' => $order->currency,
            'placed_at' => optional($order->placed_at)?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'total_amount' => (float) $item->total_amount,
            ])->values(),
        ])->values();

        return $this->ok([
            'orders' => $payload,
            'latest_id' => (int) ($payload->max('id') ?? $afterId),
        ]);
    }

    public function channelStats(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        return $this->ok([
            'stats' => $this->posOrderStatsService->channelCounts(),
        ]);
    }

    public function store(StorePosOrderRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $validated = $request->validated();
        $clientReference = isset($validated['client_reference'])
            ? trim((string) $validated['client_reference'])
            : '';
        $preExistingId = $clientReference !== ''
            ? Order::query()
                ->where('client_reference', $clientReference)
                ->value('id')
            : null;

        try {
            $order = $this->posOrderService->createPosOrder($workspace, $validated, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        $isReplay = $preExistingId !== null && (int) $preExistingId === (int) $order->id;
        $status = $isReplay ? 200 : 201;

        return $this->ok(
            new OrderResource($order->load(['items', 'table', 'customer'])),
            message: $isReplay ? 'تم استرجاع الطلب الحالي.' : 'تم إنشاء الطلب بنجاح.',
            status: $status,
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePosOrder($order);

        return $this->ok(new OrderResource($order->load(['items', 'table', 'customer', 'tableSession'])));
    }

    public function updateStatus(UpdatePosOrderStatusRequest $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);
        $this->ensurePosOrder($order);

        try {
            $updated = $this->posOrderService->updatePosStatus(
                $order,
                $request->string('pos_status')->toString(),
                $user
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new OrderResource($updated->load(['items', 'table', 'customer'])), message: 'تم تحديث حالة الطلب.');
    }

    public function updateItems(UpdateTableOrderRequest $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePosOrder($order);

        try {
            $updated = $this->posOrderService->updateOrderItems($order, $request->validated());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new OrderResource($updated->load(['items', 'table', 'customer'])), message: 'تم تعديل الطلب.');
    }

    /**
     * Delete (cancel) a table/POS order — mirrors Web "حذف الطلب".
     * Respects Laravel business rules (paid / invoiced / already cancelled).
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);
        $this->ensurePosOrder($order);

        try {
            $updated = $this->posOrderService->deletePosOrder($order, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new OrderResource($updated->load(['items', 'table', 'customer'])),
            message: 'تم حذف الطلب.',
        );
    }

    public function createInvoice(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCashier($request, $workspace);
        $this->ensurePosOrder($order);

        try {
            $invoice = $this->posOrderService->createInvoiceFromOrder($order, (int) $user->id);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total_amount' => (float) $invoice->total_amount,
            'currency' => $invoice->currency,
            'order' => new OrderResource($order->fresh(['items', 'table', 'customer'])),
        ], message: 'تم إنشاء فاتورة الكاشير.');
    }

    public function createPaymentLink(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePosOrder($order);

        try {
            $payment = $this->posOrderService->createPaymentLinkForOrder($order);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'payment_id' => $payment->id,
            'payment_link' => $payment->payment_link,
            'status' => $payment->status,
        ], message: 'تم إنشاء رابط الدفع.');
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

    private function ensurePosOrder(Order $order): void
    {
        if (! in_array($order->source, ['pos', 'qr_menu'], true)) {
            throw new HttpResponseException($this->fail('الطلب غير تابع للكاشير.', 404));
        }
    }
}
