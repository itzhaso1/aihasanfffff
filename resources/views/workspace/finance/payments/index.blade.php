@extends('layouts.financial', ['pageTitle' => 'الدفعات'])

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-xl font-black text-slate-900">سجل الدفعات</h2>
                <p class="text-sm text-slate-500">تحصيل فواتير المالية عبر محرك الدفعات الحالي. حالة المستند منفصلة عن حالة الدفع.</p>
            </div>
            <a href="{{ route('workspace.finance.exports.download', 'payments') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">CSV</a>
        </div>

        <form method="GET" class="flex flex-wrap gap-2">
            <input name="search" value="{{ request('search') }}" class="rounded-lg border-slate-300 text-sm" placeholder="بحث بالمرجع أو رقم الفاتورة أو العميل">
            <input name="reference" value="{{ request('reference') }}" class="rounded-lg border-slate-300 text-sm" placeholder="المرجع">
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الحالات</option>
                <option value="posted" @selected(request('status') === 'posted')>مرحلة</option>
                <option value="reversed" @selected(request('status') === 'reversed')>معكوسة</option>
            </select>
            <select name="method" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الطرق</option>
                @foreach(['cash' => 'نقد', 'bank_transfer' => 'تحويل', 'card' => 'بطاقة', 'online' => 'إلكتروني', 'other' => 'أخرى'] as $method => $label)
                    <option value="{{ $method }}" @selected(request('method') === $method)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-slate-300 text-sm" aria-label="من تاريخ">
            <input type="date" name="to" value="{{ request('to') }}" class="rounded-lg border-slate-300 text-sm" aria-label="إلى تاريخ">
            <button class="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white">تطبيق</button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-right">التاريخ</th>
                        <th class="px-4 py-3 text-right">الفاتورة</th>
                        <th class="px-4 py-3 text-right">العميل</th>
                        <th class="px-4 py-3 text-right">المبلغ</th>
                        <th class="px-4 py-3 text-right">الطريقة</th>
                        <th class="px-4 py-3 text-right">الحالة</th>
                        <th class="px-4 py-3 text-right">الإيصال</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($payments as $payment)
                        <tr>
                            <td class="px-4 py-3">{{ optional($payment->payment_date)->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                @if($payment->invoice)
                                    <a class="font-semibold text-[#06C2A4]" href="{{ route('workspace.finance.invoices.show', $payment->invoice) }}">{{ $payment->invoice->invoice_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $payment->invoice?->customer?->name ?: ($payment->invoice?->customer_name ?: '—') }}</td>
                            <td class="px-4 py-3 font-semibold">{{ number_format((float) $payment->amount, 2) }}</td>
                            <td class="px-4 py-3">{{ $payment->method }}</td>
                            <td class="px-4 py-3">{{ $payment->status === 'reversed' ? 'معكوسة' : 'مرحلة' }}</td>
                            <td class="px-4 py-3">
                                @if($payment->receipt)
                                    <a class="text-xs font-semibold text-[#06C2A4]" href="{{ route('workspace.finance.receipts.show', $payment->receipt) }}">{{ $payment->receipt->receipt_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">لا توجد دفعات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $payments->links() }}</div>
    </div>
@endsection
