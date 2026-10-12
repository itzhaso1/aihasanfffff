<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">المدفوعات</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 flex flex-wrap gap-2 text-left">
                <a href="{{ route('workspace.payments.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إنشاء رابط دفع</a>
                <a href="{{ route('workspace.payments.merchant.show') }}" class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm text-white">توثيق التاجر والمدفوعات</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الطلب</th>
                            <th class="px-4 py-3 text-right">المزوّد</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">المبلغ</th>
                            <th class="px-4 py-3 text-right">الرابط</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($payments as $payment)
                            <tr>
                                <td class="px-4 py-3">{{ $payment->order?->order_number ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $payment->provider }}</td>
                                <td class="px-4 py-3">{{ $payment->status }}</td>
                                <td class="px-4 py-3">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</td>
                                <td class="px-4 py-3">
                                    @if($payment->payment_link)
                                        <a href="{{ $payment->payment_link }}" target="_blank" class="text-blue-600">فتح</a>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد مدفوعات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payments->links() }}</div>
        </div>
    </div>
</x-app-layout>
