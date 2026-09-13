<?php

namespace App\Services\Finance;

use App\Models\Finance\FinancePurchaseOrder;
use App\Models\Finance\FinancePurchaseOrderItem;
use App\Models\Finance\FinanceSupplier;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\InventoryService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseOrderService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly InventoryService $inventoryService,
        private readonly TaxService $taxService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload, int $actorUserId): FinancePurchaseOrder
    {
        $supplier = FinanceSupplier::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey((int) ($payload['supplier_id'] ?? 0))
            ->first();
        if (! $supplier) {
            throw new RuntimeException('Supplier is invalid for this workspace.');
        }

        $items = $this->normalizeItems($workspace->id, $payload['items'] ?? []);
        if ($items === []) {
            throw new RuntimeException('Purchase order requires at least one item.');
        }

        $totals = $this->totals($items);

        return DB::transaction(function () use ($workspace, $payload, $supplier, $items, $totals, $actorUserId): FinancePurchaseOrder {
            $order = FinancePurchaseOrder::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'supplier_id' => $supplier->id,
                'po_number' => $payload['po_number'] ?? $this->nextNumber($workspace->id),
                'status' => 'draft',
                'order_date' => $payload['order_date'] ?? now()->toDateString(),
                'expected_date' => $payload['expected_date'] ?? null,
                'currency' => $payload['currency'] ?? 'SAR',
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'total' => $totals['total'],
                'notes' => $payload['notes'] ?? null,
                'created_by' => $actorUserId,
            ]);

            foreach ($items as $item) {
                FinancePurchaseOrderItem::withoutGlobalScopes()->create([
                    'workspace_id' => $workspace->id,
                    'purchase_order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'received_quantity' => 0,
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'tax_amount' => $item['tax_amount'],
                    'taxable_amount' => $item['taxable_amount'],
                    'total' => $item['total'],
                ]);
            }

            return $order->load('items');
        });
    }

    public function submit(FinancePurchaseOrder $order): FinancePurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw new RuntimeException('Only draft purchase orders can be submitted.');
        }

        $order->update(['status' => 'sent']);

        return $order->fresh('items');
    }

    /**
     * @param  array<int, array{id:int,quantity:int|float|string}>  $receipts
     */
    public function receive(FinancePurchaseOrder $order, array $receipts, int $actorUserId): FinancePurchaseOrder
    {
        if (! in_array($order->status, ['sent', 'partial_received'], true)) {
            throw new RuntimeException('This purchase order cannot receive stock in its current status.');
        }

        return DB::transaction(function () use ($order, $receipts, $actorUserId): FinancePurchaseOrder {
            $locked = FinancePurchaseOrder::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->load('items');

            foreach ($receipts as $receipt) {
                $item = $locked->items->firstWhere('id', (int) ($receipt['id'] ?? 0));
                if (! $item) {
                    throw new RuntimeException('Purchase order item is invalid.');
                }

                $qty = max(0, (int) round((float) ($receipt['quantity'] ?? 0)));
                if ($qty < 1) {
                    continue;
                }

                $remaining = (int) round((float) $item->quantity) - (int) $item->received_quantity;
                if ($qty > $remaining) {
                    throw new RuntimeException('Received quantity exceeds ordered quantity.');
                }

                $item->update(['received_quantity' => (int) $item->received_quantity + $qty]);

                if ($item->product_id) {
                    $this->inventoryService->adjustStock(
                        productId: (int) $item->product_id,
                        variantId: null,
                        type: 'add',
                        quantity: $qty,
                        actor: $actorUserId > 0 ? User::query()->find($actorUserId) : null,
                        referenceType: FinancePurchaseOrder::class,
                        referenceId: (int) $locked->id,
                        notes: 'purchase_order_receipt',
                    );
                }
            }

            $locked->load('items');
            $fullyReceived = $locked->items->every(fn (FinancePurchaseOrderItem $item): bool => (int) $item->received_quantity >= (int) round((float) $item->quantity));
            $locked->update(['status' => $fullyReceived ? 'received' : 'partial_received']);

            return $locked->fresh('items');
        });
    }

    public function convertToBill(Workspace $workspace, FinancePurchaseOrder $order, int $actorUserId): mixed
    {
        if (! in_array($order->status, ['received', 'partial_received', 'sent'], true)) {
            throw new RuntimeException('Purchase order cannot be billed in its current status.');
        }

        $this->financialPeriodGuardService->assertDateIsOpen(
            workspaceId: (int) $workspace->id,
            date: now()->toDateString(),
            context: 'تحويل أمر شراء إلى فاتورة'
        );

        $order->loadMissing('items');
        $alreadyReceived = $order->items->sum(fn (FinancePurchaseOrderItem $item): int => (int) $item->received_quantity) > 0;
        $invoice = $this->invoiceService->create($workspace, [
            'type' => 'purchase',
            'supplier_id' => $order->supplier_id,
            'issue_date' => now()->toDateString(),
            'currency' => $order->currency,
            'invoice_status' => 'issued',
            'notes' => 'From PO '.$order->po_number,
            'skip_inventory' => $alreadyReceived,
            'items' => $order->items->map(fn (FinancePurchaseOrderItem $item): array => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount' => 0,
                'tax_rate' => $item->tax_rate,
            ])->all(),
        ], $actorUserId);

        $order->update([
            'status' => 'billed',
            'finance_invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }

    /**
     * @param  array<int, mixed>  $rawItems
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(int $workspaceId, array $rawItems): array
    {
        $items = [];
        foreach ($rawItems as $raw) {
            $name = trim((string) ($raw['product_name'] ?? ''));
            $quantity = max(0.001, (float) ($raw['quantity'] ?? 0));
            $unitPrice = max(0, (float) ($raw['unit_price'] ?? 0));
            $taxRate = (float) ($raw['tax_rate'] ?? 0);
            $calc = $this->taxService->calculateLine($quantity, $unitPrice, 0, 'standard', $taxRate);
            $productId = isset($raw['product_id']) ? (int) $raw['product_id'] : null;
            if ($productId) {
                $exists = Product::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($productId)
                    ->exists();
                if (! $exists) {
                    throw new RuntimeException('Product is invalid for this workspace.');
                }
                $name = $name !== '' ? $name : (string) Product::withoutGlobalScopes()->find($productId)?->name;
            }
            if ($name === '') {
                continue;
            }
            $items[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'quantity' => $quantity,
                'unit_price' => Money::round($unitPrice),
                'tax_rate' => Money::round($taxRate),
                'tax_amount' => $calc['tax_amount'],
                'taxable_amount' => $calc['taxable_amount'],
                'total' => $calc['total'],
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal:float,tax:float,total:float}
     */
    private function totals(array $items): array
    {
        $subtotal = '0.00';
        $tax = '0.00';
        $total = '0.00';
        foreach ($items as $item) {
            $subtotal = Money::add($subtotal, $item['taxable_amount']);
            $tax = Money::add($tax, $item['tax_amount']);
            $total = Money::add($total, $item['total']);
        }

        return [
            'subtotal' => Money::round($subtotal),
            'tax' => Money::round($tax),
            'total' => Money::round($total),
        ];
    }

    private function nextNumber(int $workspaceId): string
    {
        $count = FinancePurchaseOrder::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->count();

        return 'PO-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
