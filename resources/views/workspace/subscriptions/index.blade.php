<x-app-layout>
    @php
        $upgradeRequired = session('upgrade_required');
    @endphp

    <div class="mx-auto max-w-7xl space-y-8" dir="rtl">
        @include('partials.flash')

        @if($upgradeRequired)
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950" role="status">
                <h2 class="text-base font-semibold">{{ __('يلزم ترقية الباقة') }}</h2>
                <p class="mt-2 text-sm">
                    {{ $upgradeRequired['message'] ?? __('هذه الميزة غير متاحة في باقتك الحالية. قم بالترقية للمتابعة.') }}
                </p>
                <a href="#plans" class="mt-3 inline-block text-sm font-semibold text-[#067e6b] underline">
                    {{ __('اختر الباقة المناسبة لك') }}
                </a>
            </div>
        @endif

        <section id="plans" class="scroll-mt-6" aria-labelledby="plans-heading">
            <div class="mb-8">
                <h1 id="plans-heading" class="text-[28px] font-extrabold tracking-tight text-gray-900">
                    {{ __('اختر الباقة المناسبة لك') }}
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-7 text-gray-500">
                    {{ __('ابدأ بمساحة عمل تجريبية اليوم — ويمكنك الترقية في أي وقت.') }}
                </p>
            </div>

            @if($planCards->count() === 0)
                <x-empty-state
                    :title="__('لا توجد باقات متاحة حاليًا لنوع مساحة العمل هذه.')"
                    :text="__('الأسعار والميزات من الباقات الرسمية في النظام. الدفع يمر عبر صفحة الدفع الآمنة.')"
                />
            @else
                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($planCards as $card)
                        <x-workspace.plan-card :card="$card" :can-manage-billing="$canManageBilling" />
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-[28px] border border-gray-100 bg-white p-6 shadow-[0_8px_24px_rgba(15,23,42,0.06)]" aria-labelledby="checkouts-heading">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#E8F8F4] text-[#06C2A4]" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 7h18M5 7v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7M8 11h8"/>
                        </svg>
                    </span>
                    <h2 id="checkouts-heading" class="text-base font-bold text-gray-900">{{ __('حالات الدفع للاشتراكات') }}</h2>
                </div>
                <a href="#checkouts-heading" class="inline-flex items-center gap-1 text-xs font-semibold text-gray-500 hover:text-[#06C2A4]">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7"/>
                    </svg>
                    {{ __('عرض الكل') }}
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs font-semibold text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('الباقة') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">Checkout</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('الدفع') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('الاشتراك') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('المبلغ') }}</th>
                            <th scope="col" class="px-4 py-3 text-right">{{ __('الحالة') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($checkoutSessions as $session)
                            @php $rowStatus = $checkoutDisplayStatus($session); @endphp
                            <tr class="hover:bg-[#F7FCFB]">
                                <td class="px-4 py-4 font-semibold text-gray-800">
                                    @if($canManageBilling)
                                        <a href="{{ route('workspace.subscriptions.checkout.show', $session) }}" class="hover:text-[#06C2A4]">
                                            {{ $session->plan?->name ?? '-' }}
                                        </a>
                                    @else
                                        {{ $session->plan?->name ?? '-' }}
                                    @endif
                                </td>
                                <td class="px-4 py-4"><x-workspace.status-pill :value="$session->checkout_status" /></td>
                                <td class="px-4 py-4"><x-workspace.status-pill :value="$session->payment_status" /></td>
                                <td class="px-4 py-4"><x-workspace.status-pill :value="$session->subscription_status" /></td>
                                <td class="px-4 py-4 font-semibold text-gray-800">
                                    {{ $session->currency }} {{ number_format((float) $session->amount, 2) }}
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold {{ $rowStatus['active'] ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600' }}">
                                        <span class="h-2 w-2 rounded-full {{ $rowStatus['active'] ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                        {{ $rowStatus['label'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">{{ __('لا توجد عمليات اشتراك بعد.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $checkoutSessions->links() }}</div>
        </section>

        <details class="group rounded-[28px] border border-gray-100 bg-white p-6 shadow-sm">
            <summary class="cursor-pointer list-none text-base font-semibold text-gray-900 marker:content-none">
                {{ __('مقارنة الباقات وسجل الاشتراكات') }}
            </summary>
            <div class="mt-6 space-y-8">
                <section id="compare" aria-labelledby="compare-heading">
                    <h2 id="compare-heading" class="mb-2 font-semibold text-gray-900">{{ __('مقارنة الباقات') }}</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-right font-semibold text-gray-700">{{ __('الميزة') }}</th>
                                    @foreach($availablePlans as $plan)
                                        <th scope="col" class="px-4 py-3 text-center font-semibold text-gray-700">
                                            {{ $plan->display_name_ar ?: $plan->name }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach($comparisonRows as $row)
                                    <tr>
                                        <th scope="row" class="px-4 py-3 text-right font-medium text-gray-800">{{ $row['label'] }}</th>
                                        @foreach($availablePlans as $plan)
                                            @php $has = (bool) ($row['availability'][$plan->id] ?? false); @endphp
                                            <td class="px-4 py-3 text-center {{ $has ? 'font-semibold text-[#067e6b]' : 'text-gray-300' }}">
                                                {{ $has ? '✓' : '—' }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section aria-labelledby="history-heading">
                    <h2 id="history-heading" class="mb-3 font-semibold">{{ __('سجل الاشتراكات') }}</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right">{{ __('الخطة') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('الحالة') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('الفترة') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @forelse($subscriptions as $subscription)
                                    <tr>
                                        <td class="px-4 py-3">{{ $subscription->plan?->name ?? '-' }}</td>
                                        <td class="px-4 py-3">{{ $statusLabel($subscription->status) }}</td>
                                        <td class="px-4 py-3">{{ $subscription->current_period_start }} → {{ $subscription->current_period_end }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">{{ __('لا يوجد سجل.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $subscriptions->links() }}</div>
                </section>
            </div>
        </details>
    </div>
</x-app-layout>
