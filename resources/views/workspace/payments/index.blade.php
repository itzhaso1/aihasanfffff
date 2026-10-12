<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">المدفوعات</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <p class="mb-4 text-sm text-slate-600">سجل تاريخي للمدفوعات السابقة. إنشاء روابط الدفع وبوابات الدفع للعملاء ملغى.</p>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الطلب</th>
                            <th class="px-4 py-3 text-right">المزوّد</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($payments as $payment)
                            <tr>
                                <td class="px-4 py-3">{{ $payment->order?->order_number ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $payment->provider }}</td>
                                <td class="px-4 py-3">{{ $payment->status }}</td>
                                <td class="px-4 py-3">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا توجد مدفوعات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payments->links() }}</div>
        </div>
    </div>
</x-app-layout>
