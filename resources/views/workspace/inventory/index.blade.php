<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">حركات المخزون</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 text-left">
                <a href="{{ route('workspace.inventory.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">تسجيل حركة جديدة</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">المنتج</th>
                            <th class="px-4 py-3 text-right">المتغير</th>
                            <th class="px-4 py-3 text-right">النوع</th>
                            <th class="px-4 py-3 text-right">الكمية</th>
                            <th class="px-4 py-3 text-right">قبل</th>
                            <th class="px-4 py-3 text-right">بعد</th>
                            <th class="px-4 py-3 text-right">التاريخ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($movements as $movement)
                            <tr>
                                <td class="px-4 py-3">{{ $movement->product?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $movement->variant?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $movement->type }}</td>
                                <td class="px-4 py-3">{{ $movement->quantity }}</td>
                                <td class="px-4 py-3">{{ $movement->before_quantity }}</td>
                                <td class="px-4 py-3">{{ $movement->after_quantity }}</td>
                                <td class="px-4 py-3">{{ $movement->created_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">لا توجد حركات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $movements->links() }}</div>
        </div>
    </div>
</x-communication-layout>
