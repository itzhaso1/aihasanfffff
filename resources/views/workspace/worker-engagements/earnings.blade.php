<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">أرباح العامل المستقل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4">
            @include('workspace.partials.nav')
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

            <div class="rounded-xl border bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">{{ $engagement->workerProfile->display_name }}</h1>
                        <p class="mt-1 text-sm text-slate-600">{{ $engagement->job_title ?: 'ارتباط مستقل' }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
                </div>

                <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                    هذه السجلات مرتبطة بهذا الارتباط فقط. لا توجد هنا معادلة تلقائية لتحويل الجلسات إلى أموال.
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

            @if($canManage)
                <div class="rounded-xl border bg-white p-6">
                    <h2 class="text-lg font-bold text-slate-900">فترة أرباح جديدة</h2>
                    <form method="POST" action="{{ route('workspace.worker-engagements.earning-periods.store', $engagement) }}" class="mt-4 grid gap-4 md:grid-cols-3">
                        @csrf
                        <label class="text-sm text-slate-700">
                            تبدأ
                            <input type="datetime-local" name="starts_at" required class="mt-1 w-full rounded-lg border-slate-300">
                        </label>
                        <label class="text-sm text-slate-700">
                            تنتهي
                            <input type="datetime-local" name="ends_at" required class="mt-1 w-full rounded-lg border-slate-300">
                        </label>
                        <button class="self-end rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">إنشاء مسودة</button>
                    </form>
                </div>
            @endif

            <div class="space-y-4">
                @forelse($periods as $period)
                    <section class="rounded-xl border bg-white p-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 class="font-bold text-slate-900">فترة {{ $period->id }}</h2>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $period->starts_at?->format('Y-m-d H:i') }} — {{ $period->ends_at?->format('Y-m-d H:i') }}
                                </p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $period->status->label() }}</span>
                        </div>

                        <div class="mt-5 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="border-b text-right text-slate-500">
                                    <tr>
                                        <th class="px-2 py-2">النوع</th>
                                        <th class="px-2 py-2">المصدر</th>
                                        <th class="px-2 py-2">المبلغ</th>
                                        <th class="px-2 py-2">العملة</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    @forelse($period->earningLines as $line)
                                        <tr>
                                            <td class="px-2 py-2">{{ $line->line_type->label() }}</td>
                                            <td class="px-2 py-2">{{ $line->source_type->label() }}</td>
                                            <td class="px-2 py-2">{{ \App\Support\Money\Money::fromMinor((int) $line->amount_minor) }}</td>
                                            <td class="px-2 py-2">{{ $line->currency }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-2 py-4 text-slate-500">لا توجد بنود بعد.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if($canManage && $period->status === \App\Enums\WorkerEarningPeriodStatus::Draft)
                            <form method="POST" action="{{ route('workspace.worker-engagements.earning-periods.lines.store', [$engagement, $period->id]) }}" class="mt-5 grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-4">
                                @csrf
                                <select name="line_type" required class="rounded-lg border-slate-300 text-sm">
                                    @foreach(\App\Enums\WorkerEarningLineType::cases() as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                                <select name="source_type" required class="rounded-lg border-slate-300 text-sm">
                                    @foreach(\App\Enums\WorkerEarningSourceType::cases() as $source)
                                        <option value="{{ $source->value }}">{{ $source->label() }}</option>
                                    @endforeach
                                </select>
                                <input name="amount_minor" type="number" step="1" required placeholder="المبلغ بوحدة minor" class="rounded-lg border-slate-300 text-sm">
                                <input name="currency" value="SAR" maxlength="3" required placeholder="SAR" class="rounded-lg border-slate-300 text-sm uppercase">
                                <input name="source_id" type="number" min="1" placeholder="معرّف المصدر (اختياري)" class="rounded-lg border-slate-300 text-sm">
                                <input name="source_reference" maxlength="191" placeholder="مرجع آمن (اختياري)" class="rounded-lg border-slate-300 text-sm">
                                <input name="adjustment_reason" maxlength="2000" placeholder="سبب التعديل عند الحاجة" class="rounded-lg border-slate-300 text-sm">
                                <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">إضافة بند</button>
                            </form>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <form method="POST" action="{{ route('workspace.worker-engagements.earning-periods.submit', [$engagement, $period->id]) }}">
                                    @csrf
                                    <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white">إرسال للمراجعة</button>
                                </form>
                            </div>
                        @elseif($canManage && in_array($period->status, [\App\Enums\WorkerEarningPeriodStatus::PendingApproval, \App\Enums\WorkerEarningPeriodStatus::Disputed], true))
                            <div class="mt-4 flex flex-wrap gap-3">
                                @if($period->status === \App\Enums\WorkerEarningPeriodStatus::PendingApproval)
                                    <form method="POST" action="{{ route('workspace.worker-engagements.earning-periods.approve', [$engagement, $period->id]) }}">
                                        @csrf
                                        <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">اعتماد الفترة</button>
                                    </form>
                                @endif
                                @if($period->status === \App\Enums\WorkerEarningPeriodStatus::Disputed)
                                    <form method="POST" action="{{ route('workspace.worker-engagements.earning-periods.submit', [$engagement, $period->id]) }}">
                                        @csrf
                                        <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white">إعادة الإرسال للمراجعة</button>
                                    </form>
                                @endif
                            </div>
                        @endif

                    </section>
                @empty
                    <div class="rounded-xl border bg-white p-6 text-sm text-slate-500">لا توجد فترات أرباح.</div>
                @endforelse
            </div>
            <div>{{ $periods->links() }}</div>

            <section class="rounded-xl border bg-white p-6">
                <h2 class="font-bold text-slate-900">سجلات الصرف</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b text-right text-slate-500">
                            <tr>
                                <th class="px-2 py-2">الفترة</th>
                                <th class="px-2 py-2">المبلغ</th>
                                <th class="px-2 py-2">الحالة</th>
                                <th class="px-2 py-2">طلب الصرف</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($payouts as $payout)
                                <tr>
                                    <td class="px-2 py-2">#{{ $payout->worker_earning_period_id }}</td>
                                    <td class="px-2 py-2">{{ \App\Support\Money\Money::fromMinor((int) $payout->amount_minor) }} {{ $payout->currency }}</td>
                                    <td class="px-2 py-2">{{ $payout->status->label() }}</td>
                                    <td class="px-2 py-2">{{ $payout->requested_at?->format('Y-m-d H:i') ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-2 py-4 text-slate-500">لا توجد سجلات صرف.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $payouts->links() }}</div>
            </section>

            <a href="{{ route('workspace.worker-engagements.show', $engagement) }}" class="inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الارتباط</a>
        </div>
    </div>
</x-app-layout>
