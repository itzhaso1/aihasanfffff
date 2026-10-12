<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">سلال التجارة</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4">
            @include('workspace.partials.nav')
            <div class="mb-4 flex items-center justify-between">
                <a href="{{ route('workspace.commerce.orders.index') }}" class="text-sm text-indigo-700">طلبات التجارة</a>
                <form method="GET" class="flex gap-2">
                    <select name="status" class="rounded border text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(['active', 'converted', 'abandoned', 'expired', 'cancelled'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="rounded bg-indigo-600 px-3 py-1 text-sm text-white">تصفية</button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">#</th>
                            <th class="px-4 py-3 text-right">العميل</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">العملة</th>
                            <th class="px-4 py-3 text-right">البنود</th>
                            <th class="px-4 py-3 text-right">ينتهي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($carts as $cart)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('workspace.commerce.carts.show', $cart) }}" class="text-indigo-700">#{{ $cart->id }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $cart->customer?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $cart->status?->value ?? $cart->status }}</td>
                                <td class="px-4 py-3">{{ $cart->currency }}</td>
                                <td class="px-4 py-3">{{ $cart->items_count }}</td>
                                <td class="px-4 py-3">{{ $cart->expires_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">لا توجد سلال.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $carts->links() }}</div>
        </div>
    </div>
</x-communication-layout>
