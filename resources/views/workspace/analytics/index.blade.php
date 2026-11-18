<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold tracking-tight text-slate-900">التحليلات والتقارير</h2>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 border border-emerald-200/60">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                بيانات لحظية مباشرة
            </span>
        </div>
    </x-slot>

    @php
        $maxChartVal = max(1, collect($summary['timeline'])->max('orders'));
        $maxRevVal = max(1, collect($summary['timeline'])->max('revenue'));
        $totalMessagesPeak = max(1, array_sum($summary['peak_hours']));
    @endphp

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')

        <!-- Top Controls & Time Range Selector -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
            <div>
                <h1 class="text-lg font-bold tracking-tight text-slate-900">
                    تقرير أداء الأعمال والذكاء الاصطناعي
                </h1>
                <p class="mt-1 text-xs text-slate-500">
                    الفترة المحددة: <span class="font-semibold text-slate-700">{{ $summary['from'] }}</span> إلى <span class="font-semibold text-slate-700">{{ $summary['to'] }}</span>
                </p>
            </div>

            <!-- Time Filter Selector Form -->
            <form method="GET" action="{{ route('workspace.analytics.index') }}" class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-xl bg-slate-100 p-1">
                    <a href="{{ route('workspace.analytics.index', ['range' => 'today']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'today',
                        'text-slate-600 hover:text-slate-900' => $range !== 'today',
                    ])>اليوم</a>
                    <a href="{{ route('workspace.analytics.index', ['range' => 'week']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'week',
                        'text-slate-600 hover:text-slate-900' => $range !== 'week',
                    ])>الأسبوع</a>
                    <a href="{{ route('workspace.analytics.index', ['range' => 'month']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'month',
                        'text-slate-600 hover:text-slate-900' => $range !== 'month',
                    ])>الشهر</a>
                    <a href="{{ route('workspace.analytics.index', ['range' => 'year']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'year',
                        'text-slate-600 hover:text-slate-900' => $range !== 'year',
                    ])>السنة</a>
                    <a href="{{ route('workspace.analytics.index', ['range' => 'custom']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'custom',
                        'text-slate-600 hover:text-slate-900' => $range !== 'custom',
                    ])>مخصص</a>
                </div>

                @if($range === 'custom')
                    <div class="flex items-center gap-2 pt-2 sm:pt-0">
                        <input type="date" name="from" value="{{ $from }}" class="rounded-xl border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700 focus:border-[#06C2A4] focus:ring-1 focus:ring-[#06C2A4]">
                        <span class="text-xs text-slate-400">إلى</span>
                        <input type="date" name="to" value="{{ $to }}" class="rounded-xl border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700 focus:border-[#06C2A4] focus:ring-1 focus:ring-[#06C2A4]">
                        <input type="hidden" name="range" value="custom">
                        <button type="submit" class="hs-btn-primary py-1 px-3 text-xs">تطبيق</button>
                    </div>
                @endif
            </form>
        </div>

        <!-- 6 Core KPI Cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <!-- 1. Revenue -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">إجمالي المبيعات</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">{{ number_format($summary['revenue'], 2) }}</span>
                    <span class="text-[11px] text-slate-500">ر.س</span>
                </div>
                <div class="mt-1 text-[11px] text-emerald-600 font-medium">مدفوعات مؤكدة</div>
            </div>

            <!-- 2. Orders -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">عدد الطلبات</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 0 0-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">{{ number_format($summary['orders_count']) }}</span>
                    <span class="text-[11px] text-slate-500">طلب</span>
                </div>
                <div class="mt-1 text-[11px] text-indigo-600 font-medium">طوال الفترة</div>
            </div>

            <!-- 3. New Customers -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">العملاء الجدد</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">{{ number_format($summary['customers_new']) }}</span>
                    <span class="text-[11px] text-slate-500">عميل</span>
                </div>
                <div class="mt-1 text-[11px] text-blue-600 font-medium">تم تسجيلهم</div>
            </div>

            <!-- 4. Conversations -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">المحادثات</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#E8FAF6] text-[#067E6B]">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">{{ number_format($summary['conversations_count']) }}</span>
                    <span class="text-[11px] text-slate-500">جلسة</span>
                </div>
                <div class="mt-1 text-[11px] text-[#067E6B] font-medium">عبر القنوات المربوطة</div>
            </div>

            <!-- 5. AI Responses -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">ردود موظف AI</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-purple-50 text-purple-600">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">{{ number_format($summary['ai_responses_count']) }}</span>
                    <span class="text-[11px] text-slate-500">رد</span>
                </div>
                <div class="mt-1 text-[11px] text-purple-600 font-medium">ردود آلية ذكية</div>
            </div>

            <!-- 6. Conversion Rate -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">معدل التحويل</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="text-xl font-extrabold tracking-tight text-slate-900">{{ $summary['conversion_rate'] }}%</span>
                </div>
                <div class="mt-1 text-[11px] text-amber-600 font-medium">تحويل المحادثات لطلبات</div>
            </div>
        </div>

        <!-- Timeline Chart & Peak Hours Grid -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Timeline Chart (2 Cols) -->
            <div class="hs-card lg:col-span-2">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">حركة المبيعات والطلبات عبر الزمن</h2>
                        <p class="mt-0.5 text-xs text-slate-400">توزيع الطلبات والإيرادات خلال الفترة المحددة</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="inline-flex items-center gap-1.5 font-medium text-slate-600">
                            <span class="h-2.5 w-2.5 rounded bg-[#06C2A4]"></span>
                            الطلبات
                        </span>
                        <span class="inline-flex items-center gap-1.5 font-medium text-slate-600">
                            <span class="h-2.5 w-2.5 rounded bg-emerald-300"></span>
                            الإيراد (ر.س)
                        </span>
                    </div>
                </div>

                @if(count($summary['timeline']) > 0)
                    <div class="mt-6 flex h-48 items-end gap-2 sm:gap-3 pt-6 border-b border-slate-100">
                        @foreach($summary['timeline'] as $pt)
                            @php
                                $ordersPct = max(8, min(100, round(($pt['orders'] / $maxChartVal) * 100)));
                                $revPct = max(6, min(100, round(($pt['revenue'] / $maxRevVal) * 100)));
                            @endphp
                            <div class="group relative flex flex-1 flex-col items-center h-full justify-end">
                                <!-- Tooltip -->
                                <div class="pointer-events-none absolute -top-12 z-20 hidden rounded-lg bg-slate-900 px-2.5 py-1 text-center text-[11px] text-white shadow-lg group-hover:block whitespace-nowrap">
                                    <div class="font-bold">{{ $pt['label'] }}</div>
                                    <div>{{ $pt['orders'] }} طلب · {{ number_format($pt['revenue'], 2) }} ر.س</div>
                                </div>
                                
                                <!-- Dual Bars -->
                                <div class="flex w-full items-end justify-center gap-1">
                                    <!-- Orders Bar -->
                                    <div class="w-full max-w-[14px] rounded-t bg-[#06C2A4] transition-all hover:bg-[#049E86]" style="height: {{ $ordersPct }}%"></div>
                                    <!-- Revenue Bar -->
                                    <div class="w-full max-w-[14px] rounded-t bg-emerald-200 transition-all hover:bg-emerald-300" style="height: {{ $revPct }}%"></div>
                                </div>
                                <span class="mt-2 text-[10px] font-medium text-slate-400 group-hover:text-slate-700 truncate w-full text-center">
                                    {{ $pt['label'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="hs-empty py-12">
                        <p class="text-xs text-slate-400">لا توجد نقاط بيانية كافية لهذه الفترة.</p>
                    </div>
                @endif
            </div>

            <!-- Peak Hours Distribution (1 Col) -->
            <div class="hs-card flex flex-col justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">أوقات الذروة ونشاط العملاء</h2>
                    <p class="mt-0.5 text-xs text-slate-400">توزيع حركة الرسائل الواردة والصادرة على مدار اليوم</p>

                    <div class="mt-5 space-y-4">
                        @foreach($summary['peak_hours'] as $label => $count)
                            @php
                                $pct = round(($count / $totalMessagesPeak) * 100);
                            @endphp
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-slate-700">{{ $label }}</span>
                                    <span class="text-slate-500 font-mono">{{ $count }} رسالة ({{ $pct }}%)</span>
                                </div>
                                <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full transition-all duration-500 {{ $pct > 35 ? 'bg-[#06C2A4]' : ($pct > 15 ? 'bg-emerald-400' : 'bg-slate-300') }}" style="width: {{ max(4, $pct) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-slate-100 bg-slate-50 p-3 text-[11px] text-slate-500 flex items-start gap-2">
                    <svg class="h-4 w-4 shrink-0 text-[#067E6B] mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>
                        يفيد هذا التحليل في تحديد فترات الذروة لضبط سرعة استجابة موظف الذكاء الاصطناعي وتجهيز فريق خدمة العملاء.
                    </span>
                </div>
            </div>
        </div>

        <!-- Lower Grid: Top Selling Products & Payment Breakdown -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Top Selling Products -->
            <div class="hs-card">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">أكثر المنتجات طلباً</h2>
                        <p class="mt-0.5 text-xs text-slate-400">المنتجات الأكثر مبيعاً وتحقيقاً للإيرادات</p>
                    </div>
                    <a href="{{ route('workspace.chat-catalog.index') }}" class="text-xs font-semibold text-[#067E6B] hover:underline">
                        إدارة الكتالوج ←
                    </a>
                </div>

                <div class="mt-4">
                    @if(count($summary['top_products']) > 0)
                        <div class="hs-table-wrap">
                            <table class="w-full text-right text-xs">
                                <thead>
                                    <tr class="border-b border-slate-100 text-slate-400">
                                        <th class="pb-2.5 font-medium">المنتج</th>
                                        <th class="pb-2.5 font-medium text-center">الكمية المباعة</th>
                                        <th class="pb-2.5 font-medium text-left">إجمالي الإيراد</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($summary['top_products'] as $prod)
                                        <tr class="transition hover:bg-slate-50/60">
                                            <td class="py-2.5 font-semibold text-slate-800">
                                                <div class="flex items-center gap-2">
                                                    <span class="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-[10px] font-bold text-slate-600">
                                                        {{ $loop->iteration }}
                                                    </span>
                                                    <span>{{ $prod['name'] }}</span>
                                                </div>
                                            </td>
                                            <td class="py-2.5 text-center font-bold text-slate-700">
                                                {{ number_format($prod['quantity']) }}
                                            </td>
                                            <td class="py-2.5 text-left font-bold text-emerald-600">
                                                {{ number_format($prod['revenue'], 2) }} ر.س
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="hs-empty py-10">
                            <p class="text-xs text-slate-400">لا توجد مبيعات منتجات مسجلة في هذه الفترة.</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Payment Status Breakdown & AI Insights -->
            <div class="hs-card space-y-6">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">تفصيل حالات الدفع</h2>
                    <p class="mt-0.5 text-xs text-slate-400">حالات عمليات الدفع المسجلة خلال الفترة</p>

                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @php
                            $statusMap = [
                                'paid' => ['label' => 'مكتمل الدفع', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-800'],
                                'pending' => ['label' => 'قيد الانتظار', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'],
                                'failed' => ['label' => 'فشلت العملية', 'class' => 'border-rose-200 bg-rose-50 text-rose-800'],
                                'refunded' => ['label' => 'مسترد', 'class' => 'border-purple-200 bg-purple-50 text-purple-800'],
                                'cancelled' => ['label' => 'ملغي', 'class' => 'border-slate-200 bg-slate-100 text-slate-700'],
                            ];
                        @endphp

                        @forelse($summary['payment_status_breakdown'] as $st => $cnt)
                            @php
                                $meta = $statusMap[$st] ?? ['label' => ucfirst($st), 'class' => 'border-slate-200 bg-slate-50 text-slate-700'];
                            @endphp
                            <div class="rounded-xl border p-3.5 {{ $meta['class'] }}">
                                <span class="text-xs font-semibold">{{ $meta['label'] }}</span>
                                <div class="mt-1 text-2xl font-extrabold">{{ number_format($cnt) }}</div>
                                <span class="text-[10px] opacity-75">عملية دفع</span>
                            </div>
                        @empty
                            <div class="col-span-full py-6 text-center text-xs text-slate-400">
                                لا توجد سجلات دفع مسجلة في هذه الفترة.
                            </div>
                        @endforelse
                    </div>
                </div>

                <!-- AI Meshall Summary Insight Box -->
                <div class="rounded-2xl border border-emerald-200/70 bg-gradient-to-br from-emerald-50/50 to-[#E8FAF6]/40 p-4">
                    <div class="flex items-center gap-3">
                        <div class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#06C2A4] text-white shadow-sm">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span class="absolute -top-1 -right-1 flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                            </span>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-slate-900">مؤشر موظف الذكاء الاصطناعي (مشعل)</h3>
                            <p class="text-[11px] text-slate-600">
                                قام مشعل بتقديم <strong class="text-slate-900">{{ number_format($summary['ai_responses_count']) }}</strong> رد آلي ذكي، وساهم في تحقيق معدل تحويل <strong class="text-[#067E6B]">{{ $summary['conversion_rate'] }}%</strong>.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
