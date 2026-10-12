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
        $expiresAt = $currentSubscription?->current_period_end
            ?? $currentSubscription?->trial_ends_at
            ?? $currentSubscription?->ends_at;
        $meters = $entitlements['meters'] ?? [];
        $meterOrder = array_keys(config('plans.meters', []));
        $meterIcons = [
            'ai_tokens' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-500', 'path' => 'M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3M3 16h3a2 2 0 0 1 2 2v3m8-5h3m-3 5v-3a2 2 0 0 1 2-2'],
            'ai_usage' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'path' => 'M13 10V3L4 14h7v7l9-11h-7z'],
            'whatsapp_messages' => ['bg' => 'bg-green-50', 'text' => 'text-green-600', 'path' => 'M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 0 1-4.255-.947L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
            'email_sends' => ['bg' => 'bg-teal-50', 'text' => 'text-teal-600', 'path' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
            'storage_mb' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'path' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
            'api_calls' => ['bg' => 'bg-teal-50', 'text' => 'text-teal-600', 'path' => 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4'],
            'orders' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-500', 'path' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z'],
            'products' => ['bg' => 'bg-teal-50', 'text' => 'text-teal-600', 'path' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            'customers' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'path' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            'team_members' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'path' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            'users' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'path' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ];
    @endphp

    <div class="space-y-5" dir="rtl">
        @include('partials.flash')

        <section class="rounded-2xl border border-[#D8F5EF] bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-2xl border border-slate-100 bg-[#F7FCFB]">
                        <x-hasim-logo :workspace="$workspace" variant="mark" size="lg" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-[#067e6b]">شعار المساحة</h2>
                        <p class="mt-1 text-sm text-slate-600">ارفع شعارك ليظهر في لوحة التحكم. إن لم ترفع شعارًا يُستخدم شعار حاسم الذكي.</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('workspace.logo.update') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="max-w-[16rem] text-xs text-slate-600 file:me-2 file:rounded-lg file:border-0 file:bg-[#E8FAF6] file:px-3 file:py-2 file:text-xs file:font-bold file:text-[#067e6b]">
                        <button type="submit" class="hs-btn-primary px-4 py-2 text-sm">حفظ الشعار</button>
                    </form>
                    @if(\App\Support\Ui\BrandLogo::customPath($workspace))
                        <form method="POST" action="{{ route('workspace.logo.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="hs-btn-secondary px-4 py-2 text-sm">إزالة الشعار</button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-[#D8F5EF] bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-10 w-10 items-center justify-center rounded-xl bg-[#E8F8F4] text-[#06C2A4]">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 8l4.2 2.1L12 3l4.8 7.1L21 8v9H3V8zm2 11h14v2H5v-2z"/>
                        </svg>
                    </span>
                    <div>
                        <h2 class="text-base font-bold text-[#067e6b]">الباقة الحالية والاستخدام</h2>
                        <p class="mt-1 text-sm font-semibold text-slate-800">
                            المستوى:
                            <span class="font-bold">{{ $tierLabel ?? $planName ?? '—' }}</span>
                        </p>
                        @if($plan?->description)
                            <p class="mt-1 max-w-xl text-xs leading-relaxed text-slate-500">{{ $plan->description }}</p>
                        @elseif($planName)
                            <p class="mt-1 text-xs text-slate-500">{{ $planName }}</p>
                        @else
                            <p class="mt-1 text-xs text-slate-500">لا يوجد اشتراك نشط حالياً.</p>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        الحالة: {{ $statusLabels[$planStatus] ?? $planStatus }}
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-full border border-[#D8F5EF] bg-[#F7FCFB] px-3 py-1 text-xs font-semibold text-slate-600">
                        <svg class="h-4 w-4 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        {{ $expiresAt?->format('d-m-Y') ?? '—' }}
                    </span>
                </div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($meterOrder as $meterKey)
                    @php
                        $meter = $meters[$meterKey] ?? null;
                        if ($meter === null) {
                            continue;
                        }
                        $label = config("plans.meters.{$meterKey}.label", $meterKey);
                        $limit = $meter['limit'] ?? null;
                        $used = (float) ($meter['used'] ?? 0);
                        $remaining = $meter['remaining'] ?? ($limit === null ? null : max(0, (float) $limit - $used));
                        $pct = ($limit && $limit > 0) ? min(100, round(($used / $limit) * 100)) : 0;
                        $icon = $meterIcons[$meterKey] ?? ['bg' => 'bg-teal-50', 'text' => 'text-teal-600', 'path' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];
                    @endphp
                    <article class="rounded-2xl border border-[#E7F4F0] bg-[#F9FFFD] p-4 {{ $loop->last && $loop->count % 3 === 2 ? 'xl:col-span-2' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800">{{ $label }}</h3>
                                <p class="mt-2 text-right text-lg font-bold tabular-nums text-slate-900" dir="ltr">
                                    {{ $limit === null ? '∞' : number_format((float) $limit) }}
                                    <span class="text-sm font-medium text-slate-400">/ {{ number_format($used) }}</span>
                                </p>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    المتبقي
                                    <span class="tabular-nums" dir="ltr">{{ $remaining === null ? '∞' : number_format((float) $remaining) }}</span>
                                </p>
                            </div>
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $icon['bg'] }} {{ $icon['text'] }}">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="{{ $icon['path'] }}"/>
                                </svg>
                            </span>
                        </div>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-[#06C2A4]" style="width: {{ $pct }}%"></div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-[#E7F4F0] bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-sm font-bold text-[#067e6b]">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#E8F8F4] text-[#06C2A4]">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 10h18M3 14h18m-9-4v8m-7 4h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </span>
                    مقارنة الباقات
                </h2>
                <a href="{{ route('workspace.subscriptions.index') }}" class="text-xs font-semibold text-[#06C2A4] hover:underline">المزيد</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400">
                            <th class="px-3 py-2 text-right font-medium">الباقة</th>
                            <th class="px-3 py-2 text-right font-medium">الاشتراكات</th>
                            <th class="px-3 py-2 text-right font-medium">الدفع</th>
                            <th class="px-3 py-2 text-right font-medium">Checkout</th>
                            <th class="px-3 py-2 text-right font-medium">المبلغ</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($checkoutSessions as $session)
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-3 font-semibold text-slate-800">{{ $session->plan?->name ?? '—' }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ $session->subscription_status === 'cancelled' ? 'الملغي' : ($statusLabels[$session->subscription_status] ?? ($session->subscription_status ?: '—')) }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ $session->payment_status ?: '—' }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ $session->checkout_status ?: '—' }}</td>
                                <td class="px-3 py-3 font-semibold tabular-nums text-slate-800" dir="ltr">
                                    {{ strtoupper((string) ($session->currency ?: 'SAR')) }}
                                    {{ number_format((float) $session->amount, 2) }}
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center justify-end gap-3 whitespace-nowrap">
                                        <a href="{{ route('workspace.subscriptions.checkout.show', $session) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-[#06C2A4]">
                                            تفاصيل أكثر
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 19l-7-7 7-7"/>
                                            </svg>
                                        </a>
                                        @if($session->checkout_status === 'completed')
                                            <span class="text-xs font-semibold text-emerald-600">منجز</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-6 text-center text-sm text-slate-400">لا توجد عمليات اشتراك بعد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-2xl border border-[#E7F4F0] bg-white p-5 shadow-sm">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-bold text-[#067e6b]">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#E8F8F4] text-[#06C2A4]">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </span>
                سجل الاشتراكات
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs text-slate-400">
                            <th class="px-3 py-2 text-right font-medium">الخطة</th>
                            <th class="px-3 py-2 text-right font-medium">الحالة</th>
                            <th class="px-3 py-2 text-right font-medium">الفترة</th>
                            <th class="px-3 py-2 text-right font-medium">الانتهاء</th>
                            <th class="px-3 py-2 text-right font-medium">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($subscriptionHistory as $subscription)
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-3 font-semibold text-slate-800">{{ $subscription->plan?->name ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    @if($subscription->status === 'active')
                                        <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">{{ $statusLabels[$subscription->status] }}</span>
                                    @elseif(in_array($subscription->status, ['cancelled', 'expired'], true))
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">{{ $statusLabels[$subscription->status] ?? $subscription->status }}</span>
                                    @else
                                        <span class="text-slate-600">{{ $statusLabels[$subscription->status] ?? $subscription->status }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 tabular-nums text-slate-600">
                                    {{ $subscription->current_period_start?->format('Y-m-d') ?? '—' }}
                                    --
                                    {{ $subscription->current_period_end?->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="px-3 py-3 tabular-nums text-slate-600">{{ $subscription->current_period_end?->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-3 py-3 font-semibold tabular-nums text-slate-800" dir="ltr">
                                    {{ strtoupper((string) ($subscription->plan?->currency ?: 'SAR')) }}
                                    {{ $subscription->plan?->price !== null ? number_format((float) $subscription->plan->price, 2) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-400">لا يوجد سجل.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
