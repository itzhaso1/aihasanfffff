<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Exceptions\CommerceCartValidationException;
use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesCommerceOrders;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Commerce\ConvertCommerceCartRequest;
use App\Http\Resources\Mobile\CommerceOrderResource;
use App\Models\Commerce\CommerceCart;
use App\Models\Order;
use App\Services\Commerce\CommerceOrderService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class CommerceOrderController extends MobileController
{
    use AuthorizesCommerceOrders;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly CommerceOrderService $orders,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceOrderView($request, $workspace);
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $paginator = Order::query()
            ->where('source', 'commerce')
            ->with([
                'customer',
                'items.commerceSeller',
                'sellerAllocations.seller',
                'inventoryReservations',
            ])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('commerce_status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return $this->ok(
            CommerceOrderResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function preview(Request $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceOrderManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);

        $result = $this->orders->validateCartForOrder($workspace, $cart);

        return $this->ok([
            'valid' => $result->isValid(),
            'problems' => $result->problems,
            'currency' => $cart->currency,
            'subtotal' => $this->sum($result, 'subtotal'),
            'tax_amount' => $this->sum($result, 'taxAmount'),
            'total_amount' => $this->sum($result, 'total'),
            'lines' => collect($result->lines)->map(fn ($line) => [
                'cart_item_id' => $line->cartItem->id,
                'catalog_item_id' => $line->item->id,
                'catalog_variant_id' => $line->variant?->id,
                'seller_id' => $line->seller->id,
                'quantity' => $line->price->quantity,
                'unit_price' => $line->price->unitPrice,
                'subtotal' => $line->price->subtotal,
                'tax_amount' => $line->price->taxAmount,
                'total' => $line->price->total,
                'currency' => $line->price->currency,
                'tax_presentation' => $line->price->taxPresentation,
            ])->values()->all(),
        ]);
    }

    public function convert(ConvertCommerceCartRequest $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceOrderManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);

        try {
            $order = $this->orders->createOrder(
                $workspace,
                $cart,
                $request->validated('idempotency_key'),
                $user,
            );
        } catch (CommerceCartValidationException $exception) {
            return $this->fail($exception->getMessage(), 422, ['problems' => $exception->problems]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new CommerceOrderResource($order),
            message: 'تم إنشاء طلب التجارة بانتظار الدفع.',
            status: 201,
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceOrderView($request, $workspace);
        $this->assertCommerceOrder($order, $workspace->id);
        $this->authorize('viewCommerce', $order);

        return $this->ok(new CommerceOrderResource($order->load([
            'customer',
            'commerceCart',
            'items.commerceSeller',
            'sellerAllocations.seller',
            'inventoryReservations',
        ])));
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceOrderCancel($request, $workspace);
        $this->assertCommerceOrder($order, $workspace->id);
        $this->authorize('cancelCommerce', $order);

        try {
            $cancelled = $this->orders->cancelDraft($workspace, $order, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceOrderResource($cancelled), message: 'تم إلغاء طلب التجارة.');
    }

    private function assertCommerceOrder(Order $order, int $workspaceId): void
    {
        if ((int) $order->workspace_id !== $workspaceId || $order->source !== 'commerce') {
            abort(404);
        }
    }

    private function assertSameWorkspace(int|string|null $resourceWorkspaceId, int $workspaceId): void
    {
        abort_unless((int) $resourceWorkspaceId === $workspaceId, 404);
    }

    private function sum(object $result, string $property): string
    {
        return collect($result->lines)
            ->reduce(
                fn (string $total, $line): string => Money::add($total, $line->price->{$property}),
                Money::fromMinor(0),
            );
    }
}
