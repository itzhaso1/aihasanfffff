@extends('layouts.financial', ['pageTitle' => 'الإيصالات'])

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-xl font-black text-slate-900">إيصالات العملاء</h2>
                <p class="text-sm text-slate-500">كل إيصال مرتبط بدفعة مالية مسجّلة. المبالغ تُقرأ من الدفعة وليس من إدخال يدوي.</p>
            </div>
            <a href="{{ route('workspace.finance.exports.download', 'payments') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">تصدير الدفعات CSV</a>
        </div>

        <form method="GET" class="flex flex-wrap gap-2">
            <input name="search" value="{{ request('search') }}" class="rounded-lg border-slate-300 text-sm" placeholder="بحث برقم الإيصال أو المرجع أو العميل">
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الحالات</option>
                <option value="posted" @selected(request('status') === 'posted')>معتمد</option>
                <option value="voided" @selected(request('status') === 'voided')>ملغى</option>
            </select>
            <select name="method" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الطرق</option>
                @foreach(['cash' => 'نقد', 'bank_transfer' => 'تحويل', 'card' => 'بطاقة', 'online' => 'إلكتروني', 'other' => 'أخرى'] as $method => $label)
                    <option value="{{ $method }}" @selected(request('method') === $method)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-slate-300 text-sm" aria-label="من تاريخ">
            <input type="date" name="to" value="{{ request('to') }}" class="rounded-lg border-slate-300 text-sm" aria-label="إلى تاريخ">
            <button class="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white">بحث</button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-right">رقم الإيصال</th>
                        <th class="px-4 py-3 text-right">العميل</th>
                        <th class="px-4 py-3 text-right">الفاتورة</th>
                        <th class="px-4 py-3 text-right">التاريخ</th>
                        <th class="px-4 py-3 text-right">المبلغ</th>
                        <th class="px-4 py-3 text-right">الحالة</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($receipts as $receipt)
                        <tr>
                            <td class="px-4 py-3 font-semibold">{{ $receipt->receipt_number }}</td>
                            <td class="px-4 py-3">{{ $receipt->customer?->name ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $receipt->invoice?->invoice_number }}</td>
                            <td class="px-4 py-3">{{ optional($receipt->payment_date)->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">{{ number_format((float) ($receipt->payment?->amount ?? $receipt->amount), 2) }} {{ $receipt->currency }}</td>
                            <td class="px-4 py-3">{{ $receipt->status === 'voided' ? 'ملغى' : 'معتمد' }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('workspace.finance.receipts.show', $receipt) }}" class="text-xs font-semibold text-[#06C2A4]">عرض</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">لا توجد إيصالات بعد. تُنشأ تلقائياً عند تسجيل دفعة على فاتورة مبيعات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $receipts->links() }}</div>
    </div>
@endsection
