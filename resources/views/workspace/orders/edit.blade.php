<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تفاصيل الطلب {{ $order->order_number }}</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="rounded-xl border bg-white p-6">
                <p class="text-sm text-gray-500">العميل: {{ $order->customer?->name ?? '-' }}</p>
                <p class="text-sm text-gray-500 mt-1">الإجمالي: {{ number_format((float) $order->total_amount, 2) }} {{ $order->currency }}</p>
                <p class="text-sm text-gray-500 mt-1">الدفع: {{ $order->payment_status }}</p>
            </div>
            <form method="POST" action="{{ route('workspace.orders.update', $order) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                @method('PUT')
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">حالة الطلب</label>
                        <select name="status" class="w-full rounded-lg border-gray-300">
                            @foreach(['draft','confirmed','cancelled','completed'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $order->status) === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">حالة الدفع</label>
                        <select name="payment_status" class="w-full rounded-lg border-gray-300">
                            @foreach(['pending','paid','failed','refunded'] as $status)
                                <option value="{{ $status }}" @selected(old('payment_status', $order->payment_status) === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Fulfillment</label>
                        <select name="fulfillment_status" class="w-full rounded-lg border-gray-300">
                            @foreach(['unfulfilled','processing','fulfilled','cancelled'] as $status)
                                <option value="{{ $status }}" @selected(old('fulfillment_status', $order->fulfillment_status) === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Shipping</label>
                        <select name="shipping_status" class="w-full rounded-lg border-gray-300">
                            @foreach(['not_shipped','processing','shipped','delivered','returned'] as $status)
                                <option value="{{ $status }}" @selected(old('shipping_status', $order->shipping_status) === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ الحالة</button>
            </form>

            <div class="rounded-xl border bg-white p-6">
                <h3 class="font-semibold mb-3">عناصر الطلب</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-right">المنتج</th>
                                <th class="px-3 py-2 text-right">SKU</th>
                                <th class="px-3 py-2 text-right">الكمية</th>
                                <th class="px-3 py-2 text-right">سعر الوحدة</th>
                                <th class="px-3 py-2 text-right">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($order->items as $item)
                                <tr>
                                    <td class="px-3 py-2">{{ $item->product_name }}</td>
                                    <td class="px-3 py-2">{{ $item->sku }}</td>
                                    <td class="px-3 py-2">{{ $item->quantity }}</td>
                                    <td class="px-3 py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format((float) $item->total_amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
