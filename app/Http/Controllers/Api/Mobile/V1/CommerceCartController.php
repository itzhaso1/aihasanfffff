<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Exceptions\CommerceCartValidationException;
use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesCommerceOrders;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Commerce\StoreCommerceCartItemRequest;
use App\Http\Requests\Commerce\StoreCommerceCartRequest;
use App\Http\Requests\Commerce\UpdateCommerceCartItemRequest;
use App\Http\Resources\Mobile\CommerceCartResource;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceCartItem;
use App\Services\Commerce\CommerceCartService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class CommerceCartController extends MobileController
{
    use AuthorizesCommerceOrders;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly CommerceCartService $carts,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function store(StoreCommerceCartRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceCartManage($request, $workspace);
        $data = $request->validated();

        try {
            $cart = $this->carts->create(
                workspace: $workspace,
                customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                currency: $data['currency'] ?? null,
                channel: $data['channel'] ?? 'chat',
                expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
                metadata: $data['metadata'] ?? [],
                actor: $user,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceCartResource($cart), message: 'تم إنشاء سلة التجارة.', status: 201);
    }

    public function show(Request $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceCartView($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);

        return $this->ok(new CommerceCartResource($cart->load(['customer', 'items.seller', 'items.item', 'items.variant'])));
    }

    public function addItem(StoreCommerceCartItemRequest $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceCartManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);
        $data = $request->validated();

        try {
            $updated = $this->carts->addItem(
                workspace: $workspace,
                cart: $cart,
                catalogItemId: (int) $data['chat_catalog_item_id'],
                catalogVariantId: isset($data['chat_catalog_item_variant_id'])
                    ? (int) $data['chat_catalog_item_variant_id']
                    : null,
                quantity: (int) $data['quantity'],
                selectedOptions: $data['selected_options'] ?? [],
                metadata: $data['metadata'] ?? [],
                actor: $user,
            );
        } catch (CommerceCartValidationException $exception) {
            return $this->fail($exception->getMessage(), 422, ['problems' => $exception->problems]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceCartResource($updated), message: 'تمت إضافة الصنف إلى السلة.');
    }

    public function updateItem(
        UpdateCommerceCartItemRequest $request,
        CommerceCart $cart,
        CommerceCartItem $cartItem,
    ): JsonResponse {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceCartManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);
        $this->assertSameWorkspace($cartItem->workspace_id, $workspace->id);
        if ((int) $cartItem->commerce_cart_id !== (int) $cart->id) {
            abort(404);
        }

        try {
            $updated = $this->carts->updateItemQuantity(
                $workspace,
                $cart,
                $cartItem,
                (int) $request->validated('quantity'),
                $user,
            );
        } catch (CommerceCartValidationException $exception) {
            return $this->fail($exception->getMessage(), 422, ['problems' => $exception->problems]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceCartResource($updated), message: 'تم تحديث كمية السلة.');
    }

    public function removeItem(Request $request, CommerceCart $cart, CommerceCartItem $cartItem): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceCartManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);
        $this->assertSameWorkspace($cartItem->workspace_id, $workspace->id);
        if ((int) $cartItem->commerce_cart_id !== (int) $cart->id) {
            abort(404);
        }

        try {
            $updated = $this->carts->removeItem($workspace, $cart, $cartItem, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceCartResource($updated), message: 'تم حذف الصنف من السلة.');
    }

    public function clear(Request $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceCartManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);

        try {
            $updated = $this->carts->clear($workspace, $cart, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceCartResource($updated), message: 'تم إفراغ السلة.');
    }

    public function validateCart(Request $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceCartManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);

        $result = $this->carts->validate($workspace, $cart);

        return $this->ok([
            'valid' => $result->isValid(),
            'problems' => $result->problems,
            'lines' => collect($result->lines)->map(fn ($line) => [
                'cart_item_id' => $line->cartItem->id,
                'currency' => $line->price->currency,
                'subtotal' => $line->price->subtotal,
                'tax_amount' => $line->price->taxAmount,
                'total' => $line->price->total,
                'seller_id' => $line->seller->id,
            ])->values()->all(),
        ]);
    }

    public function cancel(Request $request, CommerceCart $cart): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeCommerceCartManage($request, $workspace);
        $this->assertSameWorkspace($cart->workspace_id, $workspace->id);

        try {
            $updated = $this->carts->cancel($workspace, $cart, $user);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceCartResource($updated), message: 'تم إلغاء السلة.');
    }

    private function assertSameWorkspace(int|string|null $resourceWorkspaceId, int $workspaceId): void
    {
        abort_unless((int) $resourceWorkspaceId === $workspaceId, 404);
    }
}
