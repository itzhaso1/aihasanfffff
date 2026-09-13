<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Support\Money\Money;
use RuntimeException;

class InventoryAccountingService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Extra balanced journal lines for inventory / COGS. Empty when the invoice has no tracked stock.
     *
     * @return array<int, array{account_id:int,debit:float,credit:float,description:?string,entity_type:string,entity_id:int}>
     */
    public function journalLines(FinanceInvoice $invoice): array
    {
        $workspaceId = (int) $invoice->workspace_id;
        $inventory = $this->chartOfAccountsService->byCode('1300', $workspaceId);
        $cogs = $this->chartOfAccountsService->byCode('5300', $workspaceId);
        $expense = $this->chartOfAccountsService->byCode('5900', $workspaceId);

        if (! $inventory || ! $cogs || ! $expense) {
            throw new RuntimeException('دليل الحسابات يفتقد حساب المخزون أو تكلفة المبيعات.');
        }

        $invoice->loadMissing('items');
        $lines = [];

        if ($invoice->type === 'sales') {
            $cogsTotal = '0.00';
            foreach ($invoice->items as $item) {
                $cogsTotal = Money::add($cogsTotal, $this->lineCogs($item, $workspaceId));
            }

            if (Money::isPositive($cogsTotal)) {
                $lines[] = $this->line($cogs->id, $cogsTotal, '0', 'COGS', $invoice);
                $lines[] = $this->line($inventory->id, '0', $cogsTotal, 'Inventory reduction', $invoice);
            }

            return $lines;
        }

        $inventoryAmount = '0.00';
        foreach ($invoice->items as $item) {
            if ($this->tracksInventory($item, $workspaceId)) {
                $inventoryAmount = Money::add($inventoryAmount, $item->taxable_amount);
            }
        }

        if (Money::isPositive($inventoryAmount)) {
            $lines[] = $this->line($inventory->id, $inventoryAmount, '0', 'Inventory receipt', $invoice);
        }

        return $lines;
    }

    /**
     * Taxable amount that should hit the general expense account (non-inventory purchases).
     */
    public function purchaseExpenseAmount(FinanceInvoice $invoice): string
    {
        if ($invoice->type !== 'purchase') {
            return '0.00';
        }

        $invoice->loadMissing('items');
        $expenseAmount = '0.00';
        foreach ($invoice->items as $item) {
            if (! $this->tracksInventory($item, (int) $invoice->workspace_id)) {
                $expenseAmount = Money::add($expenseAmount, $item->taxable_amount);
            }
        }

        return $expenseAmount;
    }

    public function applyStock(FinanceInvoice $invoice, int $actorUserId): void
    {
        $actor = $actorUserId > 0 ? User::query()->find($actorUserId) : null;
        $invoice->loadMissing('items');

        foreach ($invoice->items as $item) {
            if (! $this->tracksInventory($item, (int) $invoice->workspace_id)) {
                continue;
            }

            $quantity = $this->stockQuantity($item);
            if ($quantity < 1) {
                continue;
            }

            $type = $invoice->type === 'sales' ? 'remove' : 'add';
            $this->inventoryService->adjustStock(
                productId: (int) $item->product_id,
                variantId: null,
                type: $type,
                quantity: $quantity,
                actor: $actor,
                referenceType: FinanceInvoice::class,
                referenceId: (int) $invoice->id,
                notes: $type === 'remove' ? 'invoice_sale' : 'invoice_purchase',
            );
        }
    }

    public function reverseStock(FinanceInvoice $invoice, int $actorUserId): void
    {
        $alreadyReversed = InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->whereIn('notes', ['invoice_sale_reversal', 'invoice_purchase_reversal'])
            ->exists();
        if ($alreadyReversed) {
            return;
        }

        $movements = InventoryMovement::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->whereIn('notes', ['invoice_sale', 'invoice_purchase'])
            ->orderBy('id')
            ->get();

        $actor = $actorUserId > 0 ? User::query()->find($actorUserId) : null;

        foreach ($movements as $movement) {
            $reverseType = $movement->type === 'remove' ? 'return' : 'remove';
            $this->inventoryService->adjustStock(
                productId: (int) $movement->product_id,
                variantId: $movement->product_variant_id ? (int) $movement->product_variant_id : null,
                type: $reverseType,
                quantity: (int) $movement->quantity,
                actor: $actor,
                referenceType: FinanceInvoice::class,
                referenceId: (int) $invoice->id,
                notes: $movement->notes === 'invoice_sale' ? 'invoice_sale_reversal' : 'invoice_purchase_reversal',
            );
        }
    }

    private function lineCogs(FinanceInvoiceItem $item, int $workspaceId): string
    {
        if (! $this->tracksInventory($item, $workspaceId)) {
            return '0.00';
        }

        $product = $this->product($item, $workspaceId);
        $unitCost = $product?->cost_price ?? 0;

        return Money::mul($unitCost, $item->quantity);
    }

    private function tracksInventory(FinanceInvoiceItem $item, int $workspaceId): bool
    {
        if (! $item->product_id) {
            return false;
        }

        $product = $this->product($item, $workspaceId);

        return (bool) ($product?->inventory_tracking);
    }

    private function product(FinanceInvoiceItem $item, int $workspaceId): ?Product
    {
        if ($item->relationLoaded('product') && $item->product) {
            return $item->product;
        }

        return Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($item->product_id)
            ->first();
    }

    private function stockQuantity(FinanceInvoiceItem $item): int
    {
        return max(0, (int) round((float) $item->quantity));
    }

    /**
     * @return array{account_id:int,debit:float,credit:float,description:string,entity_type:string,entity_id:int}
     */
    private function line(int $accountId, string $debit, string $credit, string $description, FinanceInvoice $invoice): array
    {
        return [
            'account_id' => $accountId,
            'debit' => Money::round($debit),
            'credit' => Money::round($credit),
            'description' => $description,
            'entity_type' => FinanceInvoice::class,
            'entity_id' => $invoice->id,
        ];
    }
}
