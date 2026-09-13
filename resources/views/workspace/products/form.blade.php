@php($product = $product ?? null)
<div class="grid gap-4 md:grid-cols-2">
    <div>
        <label class="mb-1 block text-sm">الاسم</label>
        <input name="name" required value="{{ old('name', $product?->name) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">التصنيف</label>
        <select name="category_id" class="w-full rounded-lg border-gray-300">
            <option value="">بدون تصنيف</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $product?->category_id) === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm">SKU</label>
        <input name="sku" required value="{{ old('sku', $product?->sku) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">Slug</label>
        <input name="slug" value="{{ old('slug', $product?->slug) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">السعر</label>
        <input type="number" step="0.01" name="price" required value="{{ old('price', $product?->price) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">سعر العرض</label>
        <input type="number" step="0.01" name="sale_price" value="{{ old('sale_price', $product?->sale_price) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">العملة</label>
        <input name="currency" required value="{{ old('currency', $product?->currency ?? 'USD') }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">المخزون</label>
        <input type="number" name="stock" required value="{{ old('stock', $product?->stock ?? 0) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الحالة</label>
        <select name="status" class="w-full rounded-lg border-gray-300">
            @foreach(['draft','active','inactive','archived'] as $status)
                <option value="{{ $status }}" @selected(old('status', $product?->status ?? 'active') === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-sm">العلامة التجارية</label>
        <input name="brand" value="{{ old('brand', $product?->brand) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">الوزن</label>
        <input type="number" step="0.001" name="weight" value="{{ old('weight', $product?->weight) }}" class="w-full rounded-lg border-gray-300" />
    </div>
    <div>
        <label class="mb-1 block text-sm">صور المنتج</label>
        <input type="file" name="image_files[]" multiple class="w-full rounded-lg border-gray-300" />
    </div>
</div>
<div>
    <label class="mb-1 block text-sm">الوصف</label>
    <textarea name="description" rows="3" class="w-full rounded-lg border-gray-300">{{ old('description', $product?->description) }}</textarea>
</div>
<div>
    <label class="mb-1 block text-sm">Attributes JSON</label>
    <textarea name="attributes_json" rows="4" class="w-full rounded-lg border-gray-300">{{ old('attributes_json', $attributesJson ?? json_encode($product?->attributes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) }}</textarea>
</div>
<div>
    <label class="mb-1 block text-sm">Variants JSON</label>
    <textarea name="variants_json" rows="8" class="w-full rounded-lg border-gray-300">{{ old('variants_json', $variantsJson ?? '[]') }}</textarea>
    <p class="mt-1 text-xs text-gray-500">مثال: [{"name":"Size 42 Black","sku":"SHOE-42-BLK","price":120,"stock":5,"attributes":{"size":"42","color":"black"}}]</p>
</div>
