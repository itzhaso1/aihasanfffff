@extends('layouts.financial', ['pageTitle' => 'المنتجات والخدمات'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">المنتجات (إعادة استخدام Product الحالي)</h2>
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3 text-right">المنتج</th>
                        <th class="px-3 py-3 text-right">رمز المنتج (SKU)</th>
                        <th class="px-3 py-3 text-right">السعر</th>
                        <th class="px-3 py-3 text-right">المخزون</th>
                        <th class="px-3 py-3 text-right">الفئة</th>
                        <th class="px-3 py-3 text-right">مبيعات مالية</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($products as $product)
                        <tr>
                            <td class="px-3 py-3 font-semibold">{{ $product->name }}</td>
                            <td class="px-3 py-3">{{ $product->sku }}</td>
                            <td class="px-3 py-3">{{ number_format((float) $product->price, 2) }} {{ $product->currency }}</td>
                            <td class="px-3 py-3">{{ $product->stock }}</td>
                            <td class="px-3 py-3">{{ $product->category?->name ?? '-' }}</td>
                            <td class="px-3 py-3">{{ number_format((float) ($salesByProduct[$product->id] ?? 0), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">لا توجد منتجات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $products->links() }}</div>
    </div>
@endsection
