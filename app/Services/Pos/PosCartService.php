<?php

namespace App\Services\Pos;

use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Session;
use RuntimeException;

class PosCartService
{
    public function __construct(
        private readonly PosOrderService $posOrderService,
    ) {}

    /**
     * @return array{
     *     items: array<string, array<string, mixed>>,
     *     customer_id: int|null,
     *     dining_table_id: int|null,
     *     discount_amount: float,
     *     notes: string|null
     * }
     */
    public function get(Workspace $workspace): array
    {
        $cart = Session::get($this->sessionKey($workspace->id));

        if (! is_array($cart)) {
            return $this->emptyCart();
        }

        return [
            'items' => is_array($cart['items'] ?? null) ? $cart['items'] : [],
            'customer_id' => isset($cart['customer_id']) ? (int) $cart['customer_id'] : null,
            'dining_table_id' => isset($cart['dining_table_id']) ? (int) $cart['dining_table_id'] : null,
            'discount_amount' => (float) ($cart['discount_amount'] ?? 0),
            'notes' => isset($cart['notes']) ? (string) $cart['notes'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(Workspace $workspace, int $posMenuItemId, int $quantity = 1): array
    {
        $quantity = max(1, $quantity);
        $menuItem = $this->resolveMenuItem($workspace->id, $posMenuItemId);
        $cart = $this->get($workspace);
        $key = $this->itemKey($posMenuItemId);

        if (isset($cart['items'][$key])) {
            $cart['items'][$key]['quantity'] = (int) $cart['items'][$key]['quantity'] + $quantity;
        } else {
            $cart['items'][$key] = [
                'key' => $key,
                'pos_menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'unit_price' => (float) $menuItem->price,
                'currency' => (string) ($menuItem->currency ?: 'USD'),
                'quantity' => $quantity,
            ];
        }

        $this->put($workspace->id, $cart);

        return $this->summary($workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateQty(Workspace $workspace, string $key, int $quantity): array
    {
        $cart = $this->get($workspace);
        if (! isset($cart['items'][$key])) {
            throw new RuntimeException('عنصر السلة غير موجود.');
        }

        if ($quantity <= 0) {
            unset($cart['items'][$key]);
        } else {
            $cart['items'][$key]['quantity'] = $quantity;
        }

        $this->put($workspace->id, $cart);

        return $this->summary($workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeItem(Workspace $workspace, string $key): array
    {
        $cart = $this->get($workspace);
        unset($cart['items'][$key]);
        $this->put($workspace->id, $cart);

        return $this->summary($workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function setCustomer(Workspace $workspace, ?int $customerId): array
    {
        $cart = $this->get($workspace);
        $cart['customer_id'] = $customerId;
        $this->put($workspace->id, $cart);

        return $this->summary($workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function setDiscount(Workspace $workspace, float $discountAmount): array
    {
        $cart = $this->get($workspace);
        $cart['discount_amount'] = max(0, round($discountAmount, 2));
        $this->put($workspace->id, $cart);

        return $this->summary($workspace);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function setMeta(Workspace $workspace, array $attributes): array
    {
        $cart = $this->get($workspace);

        if (array_key_exists('customer_id', $attributes)) {
            $cart['customer_id'] = $attributes['customer_id'] !== null ? (int) $attributes['customer_id'] : null;
        }
        if (array_key_exists('dining_table_id', $attributes)) {
            $cart['dining_table_id'] = $attributes['dining_table_id'] !== null ? (int) $attributes['dining_table_id'] : null;
        }
        if (array_key_exists('discount_amount', $attributes)) {
            $cart['discount_amount'] = max(0, round((float) $attributes['discount_amount'], 2));
        }
        if (array_key_exists('notes', $attributes)) {
            $cart['notes'] = $attributes['notes'] !== null ? (string) $attributes['notes'] : null;
        }

        $this->put($workspace->id, $cart);

        return $this->summary($workspace);
    }

    public function clear(Workspace $workspace): void
    {
        Session::forget($this->sessionKey($workspace->id));
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     customer_id: int|null,
     *     dining_table_id: int|null,
     *     discount_amount: float,
     *     notes: string|null,
     *     item_count: int,
     *     subtotal: float,
     *     total: float,
     *     currency: string
     * }
     */
    public function summary(Workspace $workspace): array
    {
        $cart = $this->get($workspace);
        $items = array_values($cart['items']);
        $subtotal = round(array_sum(array_map(
            static fn (array $line): float => (float) $line['quantity'] * (float) $line['unit_price'],
            $items
        )), 2);
        $discount = max(0, (float) $cart['discount_amount']);
        $currencies = array_values(array_unique(array_filter(array_map(
            static fn (array $line): string => strtoupper((string) ($line['currency'] ?? '')),
            $items
        ))));

        return [
            'items' => $items,
            'customer_id' => $cart['customer_id'],
            'dining_table_id' => $cart['dining_table_id'],
            'discount_amount' => $discount,
            'notes' => $cart['notes'],
            'item_count' => count($items),
            'subtotal' => $subtotal,
            'total' => max(0, round($subtotal - $discount, 2)),
            'currency' => count($currencies) === 1 ? $currencies[0] : (count($currencies) === 0 ? 'USD' : 'MIX'),
        ];
    }

    /**
     * Checkout session cart via existing PosOrderService (does not invent a parallel order path).
     */
    public function checkout(Workspace $workspace, ?User $actor): \App\Models\Order
    {
        $summary = $this->summary($workspace);
        if ($summary['item_count'] === 0) {
            throw new RuntimeException('السلة فارغة.');
        }

        $payload = [
            'customer_id' => $summary['customer_id'],
            'dining_table_id' => $summary['dining_table_id'],
            'order_type' => $summary['dining_table_id'] ? 'table' : 'takeaway',
            'discount_amount' => $summary['discount_amount'],
            'notes' => $summary['notes'],
            'items' => array_map(static fn (array $line): array => [
                'pos_menu_item_id' => (int) $line['pos_menu_item_id'],
                'quantity' => (int) $line['quantity'],
            ], $summary['items']),
        ];

        $order = $this->posOrderService->createPosOrder($workspace, $payload, $actor);
        $this->clear($workspace);

        return $order;
    }

    private function resolveMenuItem(int $workspaceId, int $posMenuItemId): PosMenuItem
    {
        $menuItem = PosMenuItem::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($posMenuItemId)
            ->where('is_active', true)
            ->first();

        if (! $menuItem) {
            throw new RuntimeException('صنف الكاشير غير صالح أو غير مفعل.');
        }

        return $menuItem;
    }

    /**
     * @param  array{
     *     items: array<string, array<string, mixed>>,
     *     customer_id: int|null,
     *     dining_table_id: int|null,
     *     discount_amount: float,
     *     notes: string|null
     * }  $cart
     */
    private function put(int $workspaceId, array $cart): void
    {
        Session::put($this->sessionKey($workspaceId), $cart);
    }

    private function sessionKey(int $workspaceId): string
    {
        return 'pos_cart.'.$workspaceId;
    }

    private function itemKey(int $posMenuItemId): string
    {
        return 'item_'.$posMenuItemId;
    }

    /**
     * @return array{
     *     items: array<string, array<string, mixed>>,
     *     customer_id: int|null,
     *     dining_table_id: int|null,
     *     discount_amount: float,
     *     notes: string|null
     * }
     */
    private function emptyCart(): array
    {
        return [
            'items' => [],
            'customer_id' => null,
            'dining_table_id' => null,
            'discount_amount' => 0.0,
            'notes' => null,
        ];
    }
}
