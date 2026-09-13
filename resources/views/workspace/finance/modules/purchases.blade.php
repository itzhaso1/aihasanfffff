@extends('layouts.financial', ['pageTitle' => 'المشتريات'])

@section('content')
    @php
        $invoiceStatusLabels = [
            'draft' => 'مسودة',
            'issued' => 'معتمدة',
            'cancelled' => 'ملغاة',
        ];
        $paymentStatusLabels = [
            'unpaid' => 'غير مدفوعة',
            'partial' => 'مدفوعة جزئيًا',
            'paid' => 'مدفوعة بالكامل',
            'overdue' => 'متأخرة',
        ];
    @endphp
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xl font-bold text-slate-900">وحدة المشتريات</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('workspace.finance.invoices.create', ['type' => 'purchase']) }}" class="rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">
                    إنشاء فاتورة شراء
                </a>
                <a href="{{ route('workspace.finance.invoices.index', ['type' => 'purchase']) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    كل فواتير الشراء
                </a>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">عدد الفواتير</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((int) $summary['invoice_count']) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المشتريات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $summary['total_purchases'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المسدد</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $summary['total_paid'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المستحق</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $summary['total_due'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">فواتير متأخرة/غير مدفوعة</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((int) $summary['overdue_count']) }} / {{ number_format((int) $summary['unpaid_count']) }}</p>
            </article>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('workspace.finance.purchases.index') }}" class="grid gap-3 md:grid-cols-6">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">بحث</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="رقم الفاتورة / المورد" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">حالة الفاتورة</label>
                    <select name="invoice_status" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">الكل</option>
                        @foreach(['draft','issued','cancelled'] as $status)
                            <option value="{{ $status }}" @selected(($filters['invoice_status'] ?? '') === $status)>{{ $invoiceStatusLabels[$status] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">حالة الدفع</label>
                    <select name="payment_status" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">الكل</option>
                        @foreach(['unpaid','partial','paid','overdue'] as $status)
                            <option value="{{ $status }}" @selected(($filters['payment_status'] ?? '') === $status)>{{ $paymentStatusLabels[$status] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">المورد</label>
                    <select name="supplier_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">الكل</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((int) ($filters['supplier_id'] ?? 0) === $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">من</label>
                        <input type="date" name="from" value="{{ $filters['from'] }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">إلى</label>
                        <input type="date" name="to" value="{{ $filters['to'] }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                </div>
                <div class="md:col-span-6 flex items-center gap-2">
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white">تطبيق الفلترة</button>
                    <a href="{{ route('workspace.finance.purchases.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">إعادة ضبط</a>
                </div>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">فواتير الشراء</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-2 py-2 text-right">رقم الفاتورة</th>
                            <th class="px-2 py-2 text-right">المورد</th>
                            <th class="px-2 py-2 text-right">حالة الفاتورة</th>
                            <th class="px-2 py-2 text-right">حالة الدفع</th>
                            <th class="px-2 py-2 text-right">الإجمالي</th>
                            <th class="px-2 py-2 text-right">المتبقي</th>
                            <th class="px-2 py-2 text-right">التاريخ</th>
                            <th class="px-2 py-2 text-left">إجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($invoices as $invoice)
                            @php
                                $invoiceState = $invoice->invoice_status
                                    ?? (in_array($invoice->status, ['draft', 'cancelled'], true) ? $invoice->status : 'issued');
                                $paymentState = $invoice->payment_status
                                    ?? (in_array($invoice->status, ['unpaid', 'partial', 'paid', 'overdue'], true) ? $invoice->status : 'unpaid');
                            @endphp
                            <tr>
                                <td class="px-2 py-2 font-semibold">{{ $invoice->invoice_number }}</td>
                                <td class="px-2 py-2">{{ $invoice->supplier?->name ?: '—' }}</td>
                                <td class="px-2 py-2">{{ $invoiceStatusLabels[$invoiceState] ?? $invoiceState }}</td>
                                <td class="px-2 py-2">{{ $paymentStatusLabels[$paymentState] ?? $paymentState }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $invoice->total, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $invoice->amount_due, 2) }}</td>
                                <td class="px-2 py-2">{{ $invoice->issue_date?->format('Y-m-d') }}</td>
                                <td class="px-2 py-2">
                                    <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">فتح</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-2 py-8 text-center text-slate-500">لا توجد فواتير شراء مطابقة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $invoices->links() }}</div>
        </article>
    </div>
@endsection
