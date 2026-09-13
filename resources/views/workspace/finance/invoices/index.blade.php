@extends('layouts.financial', ['pageTitle' => 'الفواتير'])

@section('content')
    @php
        $labels = \App\Support\Finance\InvoicePresentation::lifecycleLabels();
        $pipeline = $pipeline ?? [];
        $totals = $totals ?? ['total' => 0, 'due' => 0, 'overdue' => 0];
        $sort = request('sort', 'id');
        $direction = request('direction', 'desc');
        $nextDirection = $direction === 'asc' ? 'desc' : 'asc';
        $sortUrl = function (string $column) use ($nextDirection) {
            return request()->fullUrlWithQuery(['sort' => $column, 'direction' => request('sort') === $column ? $nextDirection : 'desc']);
        };
        $tabUrl = function (?string $lifecycle) {
            return request()->fullUrlWithQuery(['lifecycle' => $lifecycle]);
        };
    @endphp
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-xl font-black text-slate-900">مركز الفوترة</h2>
                <p class="text-sm text-slate-500">مسودة → مرسلة → مدفوعة / متأخرة. الضرائب والخصومات والمتبقي واضحة قبل أي إجراء.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.billing.dashboard') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">لوحة التحصيل</a>
                <a href="{{ route('workspace.finance.exports.download', ['dataset' => 'invoices', 'type' => request('type')]) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">CSV</a>
                <a href="{{ route('workspace.finance.invoices.create') }}" class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إنشاء فاتورة</a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            @include('workspace.finance.partials.kpi-card', ['label' => 'إجمالي الفواتير المعروضة', 'value' => $totals['total'], 'tone' => 'indigo'])
            @include('workspace.finance.partials.kpi-card', ['label' => 'المتبقي للتحصيل', 'value' => $totals['due'], 'tone' => 'amber'])
            @include('workspace.finance.partials.kpi-card', ['label' => 'المتأخر داخل التصفية', 'value' => $totals['overdue'], 'tone' => 'rose'])
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1">
            <a href="{{ $tabUrl(null) }}" class="shrink-0 rounded-full px-3 py-1.5 text-xs font-bold {{ request('lifecycle') === null || request('lifecycle') === '' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200' }}">
                الكل ({{ $pipeline['all'] ?? 0 }})
            </a>
            @foreach($labels as $key => $label)
                <a href="{{ $tabUrl($key) }}" class="shrink-0 rounded-full px-3 py-1.5 text-xs font-bold {{ request('lifecycle') === $key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200' }}">
                    {{ $label }} ({{ $pipeline[$key] ?? 0 }})
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('workspace.finance.invoices.index') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
            <input type="hidden" name="lifecycle" value="{{ request('lifecycle') }}">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="بحث برقم الفاتورة أو الاسم" class="rounded-lg border-slate-300 text-sm sm:col-span-2">
            <select name="type" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الأنواع</option>
                <option value="sales" @selected(request('type') === 'sales')>مبيعات</option>
                <option value="purchase" @selected(request('type') === 'purchase')>مشتريات</option>
            </select>
            <select name="customer_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل العملاء</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((string) request('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
            <select name="project_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل المشاريع</option>
                @foreach($projects ?? [] as $project)
                    <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
            <select name="contract_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل العقود</option>
                @foreach($contracts ?? [] as $contract)
                    <option value="{{ $contract->id }}" @selected((string) request('contract_id') === (string) $contract->id)>{{ $contract->contract_number }}</option>
                @endforeach
            </select>
            <select name="payment_method" class="rounded-lg border-slate-300 text-sm">
                <option value="">طريقة الدفع</option>
                @foreach(['cash' => 'نقد', 'bank_transfer' => 'تحويل', 'card' => 'بطاقة', 'other' => 'أخرى'] as $method => $label)
                    <option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-slate-300 text-sm" aria-label="من تاريخ">
            <input type="date" name="to" value="{{ request('to') }}" class="rounded-lg border-slate-300 text-sm" aria-label="إلى تاريخ">
            <input type="text" name="currency" value="{{ request('currency') }}" maxlength="3" placeholder="العملة" class="rounded-lg border-slate-300 text-sm">
            <div class="flex gap-2 xl:col-span-2">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تطبيق الفلاتر</button>
                <a href="{{ route('workspace.finance.invoices.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">إعادة ضبط</a>
            </div>
        </form>

        <div class="space-y-3 md:hidden">
            @forelse($invoices as $invoice)
                @php $life = \App\Support\Finance\InvoicePresentation::lifecycle($invoice); @endphp
                <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="block rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-bold text-slate-900">{{ $invoice->invoice_number }}</p>
                            <p class="text-xs text-slate-500">{{ $invoice->customer?->name ?? $invoice->customer_name ?? $invoice->supplier?->name }}</p>
                        </div>
                        @include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\InvoicePresentation::label($life), 'class' => \App\Support\Finance\InvoicePresentation::badgeClass($life)])
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <p class="text-[11px] text-slate-500">الإجمالي</p>
                            <p class="font-bold">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] text-slate-500">المتبقي</p>
                            <p class="font-bold {{ (float) $invoice->amount_due > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ number_format((float) $invoice->amount_due, 2) }}</p>
                        </div>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-500">إصدار {{ optional($invoice->issue_date)->format('Y-m-d') }} · استحقاق {{ optional($invoice->due_date)->format('Y-m-d') ?: '—' }}</p>
                </a>
            @empty
                @include('workspace.finance.partials.empty-state', [
                    'title' => 'لا توجد فواتير مطابقة',
                    'body' => 'غيّر الفلاتر أو أنشئ أول فاتورة لهذه المساحة.',
                    'actionHref' => route('workspace.finance.invoices.create'),
                    'actionLabel' => 'إنشاء فاتورة',
                ])
            @endforelse
        </div>

        <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-3 py-3 text-right font-semibold"><a href="{{ $sortUrl('invoice_number') }}" class="hover:underline">رقم الفاتورة</a></th>
                            <th class="px-3 py-3 text-right font-semibold">العميل / المورد</th>
                            <th class="px-3 py-3 text-right font-semibold"><a href="{{ $sortUrl('issue_date') }}" class="hover:underline">الإصدار</a></th>
                            <th class="px-3 py-3 text-right font-semibold"><a href="{{ $sortUrl('due_date') }}" class="hover:underline">الاستحقاق</a></th>
                            <th class="px-3 py-3 text-right font-semibold"><a href="{{ $sortUrl('total') }}" class="hover:underline">الإجمالي</a></th>
                            <th class="px-3 py-3 text-right font-semibold">الضريبة</th>
                            <th class="px-3 py-3 text-right font-semibold"><a href="{{ $sortUrl('amount_due') }}" class="hover:underline">المتبقي</a></th>
                            <th class="px-3 py-3 text-right font-semibold">الحالة</th>
                            <th class="px-3 py-3 text-right font-semibold">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($invoices as $invoice)
                            @php $life = \App\Support\Finance\InvoicePresentation::lifecycle($invoice); @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-3">
                                    <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="font-bold text-slate-900 hover:text-[#0f7668]">{{ $invoice->invoice_number }}</a>
                                    <p class="text-[11px] text-slate-500">{{ $invoice->type === 'sales' ? 'مبيعات' : 'مشتريات' }}</p>
                                </td>
                                <td class="px-3 py-3">{{ $invoice->customer?->name ?? $invoice->customer_name ?? $invoice->supplier?->name ?? '-' }}</td>
                                <td class="px-3 py-3">{{ optional($invoice->issue_date)->format('Y-m-d') }}</td>
                                <td class="px-3 py-3">{{ optional($invoice->due_date)->format('Y-m-d') ?: '—' }}</td>
                                <td class="px-3 py-3 font-semibold">{{ number_format((float) $invoice->total, 2) }} <span class="text-xs text-slate-500">{{ $invoice->currency }}</span></td>
                                <td class="px-3 py-3 text-slate-600">{{ number_format((float) $invoice->tax_amount, 2) }}</td>
                                <td class="px-3 py-3 font-bold {{ (float) $invoice->amount_due > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ number_format((float) $invoice->amount_due, 2) }}</td>
                                <td class="px-3 py-3">@include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\InvoicePresentation::label($life), 'class' => \App\Support\Finance\InvoicePresentation::badgeClass($life)])</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                        <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="text-[#06C2A4] hover:underline">عرض</a>
                                        @if($life === 'draft')
                                            <a href="{{ route('workspace.finance.invoices.edit', $invoice) }}" class="text-slate-700 hover:underline">تعديل</a>
                                        @endif
                                        <a href="{{ route('workspace.finance.invoices.pdf', $invoice) }}" class="text-slate-700 hover:underline">PDF</a>
                                        @if(in_array($life, ['sent', 'partial', 'overdue'], true))
                                            <a href="{{ route('workspace.finance.invoices.show', $invoice) }}#payments" class="text-slate-700 hover:underline">دفعة</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-10">
                                    @include('workspace.finance.partials.empty-state', [
                                        'title' => 'لا توجد فواتير مطابقة للفلاتر',
                                        'body' => 'أنشئ فاتورة أو أعد ضبط التصفية.',
                                        'actionHref' => route('workspace.finance.invoices.create'),
                                        'actionLabel' => 'إنشاء أول فاتورة',
                                    ])
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $invoices->links() }}</div>
    </div>
@endsection
