<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">سلة تجارة #{{ $cart->id }}</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4">
            @include('workspace.partials.nav')
            <a href="{{ route('workspace.commerce.carts.index') }}" class="text-sm text-indigo-700">← سلال التجارة</a>

            <div class="mt-4 grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">الحالة</p><p>{{ $cart->status?->value ?? $cart->status }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">العميل</p><p>{{ $cart->customer?->name ?? '-' }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">العملة</p><p>{{ $cart->currency }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">ينتهي</p><p>{{ $cart->expires_at?->format('Y-m-d H:i') ?? '-' }}</p></div>
            </div>

            @if($cart->convertedOrder)
                <p class="mt-4 text-sm">
                    تم التحويل إلى
                    <a href="{{ route('workspace.commerce.orders.show', $cart->convertedOrder) }}" class="text-indigo-700">
                        الطلب {{ $cart->convertedOrder->order_number }}
                    </a>
                </p>
            @endif

            <section class="mt-6 overflow-x-auto rounded-xl border bg-white">
                <h3 class="border-b px-4 py-3 font-semibold">بنود السلة</h3>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الصنف</th>
                            <th class="px-4 py-3 text-right">البائع</th>
                            <th class="px-4 py-3 text-right">الكمية</th>
                            <th class="px-4 py-3 text-right">سعر السلة</th>
                            <th class="px-4 py-3 text-right">العملة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($cart->items as $item)
                            <tr>
                                <td class="px-4 py-3">{{ $item->item?->name ?? '-' }} @if($item->variant?->name) · {{ $item->variant->name }} @endif</td>
                                <td class="px-4 py-3">{{ $item->seller?->display_name ?: $item->seller?->name ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $item->quantity }}</td>
                                <td class="px-4 py-3">{{ $item->unit_price ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $item->currency ?? $cart->currency }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد بنود.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</x-communication-layout>
