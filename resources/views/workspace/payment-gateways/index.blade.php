<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">بوابات الدفع</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 text-left">
                <a href="{{ route('workspace.payment-gateways.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إضافة بوابة</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">المزوّد</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">آخر تحقق</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($gateways as $gateway)
                            <tr>
                                <td class="px-4 py-3">{{ $gateway->provider }}</td>
                                <td class="px-4 py-3">{{ $gateway->status }}</td>
                                <td class="px-4 py-3">{{ $gateway->last_verified_at ?? '-' }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.payment-gateways.edit', $gateway) }}" class="text-blue-600">تعديل</a>
                                    <form method="POST" action="{{ route('workspace.payment-gateways.destroy', $gateway) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="mr-3 text-red-600" onclick="return confirm('تأكيد الحذف؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا توجد بوابات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $gateways->links() }}</div>
        </div>
    </div>
</x-app-layout>
