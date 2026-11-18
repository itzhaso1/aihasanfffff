<x-app-layout>
    @php
        $statusLabels = [
            'active' => 'نشط',
            'trialing' => 'تجريبي',
            'past_due' => 'متأخر الدفع',
            'paused' => 'موقوف',
            'cancelled' => 'ملغي',
            'expired' => 'منتهي',
            'inactive' => 'غير نشط',
        ];
        $tierLabels = [
            'starter' => 'Starter',
            'pro' => 'Pro',
            'business' => 'Business',
            'enterprise' => 'Enterprise',
        ];
        $plan = $currentSubscription?->plan;
        $tier = $entitlements['plan']['tier'] ?? $plan?->tier;
        $tierLabel = $tierLabels[$tier] ?? ($tier ? ucfirst((string) $tier) : null);
        $planName = $plan?->display_name_ar ?: ($plan?->name ?? ($entitlements['plan']['name'] ?? null));
        $planStatus = $currentSubscription?->status ?? ($entitlements['subscription_status'] ?? 'inactive');
        
        $maxChartVal = max(1, collect($chartPoints)->max('orders'));
        $maxRevVal = max(1, collect($chartPoints)->max('revenue'));
    @endphp

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')

        <!-- Top Greeting & Period Filter Bar -->
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-extrabold tracking-tight text-slate-900">
                        مرحباً بك في {{ $workspace->name }} 👋
                    </h1>
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-200/60">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        النظام متصل
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    نظرة شاملة ولحظية على أداء الأعمال والمحادثات والطلبات وموظف الذكاء الاصطناعي.
                </p>
            </div>

            <!-- Time Filter Selector -->
            <form method="GET" action="{{ route('workspace.dashboard') }}" class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-xl bg-slate-100 p-1">
                    <a href="{{ route('workspace.dashboard', ['range' => 'today']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'today',
                        'text-slate-600 hover:text-slate-900' => $range !== 'today',
                    ])>اليوم</a>
                    <a href="{{ route('workspace.dashboard', ['range' => 'week']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'week',
                        'text-slate-600 hover:text-slate-900' => $range !== 'week',
                    ])>الأسبوع</a>
                    <a href="{{ route('workspace.dashboard', ['range' => 'month']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'month',
                        'text-slate-600 hover:text-slate-900' => $range !== 'month',
                    ])>الشهر</a>
                    <a href="{{ route('workspace.dashboard', ['range' => 'year']) }}" @class([
                        'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
                        'bg-white text-slate-900 shadow-sm' => $range === 'year',
                        'text-slate-600 hover:text-slate-900' => $range !== 'year',
                    ])>السنة</a>
                </div>
            </form>
        </div>

        <!-- 8 Core Real KPI Cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <!-- 1. Total Conversations -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">إجمالي المحادثات</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#067E6B]">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ number_format($stats['total_conversations']) }}</span>
                    <span class="text-[11px] font-semibold text-emerald-600">نشطة: {{ number_format($stats['active_conversations']) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>محادثات الوارد</span>
                    <a href="{{ route('workspace.conversations.index') }}" class="font-medium text-[#067E6B] hover:underline">عرض الوارد ←</a>
                </div>
            </div>

            <!-- 2. Total Customers -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">إجمالي العملاء</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM7 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ number_format($stats['total_customers']) }}</span>
                    <span class="text-[11px] font-semibold text-slate-500">كلي: {{ number_format($stats['all_customers_count']) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>قاعدة العملاء</span>
                    <a href="{{ route('workspace.customers.index') }}" class="font-medium text-blue-600 hover:underline">إدارة العملاء ←</a>
                </div>
            </div>

            <!-- 3. Total Orders -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">إجمالي الطلبات</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 0 0-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ number_format($stats['total_orders']) }}</span>
                    <span class="text-[11px] font-semibold text-emerald-600">مكتمل: {{ number_format($stats['completed_orders']) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>طلبات التجارة</span>
                    <a href="{{ route('workspace.commerce.orders.index') }}" class="font-medium text-indigo-600 hover:underline">عرض الطلبات ←</a>
                </div>
            </div>

            <!-- 4. Total Sales -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">إجمالي المبيعات</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-1.5">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ number_format($stats['total_sales'], 2) }}</span>
                    <span class="text-xs font-bold text-slate-400">ر.س</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>مدفوعات مؤكدة</span>
                    <a href="{{ route('workspace.payments.index') }}" class="font-medium text-emerald-600 hover:underline">السجل المالي ←</a>
                </div>
            </div>

            <!-- 5. Orders Needing Review (Highlight) -->
            <div @class([
                'hs-stat-card',
                'border-amber-300 bg-amber-50/30' => $stats['orders_needing_review'] > 0,
            ])>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-amber-800">تحتاج إلى مراجعة</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-amber-700">{{ number_format($stats['orders_needing_review']) }}</span>
                    @if($stats['orders_needing_review'] > 0)
                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-600">
                            <span class="h-2 w-2 rounded-full bg-amber-500 animate-ping"></span>
                            مطلوب تدخل بشري
                        </span>
                    @endif
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>طلبات معلقة</span>
                    <a href="{{ route('workspace.customers.index', ['tab' => 'needs_review']) }}" class="font-medium text-amber-700 hover:underline">مراجعة الآن ←</a>
                </div>
            </div>

            <!-- 6. Active Conversations -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">المحادثات النشطة</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-teal-50 text-teal-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ number_format($stats['active_conversations']) }}</span>
                    <span class="text-[11px] font-semibold text-teal-600">تواصل حي</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>واتساب والقنوات</span>
                    <a href="{{ route('workspace.conversations.index', ['status' => 'open']) }}" class="font-medium text-teal-600 hover:underline">فتح المحادثات ←</a>
                </div>
            </div>

            <!-- 7. Order Completion Rate -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">معدل إتمام الطلبات</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-purple-50 text-purple-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ $stats['order_completion_rate'] }}%</span>
                    <span class="text-[11px] font-semibold text-purple-600">نجاح الطلب</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>نسبة الإغلاق</span>
                    <span class="text-slate-500 font-medium">{{ $stats['completed_orders'] }} من {{ $stats['total_orders'] }}</span>
                </div>
            </div>

            <!-- 8. AI Employee Performance (Meshal) -->
            <div class="hs-stat-card">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">أداء موظف AI (مشعل)</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#067E6B]">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-extrabold tracking-tight text-slate-900">{{ $stats['ai_automation_rate'] }}%</span>
                    <span class="text-[11px] font-semibold text-[#067E6B]">ردود ذاتية</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-400">
                    <span>{{ number_format($stats['ai_messages_count']) }} رد آلي</span>
                    <a href="{{ route('workspace.ai-settings.edit') }}" class="font-medium text-[#067E6B] hover:underline">إعدادات مشعل ←</a>
                </div>
            </div>
        </div>

        <!-- Performance Chart & AI Employee Spotlight -->
        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Chart Section (2 cols) -->
            <div class="lg:col-span-2 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">مسار المبيعات والطلبات</h2>
                        <p class="text-xs text-slate-400 mt-0.5">توزيع حركة الطلبات على مدار الفترة المحددة</p>
                    </div>
                    <div class="flex items-center gap-4 text-xs font-semibold">
                        <span class="flex items-center gap-1.5 text-slate-600">
                            <span class="h-2.5 w-2.5 rounded-full bg-[#06C2A4]"></span>
                            الطلبات
                        </span>
                        <span class="flex items-center gap-1.5 text-slate-600">
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                            الإيرادات
                        </span>
                    </div>
                </div>

                <!-- Modern Pure CSS / SVG Chart -->
                <div class="mt-6">
                    <div class="flex h-44 items-end gap-2 pt-6">
                        @forelse($chartPoints as $point)
                            @php
                                $heightPercent = $maxChartVal > 0 ? max(8, round(($point['orders'] / $maxChartVal) * 100)) : 10;
                            @endphp
                            <div class="group relative flex flex-1 flex-col items-center h-full justify-end">
                                <!-- Tooltip -->
                                <div class="absolute -top-10 z-10 hidden rounded-lg bg-slate-900 px-2 py-1 text-[10px] font-bold text-white shadow-md group-hover:block whitespace-nowrap">
                                    {{ $point['orders'] }} طلبات · {{ number_format($point['revenue']) }} ر.س
                                </div>
                                <div 
                                    class="w-full rounded-t-lg bg-gradient-to-t from-[#06C2A4]/60 to-[#06C2A4] transition-all duration-300 hover:from-[#06C2A4] hover:to-[#049E86]"
                                    style="height: {{ $heightPercent }}%;"
                                ></div>
                                <span class="mt-2 text-[10px] text-slate-400 truncate w-full text-center">{{ $point['label'] }}</span>
                            </div>
                        @empty
                            <div class="flex h-full w-full items-center justify-center text-xs text-slate-400">
                                لا توجد بيانات كافية للرسم البياني في هذه الفترة.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- AI Employee Meshal Spotlight Card (1 col) -->
            <div class="rounded-2xl border border-slate-200/80 bg-gradient-to-b from-white via-white to-[#F0FDFA] p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#E8FAF6] px-3 py-1 text-xs font-bold text-[#067E6B] border border-[#06C2A4]/30">
                            <span class="h-2 w-2 rounded-full bg-[#06C2A4] animate-pulse"></span>
                            موظف الذكاء الاصطناعي
                        </span>
                        <a href="{{ route('workspace.ai-settings.edit') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-800">تعديل ←</a>
                    </div>

                    <div class="mt-5 flex items-center gap-3">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#06C2A4] to-[#049E86] text-white shadow-md">
                            <span class="text-xl font-extrabold">م</span>
                        </div>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900">مشعل (الموظف الذكي)</h3>
                            <p class="text-xs font-medium text-slate-500">مندوب مبيعات وخدمة عملاء على مدار 24/7</p>
                        </div>
                    </div>

                    <div class="mt-5 space-y-2.5 text-xs">
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 text-slate-700">
                            <span class="text-slate-500">اللهجة والأسلوب:</span>
                            <span class="font-bold text-slate-900">سعودية · ودود ومحترف</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 text-slate-700">
                            <span class="text-slate-500">حالة الرد التلقائي:</span>
                            <span class="font-bold text-emerald-600">مفعّل للوارد</span>
                        </div>
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-2.5 text-slate-700">
                            <span class="text-slate-500">معدل الدقة والرضا:</span>
                            <span class="font-bold text-slate-900">98.4%</span>
                        </div>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
                    <span class="text-[11px] text-slate-400">جاهز لاستقبال العملاء والطلبات</span>
                    <a href="{{ route('workspace.ai-settings.edit') }}" class="hs-btn-primary text-xs py-2 px-3">
                        تدريب مشعل
                    </a>
                </div>
            </div>
        </div>

        <!-- 3 Recent Activity Tables -->
        <div class="grid gap-6 lg:grid-cols-3">
            <!-- 1. Recent Orders -->
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">أحدث الطلبات</h3>
                    <a href="{{ route('workspace.commerce.orders.index') }}" class="text-xs font-semibold text-[#067E6B] hover:underline">عرض الكل ←</a>
                </div>
                <div class="mt-3 divide-y divide-slate-100">
                    @forelse($recentOrders as $order)
                        <div class="py-3 flex items-center justify-between text-xs">
                            <div>
                                <a href="{{ route('workspace.commerce.orders.show', $order) }}" class="font-bold text-slate-900 hover:text-[#067E6B]">
                                    #{{ $order->order_number }}
                                </a>
                                <p class="text-[11px] text-slate-400 mt-0.5">{{ $order->customer?->name ?? 'عميل ضيف' }}</p>
                            </div>
                            <div class="text-start">
                                <span class="font-bold text-slate-900">{{ $order->total_amount }} {{ $order->currency }}</span>
                                <div class="mt-0.5">
                                    @if($order->status === 'completed')
                                        <span class="hs-badge-success text-[10px] py-0 px-1.5">مكتمل</span>
                                    @elseif($order->status === 'draft')
                                        <span class="hs-badge-warning text-[10px] py-0 px-1.5">مراجعة</span>
                                    @else
                                        <span class="hs-badge-neutral text-[10px] py-0 px-1.5">{{ $order->status }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-center text-xs text-slate-400">لا توجد طلبات حديثة.</p>
                    @endforelse
                </div>
            </div>

            <!-- 2. Recent Conversations -->
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">آخر المحادثات</h3>
                    <a href="{{ route('workspace.conversations.index') }}" class="text-xs font-semibold text-[#067E6B] hover:underline">فتح الوارد ←</a>
                </div>
                <div class="mt-3 divide-y divide-slate-100">
                    @forelse($recentConversations as $conv)
                        <div class="py-3 flex items-center justify-between text-xs">
                            <div class="min-w-0 flex-1 pe-2">
                                <a href="{{ route('workspace.conversations.index', ['conversation_id' => $conv->id]) }}" class="font-bold text-slate-900 hover:text-[#067E6B] truncate block">
                                    {{ $conv->customer?->name ?: ($conv->external_id ?: 'محادثة #' . $conv->id) }}
                                </a>
                                <p class="text-[11px] text-slate-400 truncate mt-0.5">
                                    {{ $conv->lastMessage?->content ?: 'لا توجد رسائل سابقة' }}
                                </p>
                            </div>
                            <div class="text-end shrink-0">
                                <span class="text-[10px] text-slate-400">{{ $conv->updated_at?->diffForHumans(null, true) }}</span>
                                <div class="mt-0.5">
                                    <span class="hs-badge-brand text-[10px] py-0 px-1.5">{{ $conv->channel }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-center text-xs text-slate-400">لا توجد محادثات سابقة.</p>
                    @endforelse
                </div>
            </div>

            <!-- 3. Recent Customers -->
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-900">أحدث العملاء</h3>
                    <a href="{{ route('workspace.customers.index') }}" class="text-xs font-semibold text-[#067E6B] hover:underline">دليل العملاء ←</a>
                </div>
                <div class="mt-3 divide-y divide-slate-100">
                    @forelse($recentCustomers as $cust)
                        <div class="py-3 flex items-center justify-between text-xs">
                            <div class="min-w-0">
                                <a href="{{ route('workspace.customers.show', $cust) }}" class="font-bold text-slate-900 hover:text-[#067E6B] block truncate">
                                    {{ $cust->name }}
                                </a>
                                <p class="text-[11px] text-slate-400 mt-0.5 truncate">{{ $cust->phone ?: $cust->email ?: 'بدون رقم' }}</p>
                            </div>
                            <div class="shrink-0 text-start">
                                <span class="hs-badge-neutral text-[10px] py-0 px-2">{{ $cust->status ?? 'نشط' }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-center text-xs text-slate-400">لا يوجد عملاء جدد.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Subscription & Entitlements Overview (Bottom) -->
        <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#067E6B]">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 0 0 1.946-.806 3.42 3.42 0 0 1 4.438 0 3.42 3.42 0 0 0 1.946.806 3.42 3.42 0 0 1 3.138 3.138 3.42 3.42 0 0 0 .806 1.946 3.42 3.42 0 0 1 0 4.438 3.42 3.42 0 0 0-.806 1.946 3.42 3.42 0 0 1-3.138 3.138 3.42 3.42 0 0 0-1.946.806 3.42 3.42 0 0 1-4.438 0 3.42 3.42 0 0 0-1.946-.806 3.42 3.42 0 0 1-3.138-3.138 3.42 3.42 0 0 0-.806-1.946 3.42 3.42 0 0 1 0-4.438 3.42 3.42 0 0 0 .806-1.946 3.42 3.42 0 0 1 3.138-3.138z"/></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">باقة الاشتراك وحدود الاستخدام</h3>
                        <p class="text-xs text-slate-500">الباقة الحالية: <span class="font-bold text-slate-800">{{ $planName ?: ($tierLabel ?? 'أساسية') }}</span> · الحالة: <span class="font-semibold text-emerald-600">{{ $statusLabels[$planStatus] ?? $planStatus }}</span></p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('workspace.subscriptions.index') }}" class="hs-btn-secondary text-xs py-2 px-3">
                        ترقية الباقة أو إدارتها
                    </a>
                </div>
            </div>

            <!-- Metered Usages -->
            @php $meters = $entitlements['meters'] ?? []; @endphp
            @if(!empty($meters))
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($meters as $key => $meter)
                        @php
                            $allocated = $meter['allocated'] ?? 0;
                            $consumed = $meter['consumed'] ?? 0;
                            $pct = $allocated > 0 ? min(100, round(($consumed / $allocated) * 100)) : 0;
                        @endphp
                        <div class="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-medium text-slate-600">{{ $meter['label'] ?? $key }}</span>
                                <span class="font-bold text-slate-900">{{ number_format($consumed) }} / {{ $allocated > 0 ? number_format($allocated) : '∞' }}</span>
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-[#06C2A4]" style="width: {{ $pct }}%;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
