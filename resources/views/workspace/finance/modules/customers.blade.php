@extends('layouts.financial', ['pageTitle' => 'عملاء النظام المالي'])

@section('content')
    @php
        $outstandingByCustomer = $outstandingByCustomer ?? [];
        $invoiceCountByCustomer = $invoiceCountByCustomer ?? [];
        $partyTypeLabels = [
            'individual' => 'فرد',
            'company' => 'شركة',
        ];
    @endphp
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl font-bold text-slate-900">العملاء (إعادة استخدام Customer الحالي)</h2>
            <a href="{{ route('workspace.customers.create') }}" class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إضافة عميل</a>
        </div>
        <p class="text-sm text-slate-500">الرصيد المستحق محسوب من الفواتير الصادرة والمدفوعات والإشعارات، وليس من الحقل المخزن customers.balance.</p>
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3 text-right">الاسم</th>
                        <th class="px-3 py-3 text-right">النوع</th>
                        <th class="px-3 py-3 text-right">الهاتف</th>
                        <th class="px-3 py-3 text-right">الرقم الضريبي</th>
                        <th class="px-3 py-3 text-right">طلبات</th>
                        <th class="px-3 py-3 text-right">فواتير مالية</th>
                        <th class="px-3 py-3 text-right">المستحق</th>
                        <th class="px-3 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($customers as $customer)
                        <tr>
                            <td class="px-3 py-3 font-semibold">{{ $customer->name }}</td>
                            <td class="px-3 py-3">{{ $partyTypeLabels[$customer->partyType()] ?? $customer->partyType() }}</td>
                            <td class="px-3 py-3">{{ $customer->phone }}</td>
                            <td class="px-3 py-3">{{ $customer->vat_number ?: '-' }}</td>
                            <td class="px-3 py-3">{{ $customer->orders_count }}</td>
                            <td class="px-3 py-3">{{ $invoiceCountByCustomer[$customer->id] ?? 0 }}</td>
                            <td class="px-3 py-3 font-semibold">{{ number_format((float) ($outstandingByCustomer[$customer->id] ?? 0), 2) }}</td>
                            <td class="px-3 py-3">
                                <a href="{{ route('workspace.customers.edit', $customer) }}" class="text-[#06C2A4] hover:underline">تعديل البيانات المالية</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-8 text-center text-slate-500">لا يوجد عملاء.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $customers->links() }}</div>
    </div>
@endsection
