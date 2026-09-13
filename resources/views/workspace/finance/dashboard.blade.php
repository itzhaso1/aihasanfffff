@extends('layouts.financial', ['pageTitle' => 'لوحة القرار المالي'])

@section('content')
    @php
        $analytics = $analytics ?? [];
        $hero = $analytics['hero'] ?? [];
        $secondary = $analytics['secondary'] ?? [];
        $attention = $analytics['attention'] ?? ($alerts ?? []);
        $series = collect($analytics['series'] ?? []);
        $chartSales = $series->map(fn ($row) => ['month' => $row['month'], 'value' => $row['sales']]);
        $chartProfit = $series->map(fn ($row) => ['month' => $row['month'], 'value' => $row['profit']]);
        $chartExpenses = $series->map(fn ($row) => ['month' => $row['month'], 'value' => $row['expenses']]);
        $tones = ['sales' => 'indigo', 'profit' => 'emerald', 'receivables' => 'amber', 'payables' => 'rose'];
        $periodLabels = [
            'today' => 'اليوم',
            'this_week' => 'هذا الأسبوع',
            'this_month' => 'هذا الشهر',
            'last_month' => 'الشهر الماضي',
            'this_year' => 'هذه السنة',
            'previous_year' => 'السنة الماضية',
        ];
        $cashFlow = $analytics['cash_flow'] ?? null;
        $ledgerProfit = $analytics['ledger_profit'] ?? null;
        $cashFlowChart = collect($charts['cash_flow'] ?? []);
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-[#0f7668]">حاسم · المالية</p>
                <h2 class="mt-1 text-2xl font-black text-slate-900">ماذا يحدث في العمل الآن؟</h2>
                <p class="mt-1 text-sm text-slate-500">
                    من {{ $analytics['from'] ?? now()->startOfMonth()->toDateString() }}
                    إلى {{ $analytics['to'] ?? now()->toDateString() }}
                    · المقارنة مع {{ $analytics['previous_from'] ?? '' }} → {{ $analytics['previous_to'] ?? '' }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.invoices.create') }}" class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">فاتورة جديدة</a>
                <a href="{{ route('workspace.finance.reports.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">التقارير الدفترية</a>
            </div>
        </div>

        <form method="GET" action="{{ route('workspace.finance.dashboard') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-7">
            <input type="date" name="from" value="{{ $analytics['from'] ?? request('from') }}" class="rounded-lg border-slate-300 text-sm" aria-label="من تاريخ">
            <input type="date" name="to" value="{{ $analytics['to'] ?? request('to') }}" class="rounded-lg border-slate-300 text-sm" aria-label="إلى تاريخ">
            <select name="customer_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل العملاء</option>
                @foreach($filterCustomers ?? [] as $customer)
                    <option value="{{ $customer->id }}" @selected((string) request('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
            <select name="product_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل المنتجات</option>
                @foreach($filterProducts ?? [] as $product)
                    <option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>
                @endforeach
            </select>
            <select name="project_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل المشاريع</option>
                @foreach($filterProjects ?? [] as $project)
                    <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
            <select name="lifecycle" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الحالات</option>
                @foreach(\App\Support\Finance\InvoicePresentation::lifecycleLabels() as $key => $label)
                    <option value="{{ $key }}" @selected(request('lifecycle') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <select name="payment_method" class="w-full rounded-lg border-slate-300 text-sm">
                    <option value="">طريقة الدفع</option>
                    @foreach(['cash' => 'نقد', 'bank_transfer' => 'تحويل', 'card' => 'بطاقة', 'other' => 'أخرى'] as $method => $label)
                        <option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white">فلترة</button>
            </div>
        </form>

        @if(!empty($attention))
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-amber-950">ما الذي يحتاج انتباهك؟</h3>
                    <a href="{{ route('workspace.finance.alerts.index') }}" class="text-xs font-semibold text-amber-800">مركز التنبيهات</a>
                </div>
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach($attention as $item)
                        @php $href = $item['href'] ?? null; @endphp
                        @if($href)
                            <a href="{{ $href }}" class="rounded-xl bg-white/80 px-3 py-2 text-sm text-amber-950 hover:bg-white">
                        @else
                            <div class="rounded-xl bg-white/80 px-3 py-2 text-sm text-amber-950">
                        @endif
                            <p class="font-bold">{{ $item['title'] ?? $item['reason'] ?? '' }}</p>
                            <p class="text-xs text-amber-900/80">{{ $item['reason'] ?? '' }}</p>
                        @if($href)
                            </a>
                        @else
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach($hero as $card)
                @include('workspace.finance.partials.kpi-card', [
                    'label' => $card['label'],
                    'value' => $card['value'],
                    'delta' => $card['delta'],
                    'direction' => $card['direction'],
                    'hint' => $card['hint'],
                    'tone' => $tones[$card['key']] ?? 'slate',
                    'href' => $card['key'] === 'receivables'
                        ? route('workspace.finance.invoices.index', ['lifecycle' => 'overdue', 'type' => 'sales'])
                        : ($card['key'] === 'sales' ? route('workspace.finance.invoices.index', ['type' => 'sales']) : null),
                ])
            @endforeach
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($secondary as $card)
                @include('workspace.finance.partials.kpi-card', [
                    'label' => $card['label'],
                    'value' => $card['value'],
                    'delta' => $card['delta'],
                    'direction' => $card['direction'],
                    'hint' => $card['hint'],
                ])
            @endforeach
        </section>

        @if($cashFlow)
            <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @include('workspace.finance.partials.kpi-card', ['label' => 'افتتاحي النقد', 'value' => $cashFlow['opening_cash'], 'hint' => 'رصيد الصندوق والبنك من الدفتر', 'tone' => 'slate'])
                @include('workspace.finance.partials.kpi-card', [
                    'label' => 'صافي التدفق النقدي',
                    'value' => $cashFlow['net_change'],
                    'hint' => 'تغير حسابات 1000/1100 خلال الفترة',
                    'tone' => ((float) $cashFlow['net_change'] >= 0 ? 'emerald' : 'rose'),
                    'href' => route('workspace.finance.reports.show', ['report' => 'cash-flow', 'from' => $analytics['from'] ?? null, 'to' => $analytics['to'] ?? null]),
                ])
                @include('workspace.finance.partials.kpi-card', ['label' => 'ختامي النقد', 'value' => $cashFlow['closing_cash'], 'hint' => 'بعد حركة الفترة', 'tone' => 'indigo'])
                @if($ledgerProfit)
                    @include('workspace.finance.partials.kpi-card', [
                        'label' => 'صافي الربح الدفتري',
                        'value' => $ledgerProfit['net_profit'],
                        'hint' => 'نفس مصدر قائمة الدخل الدفترية',
                        'tone' => ((float) $ledgerProfit['net_profit'] >= 0 ? 'emerald' : 'rose'),
                        'href' => route('workspace.finance.reports.show', ['report' => 'profit-loss', 'from' => $analytics['from'] ?? null, 'to' => $analytics['to'] ?? null]),
                    ])
                @endif
            </section>
        @endif

        <section class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">المبيعات حسب الشهر</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $chartSales])
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">الربح التقريبي حسب الشهر</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $chartProfit])
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">المصروفات حسب الشهر</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $chartExpenses])
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">التدفق النقدي الشهري</h3>
                    <a href="{{ route('workspace.finance.reports.show', ['report' => 'cash-flow']) }}" class="text-xs font-semibold text-[#0f7668]">تقرير التدفق</a>
                </div>
                @include('workspace.finance.partials.bar-chart', ['points' => $cashFlowChart])
            </article>
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">مقارنة الفترات</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-slate-500">
                            <tr>
                                <th class="py-1 text-right font-semibold">الفترة</th>
                                <th class="py-1 text-right font-semibold">المبيعات</th>
                                <th class="py-1 text-right font-semibold">المصروف</th>
                                <th class="py-1 text-right font-semibold">الربح</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($periodLabels as $key => $label)
                                @php $window = $periods[$key] ?? null; @endphp
                                @if($window)
                                    <tr>
                                        <td class="py-2 font-semibold text-slate-800">{{ $label }}</td>
                                        <td class="py-2">{{ number_format((float) $window['revenue'], 2) }}</td>
                                        <td class="py-2">{{ number_format((float) $window['expenses'], 2) }}</td>
                                        <td class="py-2 font-bold {{ (float) $window['net_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format((float) $window['net_profit'], 2) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">العملاء الأكثر شراءً</h3>
                    <a href="{{ route('workspace.finance.reports.index', ['from' => $analytics['from'] ?? null, 'to' => $analytics['to'] ?? null]) }}" class="text-xs font-semibold text-[#0f7668]">تفاصيل</a>
                </div>
                @include('workspace.finance.partials.bar-chart', ['points' => $analytics['top_customers'] ?? [], 'labelKey' => 'name', 'valueKey' => 'total'])
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">المتأخرون في الدفع</h3>
                <div class="space-y-2">
                    @forelse(($analytics['overdue_customers'] ?? []) as $row)
                        <div class="flex items-center justify-between rounded-xl border border-rose-100 bg-rose-50/60 px-3 py-2 text-sm">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $row->name }}</p>
                                <p class="text-xs text-rose-700">{{ $row->invoices }} فواتير متأخرة</p>
                            </div>
                            <p class="font-bold text-rose-800">{{ number_format((float) $row->due, 2) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">لا يوجد عملاء متأخرون في هذه الفلاتر.</p>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">أداء المنتجات</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $analytics['products'] ?? [], 'labelKey' => 'name', 'valueKey' => 'total'])
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">ربحية المشاريع</h3>
                <div class="space-y-2">
                    @forelse(($analytics['projects'] ?? []) as $project)
                        <div class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <p class="font-semibold">{{ $project['name'] }}</p>
                            <p class="text-xs text-slate-500">إيراد {{ number_format((float) $project['revenue'], 2) }} · تكلفة {{ number_format((float) $project['costs'], 2) }}</p>
                            <p class="mt-1 font-bold {{ (float) $project['profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">ربح {{ number_format((float) $project['profit'], 2) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">اربط الفواتير والمصروفات بمشروع لترى الربحية هنا.</p>
                    @endforelse
                </div>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">أين زادت المصاريف؟</h3>
                @include('workspace.finance.partials.bar-chart', ['points' => $analytics['expenses_by_category'] ?? [], 'labelKey' => 'name', 'valueKey' => 'total'])
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">المخزون المنخفض / القيمة</h3>
                    <a href="{{ route('workspace.finance.reports.show', ['report' => 'inventory-valuation']) }}" class="text-xs font-semibold text-[#0f7668]">تقييم المخزون</a>
                </div>
                <div class="space-y-2">
                    @forelse(($analytics['inventory'] ?? []) as $row)
                        <div class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <div>
                                <p class="font-semibold">{{ $row['name'] }}</p>
                                <p class="text-xs text-slate-500">{{ $row['sku'] }} · كمية {{ $row['stock'] }}</p>
                            </div>
                            <p class="font-bold">{{ number_format((float) $row['value'], 2) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد منتجات متتبعة.</p>
                    @endforelse
                </div>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">عقود قاربت على الانتهاء</h3>
                    <a href="{{ route('workspace.finance.contracts.index', ['expiring' => 1]) }}" class="text-xs font-semibold text-[#0f7668]">عرض العقود</a>
                </div>
                <div class="space-y-2">
                    @forelse(($analytics['expiring_contracts'] ?? []) as $contract)
                        @php $expiry = \App\Support\Finance\ContractPresentation::expiry($contract); @endphp
                        <a href="{{ route('workspace.finance.contracts.show', $contract) }}" class="block rounded-xl border border-slate-200 px-3 py-2 hover:bg-slate-50">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-semibold">{{ $contract->contract_number }} · {{ $contract->title }}</p>
                                @include('workspace.finance.partials.status-badge', ['label' => $expiry['label'], 'class' => \App\Support\Finance\ContractPresentation::expiryBadgeClass($expiry['severity'])])
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ optional($contract->end_date)->format('Y-m-d') }} · {{ number_format((float) $contract->value, 2) }} {{ $contract->currency }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد عقود تنتهي خلال 30 يومًا.</p>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold">آخر الفواتير</h3>
                    <a href="{{ route('workspace.finance.invoices.index') }}" class="text-xs font-semibold text-[#0f7668]">سجل الفواتير</a>
                </div>
                <div class="space-y-2">
                    @forelse(($latest['invoices'] ?? []) as $invoice)
                        @php $life = \App\Support\Finance\InvoicePresentation::lifecycle($invoice); @endphp
                        <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="block rounded-xl border border-slate-200 p-3 hover:bg-slate-50">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-semibold">{{ $invoice->invoice_number }}</p>
                                @include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\InvoicePresentation::label($life), 'class' => \App\Support\Finance\InvoicePresentation::badgeClass($life)])
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }} · متبقي {{ number_format((float) $invoice->amount_due, 2) }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد فواتير.</p>
                    @endforelse
                </div>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">الفواتير المتأخرة</h3>
                <div class="space-y-2">
                    @forelse(($latest['overdue_invoices'] ?? []) as $invoice)
                        <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="block rounded-xl border border-rose-200 bg-rose-50 p-3">
                            <p class="text-sm font-semibold">{{ $invoice->invoice_number }} · {{ $invoice->customer?->name ?? $invoice->customer_name }}</p>
                            <p class="text-xs text-rose-700">استحقاق {{ optional($invoice->due_date)->format('Y-m-d') }} · متبقي {{ number_format((float) $invoice->amount_due, 2) }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد فواتير متأخرة. هذا مؤشر صحي.</p>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
@endsection
