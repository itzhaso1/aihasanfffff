@extends('platform.layout')

@section('content')
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl px-4 space-y-8">
            @include('platform.partials.nav')
            @include('partials.flash')

            <div>
                <h1 class="text-xl font-bold text-gray-900">لوحة المنصة</h1>
                <p class="mt-1 text-sm text-gray-600">إحصاءات حقيقية فقط — بدون أرقام وهمية.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border bg-white p-5"><p class="text-sm text-gray-500">المستخدمون</p><p class="text-2xl font-bold">{{ $stats['users'] }}</p></div>
                <div class="rounded-xl border bg-white p-5"><p class="text-sm text-gray-500">مساحات العمل</p><p class="text-2xl font-bold">{{ $stats['workspaces'] }}</p></div>
                <div class="rounded-xl border bg-white p-5"><p class="text-sm text-gray-500">الباقات</p><p class="text-2xl font-bold">{{ $stats['plans'] }}</p></div>
                <div class="rounded-xl border bg-white p-5"><p class="text-sm text-gray-500">الاشتراكات</p><p class="text-2xl font-bold">{{ $stats['subscriptions'] }}</p></div>
                <div class="rounded-xl border bg-white p-5"><p class="text-sm text-gray-500">الطلبات</p><p class="text-2xl font-bold">{{ $stats['orders'] }}</p></div>
                <div class="rounded-xl border bg-white p-5"><p class="text-sm text-gray-500">مدفوعات مدفوعة (إجمالي السجلات)</p><p class="text-2xl font-bold">{{ $stats['payments_paid'] }}</p></div>
            </div>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">إيراد المنصة ≠ حجم أعمال التجار (GMV)</h2>
                @if(!$stats['money_bucket_available'])
                    <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        فصل الإيرادات غير متاح حالياً (عمود money_bucket غير موجود).
                    </p>
                @endif
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                        <p class="text-sm font-semibold text-emerald-900">إيراد المنصة (Platform Revenue)</p>
                        <p class="mt-2 text-2xl font-bold text-emerald-950">{{ number_format($stats['platform_revenue_amount'], 2) }}</p>
                        <p class="mt-1 text-xs text-emerald-800">{{ $stats['platform_revenue_count'] }} عملية · اشتراكات المنصة فقط</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <p class="text-sm font-semibold text-slate-800">حجم أعمال التجار (Merchant GMV)</p>
                        <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['merchant_gmv_amount'], 2) }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ $stats['merchant_gmv_count'] }} عملية · ليس إيراد منصة</p>
                    </div>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">حالات الاشتراك</h2>
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach($stats['subscription_statuses'] as $status => $count)
                        <div class="rounded-xl border bg-white p-4">
                            <p class="text-xs text-gray-500">{{ $status }}</p>
                            <p class="text-xl font-bold">{{ $count }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">توزيع الباقات الرسمية</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @forelse($stats['plan_distribution'] as $row)
                        <div class="rounded-xl border bg-white p-4">
                            <p class="text-sm text-gray-500">{{ $row['name'] }} <span class="text-xs">({{ $row['code'] }})</span></p>
                            <p class="text-2xl font-bold">{{ $row['count'] }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">لا توجد باقات رسمية بعد.</p>
                    @endforelse
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-lg font-semibold text-gray-900">توثيق التجار ومزوّد الدفع</h2>
                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach($stats['merchant_verification'] as $status => $count)
                        <div class="rounded-xl border bg-white p-4">
                            <p class="text-xs text-gray-500">{{ $status }}</p>
                            <p class="text-xl font-bold">{{ $count }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($stats['provider_onboarding'] as $status => $count)
                        <div class="rounded-xl border bg-white p-4">
                            <p class="text-xs text-gray-500">مزود: {{ $status }}</p>
                            <p class="text-xl font-bold">{{ $count }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="text-sm text-gray-600">تجار معتمدون: <strong>{{ $stats['merchants_approved'] }}</strong></p>
            </section>
        </div>
    </div>
@endsection
