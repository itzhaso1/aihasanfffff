<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">المنتجات</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <form method="GET" class="flex gap-2">
                    <input name="search" value="{{ request('search') }}" placeholder="بحث بالاسم أو SKU" class="rounded-lg border-gray-300 text-sm" />
                    <select name="status" class="rounded-lg border-gray-300 text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(['draft','active','inactive','archived'] as $status)
                            <option value="{{ $status }}" @selected(request('status')===$status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm text-white">تصفية</button>
                </form>
                <a href="{{ route('workspace.products.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إضافة منتج</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">المنتج</th>
                            <th class="px-4 py-3 text-right">التصنيف</th>
                            <th class="px-4 py-3 text-right">SKU</th>
                            <th class="px-4 py-3 text-right">السعر</th>
                            <th class="px-4 py-3 text-right">المخزون</th>
                            <th class="px-4 py-3 text-right">المتغيرات</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($products as $product)
                            <tr>
                                <td class="px-4 py-3">{{ $product->name }}</td>
                                <td class="px-4 py-3">{{ $product->category?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $product->sku }}</td>
                                <td class="px-4 py-3">{{ number_format((float) $product->price, 2) }} {{ $product->currency }}</td>
                                <td class="px-4 py-3">{{ $product->stock }}</td>
                                <td class="px-4 py-3">{{ $product->variants_count }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.products.edit', $product) }}" class="text-blue-600">تعديل</a>
                                    <form method="POST" action="{{ route('workspace.products.destroy', $product) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="mr-3 text-red-600" onclick="return confirm('تأكيد الحذف؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">لا توجد منتجات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $products->links() }}</div>
        </div>
    </div>
</x-communication-layout>
