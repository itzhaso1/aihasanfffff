<?php

namespace App\Services\Product;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class DigitalDownloadService
{
    /**
     * Issue a temporary signed download URL for a digital order item.
     * Asset path is never exposed as a public listing URL.
     */
    public function issueSignedDownloadUrl(OrderItem $orderItem, int $expiresMinutes = 30): string
    {
        $product = $this->resolveDigitalProduct($orderItem);

        if (! $product->digital_asset_path) {
            throw new RuntimeException('لا يوجد ملف رقمي مرتبط بهذا العنصر.');
        }

        $limit = $product->download_limit;
        if ($limit !== null && $limit >= 0) {
            $downloads = (int) data_get($orderItem->order?->metadata, 'digital_downloads.'.$orderItem->id.'.count', 0);
            if ($downloads >= $limit) {
                throw new RuntimeException('تم استنفاذ حد التحميل لهذا العنصر.');
            }
        }

        return URL::temporarySignedRoute(
            'downloads.digital',
            now()->addMinutes(max(1, $expiresMinutes)),
            ['orderItem' => $orderItem->id]
        );
    }

    public function resolveDigitalProduct(OrderItem $orderItem): Product
    {
        if (! $orderItem->product_id) {
            throw new RuntimeException('عنصر الطلب غير مرتبط بمنتج رقمي.');
        }

        $product = Product::withoutGlobalScopes()
            ->where('workspace_id', $orderItem->workspace_id)
            ->whereKey($orderItem->product_id)
            ->first();

        if (! $product || $product->product_kind !== 'digital') {
            throw new RuntimeException('المنتج ليس منتجًا رقميًا.');
        }

        return $product;
    }
}
