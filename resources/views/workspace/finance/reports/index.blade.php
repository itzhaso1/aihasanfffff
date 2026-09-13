@extends('layouts.financial', ['pageTitle' => 'التقارير والتحليلات'])

@section('content')
    @php
        $periodLabels = [
            'today' => 'اليوم',
            'this_week' => 'هذا الأسبوع',
            'this_month' => 'هذا الشهر',
            'last_month' => 'الشهر الماضي',
            'this_year' => 'هذه السنة',
            'previous_year' => 'السنة الماضية',
        ];
        $analytics = $analytics ?? [];
    @endphp
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-xl font-black text-slate-900">التقارير والتحليلات</h2>
                <p class="text-sm text-slate-500">لوحة القرار تلخص السؤال. هنا الحساب من الدفتر والفوترة مع مقارنة الفترات.</p>
            </div>
            <form method="GET" action="{{ route('workspace.finance.reports.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-slate-300 text-sm" aria-label="من تاريخ">
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-slate-300 text-sm" aria-label="إلى تاريخ">
                <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">تحديث</button>
                <a href="{{ route('workspace.finance.dashboard', ['from' => $from, 'to' => $to]) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">لوحة القرار</a>
            </form>
        </div>

        <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            تصدير CSV متاح لكل تقرير من صفحة التقرير، ولتصدير الفواتير/الدفعات/العملاء/المصروفات/عروض الأسعار من
            <a class="font-semibold text-[#06C2A4]" href="{{ route('workspace.finance.exports.index') }}">صفحة التصدير</a>.
            الفلاتر الحالية جاهزة: التاريخ يظهر هنا، وفلاتر العميل/المنتج/المشروع/الحالة/طريقة الدفع على لوحة القرار وسجل الفواتير.
        </p>

        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                'profit-loss' => ['الأرباح والخسائر', 'إيراد وتكلفة وصافي من القيود'],
                'cash-flow' => ['التدفق النقدي', 'افتتاحي / تغير / ختامي للصندوق والبنك'],
                'ar-aging' => ['أعمار الذمم', 'كم لنا ومتى استحق'],
                'ap-aging' => ['أعمار الموردين', 'كم علينا ومتى يستحق'],
                'inventory-valuation' => ['تقييم المخزون', 'الكمية × التكلفة'],
                'balance-sheet' => ['الميزانية', 'أصول مقابل التزامات وحقوق'],
                'trial-balance' => ['ميزان المراجعة', 'مدين يساوي دائن'],
                'general-ledger' => ['دفتر الأستاذ', 'حركة كل حساب'],
            ] as $key => [$label, $hint])
                <a href="{{ route('workspace.finance.reports.show', ['report' => $key, 'from' => $from, 'to' => $to]) }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:-translate-y-0.5 hover:shadow-md">
                    <p class="text-sm font-bold text-slate-900">{{ $label }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                </a>
            @endforeach
        </div>

        @if(!empty($profitAndLoss))
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @include('workspace.finance.partials.kpi-card', ['label' => 'الإيرادات (من الدفتر)', 'value' => $profitAndLoss['revenue'], 'tone' => 'indigo'])
                @include('workspace.finance.partials.kpi-card', ['label' => 'تكلفة المبيعات', 'value' => $profitAndLoss['cogs'], 'tone' => 'rose'])
                @include('workspace.finance.partials.kpi-card', ['label' => 'مجمل الربح', 'value' => $profitAndLoss['gross_profit'], 'tone' => 'emerald'])
                @include('workspace.finance.partials.kpi-card', ['label' => 'صافي الربح الدفتري', 'value' => $profitAndLoss['net_profit'], 'tone' => ((float) $profitAndLoss['net_profit'] >= 0 ? 'emerald' : 'rose')])
            </div>
        @endif

        @if(!empty($cashFlow))
            <div class="grid gap-3 md:grid-cols-3">
                @include('workspace.finance.partials.kpi-card', ['label' => 'افتتاحي النقد', 'value' => $cashFlow['opening_cash']])
                @include('workspace.finance.partials.kpi-card', [
                    'label' => 'صافي التدفق',
                    'value' => $cashFlow['net_change'],
                    'tone' => ((float) $cashFlow['net_change'] >= 0 ? 'emerald' : 'rose'),
                    'href' => route('workspace.finance.reports.show', ['report' => 'cash-flow', 'from' => $from, 'to' => $to]),
                ])
                @include('workspace.finance.partials.kpi-card', ['label' => 'ختامي النقد', 'value' => $cashFlow['closing_cash'], 'tone' => 'indigo'])
            </div>
        @endif

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المبيعات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) ($salesSummary->total_sales ?? 0), 2) }}</p>
                <p class="text-xs text-slate-500">الفواتير: {{ $salesSummary->invoices_count ?? 0 }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المشتريات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) ($purchaseSummary->total_purchases ?? 0), 2) }}</p>
                <p class="text-xs text-slate-500">الفواتير: {{ $purchaseSummary->invoices_count ?? 0 }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">إجمالي المصروفات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) ($expenseSummary->total_expenses ?? 0), 2) }}</p>
                <p class="text-xs text-slate-500">العناصر: {{ $expenseSummary->expenses_count ?? 0 }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">صافي VAT</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $vat['net'], 2) }}</p>
                <p class="text-xs text-slate-500">Output {{ number_format((float) $vat['output'], 2) }} / Input {{ number_format((float) $vat['input'], 2) }}</p>
            </article>
        </div>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">مقارنة الفترات</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-slate-500">
                        <tr>
                            <th class="py-1 text-right">الفترة</th>
                            <th class="py-1 text-right">المبيعات</th>
                            <th class="py-1 text-right">المصروف</th>
                            <th class="py-1 text-right">الربح</th>
                            <th class="py-1 text-right">الذمم</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($periodLabels as $key => $label)
                            @php $window = ($periods ?? [])[$key] ?? null; @endphp
                            @if($window)
                                <tr>
                                    <td class="py-2 font-semibold">{{ $label }}</td>
                                    <td class="py-2">{{ number_format((float) $window['revenue'], 2) }}</td>
                                    <td class="py-2">{{ number_format((float) $window['expenses'], 2) }}</td>
                                    <td class="py-2 font-bold {{ (float) $window['net_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format((float) $window['net_profit'], 2) }}</td>
                                    <td class="py-2">{{ number_format((float) $window['receivables'], 2) }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>

        <div class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">المبيعات حسب العملاء</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $salesByCustomer, 'labelKey' => 'customer_name', 'valueKey' => 'total'])
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">المصروفات حسب التصنيف</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $expensesByCategory, 'labelKey' => 'category_name', 'valueKey' => 'total'])
            </article>
        </div>

        @if(!empty($analytics['products']))
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">أداء المنتجات في الفترة</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $analytics['products'], 'labelKey' => 'name', 'valueKey' => 'total'])
            </article>
        @endif

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">ملخص ضريبة القيمة المضافة</h3>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3 text-sm">ضريبة المخرجات: <strong>{{ number_format((float) $vat['output'], 2) }}</strong></div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm">ضريبة المدخلات: <strong>{{ number_format((float) $vat['input'], 2) }}</strong></div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm">صافي الضريبة: <strong>{{ number_format((float) $vat['net'], 2) }}</strong></div>
            </div>
        </article>
    </div>
@endsection
