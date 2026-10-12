<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">طلبات التجارة</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            <p class="mb-4 text-sm text-gray-600">طلبات المنتجات والمخزون فقط. لا تتضمن هذه الصفحة طلبات الكاشير أو فواتير المالية.</p>
            <form method="GET" class="mb-4 flex gap-2">
                <select name="status" class="rounded border-gray-300 text-sm">
                    <option value="">كل الحالات</option>
                    <option value="pending_payment" @selected(request('status') === 'pending_payment')>بانتظار الدفع</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>ملغي</option>
                </select>
                <button class="rounded bg-gray-800 px-3 py-2 text-sm text-white">تصفية</button>
            </form>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الرقم</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">العميل</th>
                            <th class="px-4 py-3 text-right">الإجمالي</th>
                            <th class="px-4 py-3 text-right">البنود</th>
                            <th class="px-4 py-3 text-right">الحجوزات</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($orders as $order)
                            <tr>
                                <td class="px-4 py-3">{{ $order->order_number }}</td>
                                <td class="px-4 py-3">{{ $order->commerce_status?->value ?? $order->commerce_status }}</td>
                                <td class="px-4 py-3">{{ $order->customer?->name ?? 'ضيف' }}</td>
                                <td class="px-4 py-3">{{ $order->total_amount }} {{ $order->currency }}</td>
                                <td class="px-4 py-3">{{ $order->items_count }}</td>
                                <td class="px-4 py-3">{{ $order->inventory_reservations_count }}</td>
                                <td class="px-4 py-3"><a class="text-indigo-700" href="{{ route('workspace.commerce.orders.show', $order) }}">عرض</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">لا توجد طلبات تجارة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        </div>
    </div>
</x-communication-layout>
