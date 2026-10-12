<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">التحليلات</h2></x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-base font-bold text-slate-900">ملخص الأداء</h3>
                <p class="mt-1 text-sm text-slate-500">إحصاءات حقيقية من قاعدة البيانات لمساحة العمل الحالية.</p>

                <form method="GET" action="{{ route('workspace.analytics.index') }}" class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الفترة</label>
                        <select name="range" class="rounded-xl border-slate-300 text-sm" onchange="this.form.submit()">
                            <option value="today" @selected($range === 'today')>اليوم</option>
                            <option value="week" @selected($range === 'week')>هذا الأسبوع</option>
                            <option value="month" @selected($range === 'month')>هذا الشهر</option>
                            <option value="year" @selected($range === 'year')>هذه السنة</option>
                            <option value="custom" @selected($range === 'custom')>مخصص</option>
                        </select>
                    </div>
                    @if($range === 'custom')
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">من</label>
                            <input type="date" name="from" value="{{ $from }}" class="rounded-xl border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">إلى</label>
                            <input type="date" name="to" value="{{ $to }}" class="rounded-xl border-slate-300 text-sm">
                        </div>
                        <button type="submit" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
                    @endif
                </form>

                <p class="mt-3 text-xs text-slate-500">{{ $summary['from'] }} — {{ $summary['to'] }}</p>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs text-slate-500">الإيرادات (مدفوعات مكتملة)</p>
                        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($summary['revenue'], 2) }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs text-slate-500">الطلبات</p>
                        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($summary['orders_count']) }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs text-slate-500">عملاء جدد</p>
                        <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($summary['customers_new']) }}</p>
                    </div>
                </div>
            </div>

            <div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h4 class="text-sm font-bold text-slate-900">أفضل المنتجات</h4>
                    <ul class="mt-3 divide-y divide-slate-100">
                        @forelse($summary['top_products'] as $product)
                            <li class="flex items-center justify-between py-2 text-sm">
                                <span>{{ $product['name'] }}</span>
                                <span class="font-semibold text-slate-700">{{ number_format($product['quantity']) }} · {{ number_format($product['revenue'], 2) }}</span>
                            </li>
                        @empty
                            <li class="py-4 text-sm text-slate-500">لا توجد مبيعات منتجات في هذه الفترة.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h4 class="text-sm font-bold text-slate-900">تفصيل حالة الدفع</h4>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse($summary['payment_status_breakdown'] as $status => $total)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                            <span class="text-slate-500">{{ $status }}</span>
                            <span class="mr-2 font-bold text-slate-900">{{ $total }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد بيانات دفع لهذه الفترة.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
