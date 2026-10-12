<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">طلب {{ $order->order_number }}</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4">
            @include('workspace.partials.nav')
            <a href="{{ route('workspace.commerce.orders.index') }}" class="text-sm text-indigo-700">← طلبات التجارة</a>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">الحالة</p><p>{{ $order->commerce_status?->value ?? $order->commerce_status }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">الدفع</p><p>{{ $order->payment_status }}</p></div>
                <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-500">الإجمالي</p><p>{{ $order->total_amount }} {{ $order->currency }}</p></div>
            </div>
            @if($order->commerceCart)
                <p class="mt-4 text-sm">
                    <a href="{{ route('workspace.commerce.carts.show', $order->commerceCart) }}" class="text-indigo-700">عرض السلة المصدر</a>
                </p>
            @endif
            <section class="mt-6 overflow-x-auto rounded-xl border bg-white">
                <h3 class="border-b px-4 py-3 font-semibold">البنود المثبتة</h3>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-right">الصنف</th><th class="px-4 py-3 text-right">البائع</th><th class="px-4 py-3 text-right">الكمية</th><th class="px-4 py-3 text-right">السعر</th><th class="px-4 py-3 text-right">الإجمالي</th></tr></thead>
                    <tbody class="divide-y">
                        @foreach($order->items as $item)
                            <tr>
                                <td class="px-4 py-3">{{ $item->product_name }} @if($item->variant_name) · {{ $item->variant_name }} @endif</td>
                                <td class="px-4 py-3">{{ $item->seller_display_name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $item->quantity }}</td>
                                <td class="px-4 py-3">{{ $item->unit_price }} {{ $item->currency }}</td>
                                <td class="px-4 py-3">{{ $item->total_amount }} {{ $item->currency }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
            <section class="mt-6 overflow-x-auto rounded-xl border bg-white">
                <h3 class="border-b px-4 py-3 font-semibold">تخصيصات البائعين</h3>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-right">البائع</th><th class="px-4 py-3 text-right">الإجمالي</th><th class="px-4 py-3 text-right">رسوم المنصة</th><th class="px-4 py-3 text-right">الصافي</th><th class="px-4 py-3 text-right">التسوية</th></tr></thead>
                    <tbody class="divide-y">
                        @forelse($order->sellerAllocations as $allocation)
                            <tr>
                                <td class="px-4 py-3">{{ $allocation->seller?->display_name ?: $allocation->seller?->name }}</td>
                                <td class="px-4 py-3">{{ $allocation->gross_amount }} {{ $allocation->currency }}</td>
                                <td class="px-4 py-3">{{ $allocation->platform_fee_amount }} {{ $allocation->currency }}</td>
                                <td class="px-4 py-3">{{ $allocation->seller_net_amount }} {{ $allocation->currency }}</td>
                                <td class="px-4 py-3">{{ $allocation->settlement_status?->value ?? $allocation->settlement_status }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد تخصيصات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
            <section class="mt-6 overflow-x-auto rounded-xl border bg-white">
                <h3 class="border-b px-4 py-3 font-semibold">حجوزات المخزون</h3>
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-3 text-right">البند</th><th class="px-4 py-3 text-right">النوع</th><th class="px-4 py-3 text-right">الكمية</th><th class="px-4 py-3 text-right">الحالة</th><th class="px-4 py-3 text-right">تنتهي</th></tr></thead>
                    <tbody class="divide-y">
                        @forelse($order->inventoryReservations as $reservation)
                            <tr>
                                <td class="px-4 py-3">#{{ $reservation->order_item_id }}</td>
                                <td class="px-4 py-3">{{ $reservation->reservation_kind }}</td>
                                <td class="px-4 py-3">{{ $reservation->quantity }}</td>
                                <td class="px-4 py-3">{{ $reservation->status?->value ?? $reservation->status }}</td>
                                <td class="px-4 py-3">{{ $reservation->expires_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد حجوزات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</x-communication-layout>
