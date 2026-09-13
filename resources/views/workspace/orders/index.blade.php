<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">الطلبات</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 flex items-center justify-between">
                <form method="GET" class="flex gap-2">
                    <input name="search" value="{{ request('search') }}" placeholder="رقم الطلب" class="rounded-lg border-gray-300 text-sm" />
                    <select name="status" class="rounded-lg border-gray-300 text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(['draft','confirmed','cancelled','completed'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm text-white">بحث</button>
                </form>
                <a href="{{ route('workspace.orders.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إنشاء طلب</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">رقم الطلب</th>
                            <th class="px-4 py-3 text-right">العميل</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">الدفع</th>
                            <th class="px-4 py-3 text-right">الإجمالي</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($orders as $order)
                            <tr>
                                <td class="px-4 py-3">{{ $order->order_number }}</td>
                                <td class="px-4 py-3">{{ $order->customer?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $order->status }}</td>
                                <td class="px-4 py-3">{{ $order->payment_status }}</td>
                                <td class="px-4 py-3">{{ number_format((float)$order->total_amount, 2) }} {{ $order->currency }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.orders.edit', $order) }}" class="text-blue-600">تفاصيل/تحديث</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">لا توجد طلبات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        </div>
    </div>
</x-app-layout>
