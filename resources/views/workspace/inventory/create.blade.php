<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تسجيل حركة مخزون</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('workspace.inventory.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">المنتج</label>
                        <select name="product_id" required class="w-full rounded-lg border-gray-300">
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} (Stock: {{ $product->stock }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">المتغير (اختياري)</label>
                        <select name="product_variant_id" class="w-full rounded-lg border-gray-300">
                            <option value="">بدون متغير</option>
                            @foreach($variants as $variant)
                                <option value="{{ $variant->id }}">{{ $variant->name ?: $variant->sku }} (Stock: {{ $variant->stock }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">النوع</label>
                        <select name="type" class="w-full rounded-lg border-gray-300">
                            @foreach(['add','remove','reserve','release','adjustment','return'] as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">الكمية</label>
                        <input type="number" min="1" name="quantity" required class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Reference Type</label>
                        <input name="reference_type" class="w-full rounded-lg border-gray-300" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Reference ID</label>
                        <input type="number" name="reference_id" class="w-full rounded-lg border-gray-300" />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm">ملاحظات</label>
                    <textarea name="notes" rows="3" class="w-full rounded-lg border-gray-300"></textarea>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">تنفيذ</button>
            </form>
        </div>
    </div>
</x-communication-layout>
