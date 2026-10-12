@php
    $oldProducts = old('products', []);
    $selectedProductIds = collect(is_array($oldProducts) ? $oldProducts : [])
        ->filter(fn ($product): bool => is_array($product) && isset($product['id']))
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->all();
@endphp

<section class="rounded-xl border border-slate-200 bg-slate-50 p-5" dir="rtl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-bold text-slate-900">المنتجات لهذا العمل</h2>
            <p class="mt-1 text-sm text-slate-600">
                اختر المنتجات التي سيعمل عليها العامل فقط. يمكنك ترك حدود السعر فارغة لاستخدام حدود الكتالوج الحالية.
            </p>
        </div>
        <span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700">
            نطاق الارتباط
        </span>
    </div>

    @if ($products->isEmpty())
        <div class="mt-4 rounded-lg border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-600">
            لا توجد منتجات منشورة ومتاحة للتعيين حاليًا.
        </div>
    @else
        <div class="mt-4 grid gap-3 md:grid-cols-2">
            @foreach ($products as $index => $product)
                @php
                    $selected = in_array((int) $product->id, $selectedProductIds, true);
                    $basePrice = $product->price !== null
                        ? \App\Support\Money\Money::of((string) $product->price).' '.($product->currency ?: 'SAR')
                        : 'غير محدد';
                    $minimumPrice = $product->worker_min_price !== null
                        ? \App\Support\Money\Money::of((string) $product->worker_min_price)
                        : null;
                    $maximumPrice = $product->worker_max_price !== null
                        ? \App\Support\Money\Money::of((string) $product->worker_max_price)
                        : null;
                @endphp

                <div
                    class="worker-product-row rounded-xl border border-slate-200 bg-white p-4"
                    data-worker-product-row
                >
                    <div class="flex items-start gap-3">
                        <input
                            id="worker-product-{{ $product->id }}"
                            type="checkbox"
                            name="products[{{ $index }}][id]"
                            value="{{ $product->id }}"
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                            data-worker-product-toggle
                            @checked($selected)
                        />
                        <label for="worker-product-{{ $product->id }}" class="min-w-0 flex-1 cursor-pointer">
                            <span class="flex items-start gap-3">
                                @if ($product->image_path)
                                    <img
                                        src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}"
                                        alt=""
                                        class="h-12 w-12 rounded-lg object-cover"
                                    />
                                @else
                                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700">
                                        <span aria-hidden="true">▦</span>
                                    </span>
                                @endif
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-900">{{ $product->name }}</span>
                                    <span class="mt-1 block text-xs text-slate-500">
                                        السعر الأساسي: {{ $basePrice }}
                                    </span>
                                    @if ($product->short_description ?: $product->description)
                                        <span class="mt-1 block text-xs text-slate-500">
                                            {{ \Illuminate\Support\Str::limit($product->short_description ?: $product->description, 100) }}
                                        </span>
                                    @endif
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="mt-3 grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2">
                        <div>
                            <label for="worker-product-{{ $product->id }}-minimum" class="text-xs font-semibold text-slate-600">
                                الحد الأدنى لهذا العمل
                            </label>
                            <input
                                id="worker-product-{{ $product->id }}-minimum"
                                type="number"
                                name="products[{{ $index }}][minimum_selling_price]"
                                value="{{ old("products.{$index}.minimum_selling_price", '') }}"
                                min="0"
                                step="0.01"
                                placeholder="{{ $minimumPrice ?? 'غير محدد' }}"
                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                data-worker-product-price
                                @disabled(! $selected)
                            />
                        </div>
                        <div>
                            <label for="worker-product-{{ $product->id }}-maximum" class="text-xs font-semibold text-slate-600">
                                الحد الأعلى لهذا العمل
                            </label>
                            <input
                                id="worker-product-{{ $product->id }}-maximum"
                                type="number"
                                name="products[{{ $index }}][maximum_selling_price]"
                                value="{{ old("products.{$index}.maximum_selling_price", '') }}"
                                min="0"
                                step="0.01"
                                placeholder="{{ $maximumPrice ?? 'غير محدد' }}"
                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                data-worker-product-price
                                @disabled(! $selected)
                            />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>

<script>
    document.querySelectorAll('[data-worker-product-row]').forEach((row) => {
        const toggle = row.querySelector('[data-worker-product-toggle]');
        const priceFields = row.querySelectorAll('[data-worker-product-price]');

        const syncProductState = () => {
            priceFields.forEach((field) => {
                field.disabled = !toggle.checked;
            });
            row.classList.toggle('border-teal-400', toggle.checked);
            row.classList.toggle('ring-1', toggle.checked);
            row.classList.toggle('ring-teal-100', toggle.checked);
        };

        toggle.addEventListener('change', syncProductState);
        syncProductState();
    });
</script>
