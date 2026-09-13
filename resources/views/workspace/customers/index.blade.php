<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">العملاء</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 flex items-center justify-between">
                <form method="GET" class="flex gap-2">
                    <input name="search" value="{{ request('search') }}" class="rounded-lg border-gray-300 text-sm" placeholder="بحث..." />
                    <button class="rounded-lg bg-gray-800 px-3 py-2 text-white text-sm">بحث</button>
                </form>
                <a href="{{ route('workspace.customers.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إضافة عميل</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الاسم</th>
                            <th class="px-4 py-3 text-right">النوع</th>
                            <th class="px-4 py-3 text-right">الهاتف</th>
                            <th class="px-4 py-3 text-right">البريد</th>
                            <th class="px-4 py-3 text-right">الرقم الضريبي</th>
                            <th class="px-4 py-3 text-right">الطلبات</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($customers as $customer)
                            <tr>
                                <td class="px-4 py-3">{{ $customer->name }}</td>
                                <td class="px-4 py-3">{{ $customer->partyType() === 'company' ? 'شركة' : 'فرد' }}</td>
                                <td class="px-4 py-3">{{ $customer->phone }}</td>
                                <td class="px-4 py-3">{{ $customer->email ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $customer->vat_number ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $customer->orders_count }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.customers.edit', $customer) }}" class="text-blue-600">تعديل</a>
                                    <form method="POST" action="{{ route('workspace.customers.destroy', $customer) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="mr-3 text-red-600" onclick="return confirm('تأكيد الحذف؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">لا يوجد عملاء.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $customers->links() }}</div>
        </div>
    </div>
</x-communication-layout>
