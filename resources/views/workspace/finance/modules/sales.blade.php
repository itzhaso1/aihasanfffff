@extends('layouts.financial', ['pageTitle' => 'المبيعات'])

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
            <h2 class="text-xl font-bold text-slate-900">وحدة المبيعات</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('workspace.finance.quotes.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    عروض الأسعار
                </a>
                <a href="{{ route('workspace.finance.quotes.create') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    إنشاء عرض سعر
                </a>
                <a href="{{ route('workspace.finance.invoices.create', ['type' => 'sales']) }}" class="rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">
                    إنشاء فاتورة مبيعات
                </a>
                <a href="{{ route('workspace.finance.invoices.index', ['type' => 'sales']) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    كل فواتير المبيعات
                </a>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">عدد الفواتير</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((int) $summary['invoice_count']) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المبيعات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $summary['total_sales'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المحصل</p>
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
            <form method="GET" action="{{ route('workspace.finance.sales.index') }}" class="grid gap-3 md:grid-cols-6">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">بحث</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="رقم الفاتورة / العميل"
                           class="w-full rounded-lg border-slate-300 text-sm">
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
                    <label class="mb-1 block text-xs font-semibold text-slate-600">العميل</label>
                    <select name="customer_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">الكل</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) ($filters['customer_id'] ?? 0) === $customer->id)>{{ $customer->name }}</option>
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
                    <a href="{{ route('workspace.finance.sales.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">إعادة ضبط</a>
                </div>
            </form>
        </article>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">فواتير المبيعات</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-2 py-2 text-right">رقم الفاتورة</th>
                                <th class="px-2 py-2 text-right">العميل</th>
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
                                    <td class="px-2 py-2">{{ $invoice->customer?->name ?: $invoice->customer_name ?: 'عميل نقدي' }}</td>
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
                                <tr><td colspan="8" class="px-2 py-8 text-center text-slate-500">لا توجد فواتير مبيعات مطابقة.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $invoices->links() }}</div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">آخر دفعات المبيعات</h3>
                <div class="space-y-2">
                    @forelse($recentPayments as $payment)
                        <div class="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <p class="font-semibold">{{ $payment->invoice?->invoice_number }} — {{ number_format((float) $payment->amount, 2) }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $payment->payment_date?->format('Y-m-d') }}
                                | {{ $payment->method }}
                                | {{ $payment->treasuryAccount?->name }}
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد دفعات حديثة.</p>
                    @endforelse
                </div>
            </article>
        </div>
    </div>
@endsection
