<x-guest-layout title="الأرباح وسجلات الصرف">
    <div dir="rtl" class="mx-auto max-w-4xl space-y-6 px-4 py-8">
        <x-worker-account-nav :engagement-id="$engagement->id" />
        @include('partials.flash')

        @if($errors->any())
            <div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900">{{ $engagement->workspace->name }}</h1>
                    <p class="mt-1 text-sm text-slate-600">{{ $engagement->job_title ?: 'ارتباط مستقل' }}</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
            </div>

            <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                السجلات للعرض فقط. المبالغ محفوظة بوحدات minor وتُعرض بصيغة العملة؛ لا يمكن تعديل الأرباح أو حالة الصرف من حساب العامل.
            </p>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <h2 class="font-semibold">حالات الفترات</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($earningsSummary['period_statuses'] as $status => $count)
                            <span class="rounded-full bg-white px-2 py-1">{{ \App\Enums\WorkerEarningPeriodStatus::tryFrom((string) $status)?->label() ?? $status }}: {{ $count }}</span>
                        @empty
                            <span class="text-slate-500">لا توجد فترات.</span>
                        @endforelse
                    </div>
                        <div class="mt-3 space-y-1 text-xs text-slate-500">
                            @forelse($earningsSummary['line_totals'] as $total)
                                <p>{{ \App\Enums\WorkerEarningPeriodStatus::tryFrom((string) $total['period_status'])?->label() ?? $total['period_status'] }}: {{ \App\Support\Money\Money::fromMinor($total['amount_minor']) }} {{ $total['currency'] }}</p>
                            @empty
                                <p>لا توجد بنود مالية محفوظة.</p>
                            @endforelse
                        </div>
                </div>
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <h2 class="font-semibold">حالات الصرف</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse($payoutSummary['status_counts'] as $status => $count)
                            <span class="rounded-full bg-white px-2 py-1">{{ \App\Enums\WorkerPayoutStatus::tryFrom((string) $status)?->label() ?? $status }}: {{ $count }}</span>
                        @empty
                            <span class="text-slate-500">لا توجد سجلات صرف.</span>
                        @endforelse
                    </div>
                        <div class="mt-3 space-y-1 text-xs text-slate-500">
                            @forelse($payoutSummary['totals'] as $total)
                                <p>{{ \App\Enums\WorkerPayoutStatus::tryFrom((string) $total['status'])?->label() ?? $total['status'] }}: {{ \App\Support\Money\Money::fromMinor($total['amount_minor']) }} {{ $total['currency'] }}</p>
                            @empty
                                <p>لا توجد مبالغ صرف محفوظة.</p>
                            @endforelse
                        </div>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            @forelse($periods as $period)
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="font-bold text-slate-900">فترة {{ $period->id }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ $period->starts_at?->format('Y-m-d H:i') }} — {{ $period->ends_at?->format('Y-m-d H:i') }}
                            </p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $period->status->label() }}</span>
                    </div>

                    <ul class="mt-4 divide-y text-sm">
                        @forelse($period->earningLines as $line)
                            <li class="flex flex-wrap justify-between gap-3 py-2">
                                <span>{{ $line->line_type->label() }} · {{ $line->source_type->label() }}</span>
                                <span>{{ \App\Support\Money\Money::fromMinor((int) $line->amount_minor) }} {{ $line->currency }}</span>
                            </li>
                        @empty
                            <li class="py-2 text-slate-500">لا توجد بنود.</li>
                        @endforelse
                    </ul>

                    @if(in_array($period->status, [\App\Enums\WorkerEarningPeriodStatus::PendingApproval, \App\Enums\WorkerEarningPeriodStatus::Approved], true))
                        <form method="POST" action="{{ route('worker.engagements.earnings.dispute', [$engagement->id, $period->id]) }}" class="mt-4 flex flex-wrap gap-3">
                            @csrf
                            <input name="reason" maxlength="2000" placeholder="سبب الاعتراض (اختياري)" class="min-w-64 flex-1 rounded-lg border-slate-300 text-sm">
                            <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white">تسجيل اعتراض</button>
                        </form>
                    @endif
                </section>
            @empty
                <div class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">لا توجد فترات أرباح.</div>
            @endforelse
        </div>
        <div>{{ $periods->links() }}</div>

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">حالة سجلات الصرف</h2>
            <ul class="mt-4 divide-y text-sm">
                @forelse($payouts as $payout)
                    <li class="flex flex-wrap justify-between gap-3 py-3">
                        <span>فترة {{ $payout->worker_earning_period_id }}</span>
                        <span>{{ $payout->status->label() }} — {{ \App\Support\Money\Money::fromMinor((int) $payout->amount_minor) }} {{ $payout->currency }}</span>
                    </li>
                @empty
                    <li class="py-3 text-slate-500">لا توجد سجلات صرف.</li>
                @endforelse
            </ul>
            <div class="mt-4">{{ $payouts->links() }}</div>
        </section>

        <a href="{{ route('worker.engagements.show', $engagement->id) }}" class="inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الارتباط</a>
    </div>
</x-guest-layout>
