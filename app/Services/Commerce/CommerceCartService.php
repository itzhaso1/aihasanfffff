<?php

namespace App\Services\Commerce;

use App\Enums\Commerce\CommerceCartStatus;
use App\Events\CommerceCartCreated;
use App\Events\CommerceCartUpdated;
use App\Exceptions\CommerceCartValidationException;
use App\Models\ChatCatalog;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceCartItem;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CommerceCartService
{
    public function __construct(
        private readonly CommerceCartValidationService $validator,
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function create(
        Workspace $workspace,
        ?int $customerId = null,
        ?string $currency = null,
        string $channel = 'chat',
        ?\DateTimeInterface $expiresAt = null,
        array $metadata = [],
        ?User $actor = null,
    ): CommerceCart {
        $cart = $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $customerId,
            $currency,
            $channel,
            $expiresAt,
            $metadata,
        ): CommerceCart {
            if ($customerId !== null && ! Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($customerId)
                ->exists()) {
                throw new InvalidArgumentException('Customer does not belong to the current workspace.');
            }

            $catalog = ChatCatalog::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->first();
            if (! $catalog) {
                throw new RuntimeException('Commerce catalog is not configured.');
            }

            $resolvedCurrency = strtoupper((string) ($currency ?: $catalog->default_currency));
            if (! preg_match('/^[A-Z]{3}$/', $resolvedCurrency)) {
                throw new InvalidArgumentException('Cart currency must be a three-letter code.');
            }

            $resolvedChannel = strtolower(trim($channel));
            if (! in_array($resolvedChannel, ['chat', 'whatsapp', 'instagram', 'web', 'marketplace'], true)) {
                throw new InvalidArgumentException('Commerce channel is invalid.');
            }

            $expires = $expiresAt
                ? Carbon::instance($expiresAt)
                : now()->addDay();
            if ($expires->isPast()) {
                throw new InvalidArgumentException('Cart expiry must be in the future.');
            }

            $cart = CommerceCart::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customerId,
                'commerce_seller_id' => null,
                'currency' => $resolvedCurrency,
                'status' => CommerceCartStatus::Active,
                'channel' => $resolvedChannel,
                'metadata' => $metadata,
                'expires_at' => $expires,
            ]);

            return $cart->fresh(['customer', 'items']) ?? $cart;
        });

        $this->audit->log(
            action: 'commerce.cart.created',
            entityType: CommerceCart::class,
            entityId: (int) $cart->id,
            actor: $actor,
            meta: ['channel' => $cart->channel],
            workspaceId: (int) $workspace->id,
        );
        event(new CommerceCartCreated($cart));

        return $cart;
    }

    /**
     * @param  array<string,mixed>  $selectedOptions
     * @param  array<string,mixed>  $metadata
     */
    public function addItem(
        Workspace $workspace,
        CommerceCart $cart,
        int $catalogItemId,
        ?int $catalogVariantId,
        int $quantity,
        array $selectedOptions = [],
        array $metadata = [],
        ?User $actor = null,
    ): CommerceCart {
        $updated = $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $cart,
            $catalogItemId,
            $catalogVariantId,
            $quantity,
            $selectedOptions,
            $metadata,
        ): CommerceCart {
            return DB::transaction(function () use (
                $workspace,
                $cart,
                $catalogItemId,
                $catalogVariantId,
                $quantity,
                $selectedOptions,
                $metadata,
            ): CommerceCart {
                $locked = $this->lockActiveCart($workspace, $cart);
                if ($quantity < 1) {
                    throw new InvalidArgumentException('Quantity must be at least 1.');
                }

                CommerceCartItem::query()->create([
                    'workspace_id' => $workspace->id,
                    'commerce_cart_id' => $locked->id,
                    'chat_catalog_item_id' => $catalogItemId,
                    'chat_catalog_item_variant_id' => $catalogVariantId,
                    'commerce_seller_id' => null,
                    'quantity' => $quantity,
                    'unit_price' => null,
                    'currency' => null,
                    'price_snapshot' => null,
                    'selected_options' => $selectedOptions,
                    'metadata' => $metadata,
                ]);

                return $this->validateAndRefreshSnapshots($workspace, $locked, true);
            });
        });

        $this->auditUpdated($updated, $actor, 'item_added');
        event(new CommerceCartUpdated($updated));

        return $updated;
    }

    public function updateItemQuantity(
        Workspace $workspace,
        CommerceCart $cart,
        CommerceCartItem $cartItem,
        int $quantity,
        ?User $actor = null,
    ): CommerceCart {
        $updated = $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $cart,
            $cartItem,
            $quantity,
        ): CommerceCart {
            return DB::transaction(function () use ($workspace, $cart, $cartItem, $quantity): CommerceCart {
                $locked = $this->lockActiveCart($workspace, $cart);
                if ($quantity < 1) {
                    throw new InvalidArgumentException('Quantity must be at least 1.');
                }

                $line = CommerceCartItem::query()
                    ->where('commerce_cart_id', $locked->id)
                    ->whereKey($cartItem->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $line->update(['quantity' => $quantity]);

                return $this->validateAndRefreshSnapshots($workspace, $locked, true);
            });
        });

        $this->auditUpdated($updated, $actor, 'quantity_updated');
        event(new CommerceCartUpdated($updated));

        return $updated;
    }

    public function removeItem(
        Workspace $workspace,
        CommerceCart $cart,
        CommerceCartItem $cartItem,
        ?User $actor = null,
    ): CommerceCart {
        $updated = $this->workspaceContext->runFor($workspace, function () use ($workspace, $cart, $cartItem): CommerceCart {
            return DB::transaction(function () use ($workspace, $cart, $cartItem): CommerceCart {
                $locked = $this->lockActiveCart($workspace, $cart);
                CommerceCartItem::query()
                    ->where('commerce_cart_id', $locked->id)
                    ->whereKey($cartItem->id)
                    ->delete();

                return $locked->fresh(['customer', 'items']) ?? $locked;
            });
        });

        $this->auditUpdated($updated, $actor, 'item_removed');
        event(new CommerceCartUpdated($updated));

        return $updated;
    }

    public function clear(Workspace $workspace, CommerceCart $cart, ?User $actor = null): CommerceCart
    {
        $updated = $this->workspaceContext->runFor($workspace, function () use ($workspace, $cart): CommerceCart {
            return DB::transaction(function () use ($workspace, $cart): CommerceCart {
                $locked = $this->lockActiveCart($workspace, $cart);
                $locked->items()->delete();

                return $locked->fresh(['customer', 'items']) ?? $locked;
            });
        });

        $this->auditUpdated($updated, $actor, 'cleared');
        event(new CommerceCartUpdated($updated));

        return $updated;
    }

    public function validate(Workspace $workspace, CommerceCart $cart): CommerceCartValidationResult
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $cart): CommerceCartValidationResult {
            $fresh = CommerceCart::query()
                ->with('items')
                ->whereKey($cart->id)
                ->firstOrFail();

            return $this->validator->validate($workspace, $fresh);
        });
    }

    public function cancel(Workspace $workspace, CommerceCart $cart, ?User $actor = null): CommerceCart
    {
        $updated = $this->workspaceContext->runFor($workspace, function () use ($workspace, $cart): CommerceCart {
            return DB::transaction(function () use ($workspace, $cart): CommerceCart {
                $locked = CommerceCart::query()
                    ->whereKey($cart->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($locked->status === CommerceCartStatus::Converted) {
                    throw new RuntimeException('Converted carts cannot be cancelled.');
                }
                $this->assertCartWorkspace($workspace, $locked);
                $locked->update(['status' => CommerceCartStatus::Cancelled]);

                return $locked->fresh(['customer', 'items']) ?? $locked;
            });
        });

        $this->auditUpdated($updated, $actor, 'cancelled');
        event(new CommerceCartUpdated($updated));

        return $updated;
    }

    private function lockActiveCart(Workspace $workspace, CommerceCart $cart): CommerceCart
    {
        $locked = CommerceCart::query()
            ->whereKey($cart->id)
            ->lockForUpdate()
            ->firstOrFail();
        $this->assertCartWorkspace($workspace, $locked);

        if ($locked->isExpired()) {
            if ($locked->status === CommerceCartStatus::Active) {
                $locked->update(['status' => CommerceCartStatus::Expired]);
            }
            throw new RuntimeException('Cart has expired.');
        }
        if ($locked->status !== CommerceCartStatus::Active) {
            throw new RuntimeException('Cart is not active.');
        }

        return $locked;
    }

    private function assertCartWorkspace(Workspace $workspace, CommerceCart $cart): void
    {
        if ((int) $cart->workspace_id !== (int) $workspace->id) {
            abort(404);
        }
    }

    private function validateAndRefreshSnapshots(
        Workspace $workspace,
        CommerceCart $cart,
        bool $lockCatalogRows,
    ): CommerceCart {
        $fresh = $cart->fresh(['items']) ?? $cart;
        $result = $this->validator->validate($workspace, $fresh, $lockCatalogRows);
        if (! $result->isValid()) {
            throw new CommerceCartValidationException($result->problems);
        }

        foreach ($result->lines as $line) {
            $line->cartItem->update([
                'commerce_seller_id' => $line->seller->id,
                'unit_price' => $line->price->unitPrice,
                'currency' => $line->price->currency,
                'price_snapshot' => $line->price->toSnapshot(),
            ]);
        }

        return $cart->fresh(['customer', 'items.seller', 'items.item', 'items.variant']) ?? $cart;
    }

    private function auditUpdated(CommerceCart $cart, ?User $actor, string $action): void
    {
        $this->audit->log(
            action: 'commerce.cart.'.$action,
            entityType: CommerceCart::class,
            entityId: (int) $cart->id,
            actor: $actor,
            meta: ['item_count' => $cart->relationLoaded('items') ? $cart->items->count() : null],
            workspaceId: (int) $cart->workspace_id,
        );
    }
}
