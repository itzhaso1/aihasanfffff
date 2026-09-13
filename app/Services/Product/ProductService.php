<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     * @return Product
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $product = Product::query()->create([
                ...$data,
                'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            ]);

            foreach (($data['variants'] ?? []) as $variant) {
                $product->variants()->create([
                    ...$variant,
                    'sku' => $variant['sku'] ?? $product->sku.'-'.Str::upper(Str::random(4)),
                ]);
            }

            return $product->fresh(['variants']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $product->update([
                ...$data,
                'slug' => $data['slug'] ?? $product->slug,
            ]);

            if (array_key_exists('variants', $data)) {
                $incomingIds = collect($data['variants'])
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all();

                $product->variants()
                    ->whereNotIn('id', $incomingIds)
                    ->delete();

                foreach ($data['variants'] as $variantData) {
                    if (! empty($variantData['id'])) {
                        ProductVariant::query()
                            ->where('product_id', $product->id)
                            ->whereKey($variantData['id'])
                            ->firstOrFail()
                            ->update($variantData);
                    } else {
                        $product->variants()->create([
                            ...$variantData,
                            'sku' => $variantData['sku'] ?? $product->sku.'-'.Str::upper(Str::random(4)),
                        ]);
                    }
                }
            }

            return $product->fresh(['variants']);
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product->variants()->delete();
            $product->delete();
        });
    }
}
