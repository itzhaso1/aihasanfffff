<?php

namespace App\Services\Finance;

use App\Models\Finance\FinancePriceList;
use App\Models\Finance\FinancePriceListItem;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PriceListService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload, int $actorUserId): FinancePriceList
    {
        return DB::transaction(function () use ($workspace, $payload, $actorUserId): FinancePriceList {
            return FinancePriceList::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => trim((string) $payload['name']),
                'code' => $this->normalizeNullable($payload['code'] ?? null),
                'currency' => strtoupper((string) ($payload['currency'] ?? 'SAR')),
                'status' => 'draft',
                'effective_from' => $payload['effective_from'] ?? null,
                'effective_to' => $payload['effective_to'] ?? null,
                'notes' => $this->normalizeNullable($payload['notes'] ?? null),
                'approved_by' => null,
                'approved_at' => null,
            ]);
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function update(FinancePriceList $priceList, array $payload): FinancePriceList
    {
        if ($priceList->status === 'cancelled') {
            throw new RuntimeException('لا يمكن تعديل قائمة أسعار ملغاة.');
        }

        $priceList->update([
            'name' => trim((string) $payload['name']),
            'code' => $this->normalizeNullable($payload['code'] ?? null),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'SAR')),
            'effective_from' => $payload['effective_from'] ?? null,
            'effective_to' => $payload['effective_to'] ?? null,
            'notes' => $this->normalizeNullable($payload['notes'] ?? null),
        ]);

        return $priceList->refresh();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function addItem(FinancePriceList $priceList, array $payload): FinancePriceListItem
    {
        if ($priceList->status === 'cancelled') {
            throw new RuntimeException('لا يمكن إضافة عناصر إلى قائمة ملغاة.');
        }

        $product = null;
        if (! empty($payload['product_id'])) {
            $product = Product::query()->whereKey((int) $payload['product_id'])->first();
            if (! $product) {
                throw new RuntimeException('المنتج المحدد غير موجود.');
            }
        }

        $name = trim((string) ($payload['product_name'] ?? ''));
        if ($product) {
            $name = $product->name;
        }
        if ($name === '') {
            throw new RuntimeException('اسم المنتج/الخدمة مطلوب.');
        }

        $price = round((float) ($payload['price'] ?? 0), 2);
        if ($price <= 0) {
            throw new RuntimeException('سعر العنصر يجب أن يكون أكبر من صفر.');
        }

        $minQuantity = round((float) ($payload['min_quantity'] ?? 1), 3);
        if ($minQuantity <= 0) {
            throw new RuntimeException('الحد الأدنى للكمية يجب أن يكون أكبر من صفر.');
        }

        return FinancePriceListItem::withoutGlobalScopes()->create([
            'workspace_id' => $priceList->workspace_id,
            'price_list_id' => $priceList->id,
            'product_id' => $product?->id,
            'product_name' => $name,
            'sku' => $product?->sku ?: $this->normalizeNullable($payload['sku'] ?? null),
            'min_quantity' => $minQuantity,
            'price' => $price,
            'tax_rate' => round((float) ($payload['tax_rate'] ?? 0), 2),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'metadata' => null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateItem(FinancePriceListItem $item, array $payload): FinancePriceListItem
    {
        $priceList = $item->priceList()->firstOrFail();
        if ($priceList->status === 'cancelled') {
            throw new RuntimeException('لا يمكن تعديل عنصر في قائمة ملغاة.');
        }

        $price = round((float) ($payload['price'] ?? $item->price), 2);
        $minQuantity = round((float) ($payload['min_quantity'] ?? $item->min_quantity), 3);
        if ($price <= 0 || $minQuantity <= 0) {
            throw new RuntimeException('قيمة السعر والحد الأدنى يجب أن تكون أكبر من صفر.');
        }

        $item->update([
            'product_name' => trim((string) ($payload['product_name'] ?? $item->product_name)),
            'sku' => $this->normalizeNullable($payload['sku'] ?? $item->sku),
            'min_quantity' => $minQuantity,
            'price' => $price,
            'tax_rate' => round((float) ($payload['tax_rate'] ?? $item->tax_rate), 2),
            'is_active' => (bool) ($payload['is_active'] ?? $item->is_active),
        ]);

        return $item->refresh();
    }

    public function deleteItem(FinancePriceListItem $item): void
    {
        $priceList = $item->priceList()->firstOrFail();
        if ($priceList->status === 'approved') {
            throw new RuntimeException('لا يمكن حذف عنصر من قائمة معتمدة. قم بإعادتها إلى مسودة أولاً.');
        }

        $item->delete();
    }

    public function approve(FinancePriceList $priceList, int $actorUserId): FinancePriceList
    {
        if ($priceList->items()->count() === 0) {
            throw new RuntimeException('لا يمكن اعتماد قائمة أسعار بدون عناصر.');
        }

        $priceList->update([
            'status' => 'approved',
            'approved_by' => $actorUserId,
            'approved_at' => now(),
        ]);

        return $priceList->refresh();
    }

    public function markDraft(FinancePriceList $priceList): FinancePriceList
    {
        if ($priceList->status === 'cancelled') {
            throw new RuntimeException('لا يمكن إعادة قائمة ملغاة إلى مسودة.');
        }

        $priceList->update([
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return $priceList->refresh();
    }

    public function cancel(FinancePriceList $priceList): FinancePriceList
    {
        $priceList->update(['status' => 'cancelled']);

        return $priceList->refresh();
    }

    /**
     * Newest approved, currently effective list wins per product_id.
     *
     * @return array<int, array{price:float, tax_rate:float}>
     */
    public function effectivePricesByProductId(int $workspaceId, ?string $onDate = null): array
    {
        $date = $onDate ?: now()->toDateString();

        $lists = FinancePriceList::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'approved')
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->with(['items' => function ($query): void {
                $query->where('is_active', true)->whereNotNull('product_id');
            }])
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($lists as $list) {
            foreach ($list->items as $item) {
                $productId = (int) $item->product_id;
                if ($productId <= 0 || array_key_exists($productId, $map)) {
                    continue;
                }
                $map[$productId] = [
                    'price' => round((float) $item->price, 2),
                    'tax_rate' => round((float) $item->tax_rate, 2),
                ];
            }
        }

        return $map;
    }

    private function normalizeNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
