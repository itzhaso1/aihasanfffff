<x-guest-layout title="تفاصيل الارتباط">
    <div dir="rtl" class="mx-auto max-w-3xl space-y-6 px-4 py-8">
        <x-worker-account-nav :engagement-id="$engagement->id" :can-read-inbox="$canReadInbox" />
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
                    <p class="mt-1 text-sm text-slate-600">{{ $engagement->job_title ?: 'بدون مسمى محدد' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full bg-teal-50 px-3 py-1 text-sm font-semibold text-teal-800">عامل مستقل</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
                </div>
            </div>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">الدولة</dt><dd>{{ $engagement->workerProfile->country_code }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">البداية</dt><dd>{{ $engagement->starts_at?->format('Y-m-d') ?: '-' }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">النهاية</dt><dd>{{ $engagement->ends_at?->format('Y-m-d') ?: '-' }}</dd></div>
                @if($engagement->contractDurationLabel())
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">مدة التعاقد</dt><dd>{{ $engagement->contractDurationLabel() }}</dd></div>
                @endif
                @if($engagement->contractPriceLabel())
                    <div class="rounded-lg bg-teal-50 p-3"><dt class="font-semibold">سعر التعاقد</dt><dd>{{ $engagement->contractPriceLabel() }}</dd></div>
                @endif
                <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">التحقق</dt><dd>تم التحقق من الملف قبل العرض</dd></div>
            </dl>

            <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                قبول العرض لا ينشئ عضوية في مساحة العمل ولا يمنح صلاحيات أو وصولاً إلى صندوق الوارد.
            </p>

            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <span class="block font-semibold">جلسات العمل</span>
                    <span class="mt-1 block">{{ $operationalSummary['sessions']['count'] }} جلسة · {{ number_format($operationalSummary['sessions']['recorded_duration_seconds']) }} ثانية موثقة</span>
                </div>
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <span class="block font-semibold">صندوق الوارد</span>
                    <span class="mt-1 block">{{ $operationalSummary['inbox']['sent_messages_count'] }} رسالة مرسلة</span>
                </div>
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <span class="block font-semibold">الأرباح</span>
                    <span class="mt-1 block">{{ array_sum($operationalSummary['earnings']['period_statuses']) }} فترة مسجلة</span>
                    @foreach($operationalSummary['earnings']['line_totals'] as $total)
                        <span class="block text-xs text-slate-500">{{ $total['period_status'] }}: {{ \App\Support\Money\Money::fromMinor($total['amount_minor']) }} {{ $total['currency'] }}</span>
                    @endforeach
                </div>
                <div class="rounded-lg bg-slate-50 p-4 text-sm">
                    <span class="block font-semibold">الصرف</span>
                    <span class="mt-1 block">{{ array_sum($operationalSummary['payouts']['status_counts']) }} سجل صرف</span>
                    @foreach($operationalSummary['payouts']['totals'] as $total)
                        <span class="block text-xs text-slate-500">{{ $total['status'] }}: {{ \App\Support\Money\Money::fromMinor($total['amount_minor']) }} {{ $total['currency'] }}</span>
                    @endforeach
                </div>
            </div>

            <div class="mt-6 rounded-lg border border-slate-200 p-4 text-sm">
                <h2 class="font-semibold">القدرات الممنوحة لهذا الارتباط</h2>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @forelse($operationalSummary['capabilities'] as $permission)
                        <span class="rounded-full bg-teal-50 px-2 py-1 text-teal-800">
                            {{ $permission->capabilityDefinition?->display_name ?: $permission->capability_key }}
                            <span class="mt-0.5 block font-mono text-[10px] text-teal-700/70">{{ $permission->capability_key }}</span>
                        </span>
                    @empty
                        <span class="text-slate-500">لا توجد قدرات ممنوحة.</span>
                    @endforelse
                </div>
            </div>

            @if($engagement->status === \App\Enums\WorkerEngagementStatus::Offered)
                <div class="mt-6 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('worker.engagements.accept', $engagement->id) }}">
                        @csrf
                        <button class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white">قبول العرض</button>
                    </form>
                    <form method="POST" action="{{ route('worker.engagements.reject', $engagement->id) }}">
                        @csrf
                        <button class="rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white">رفض العرض</button>
                    </form>
                </div>
            @endif

            <a href="{{ route('worker.engagements.sessions.show', $engagement->id) }}" class="mt-6 inline-block text-sm font-semibold text-teal-700 underline">جلسات العمل والنشاط</a>
            <a href="{{ route('worker.engagements.earnings.show', $engagement->id) }}" class="mt-6 mr-4 inline-block text-sm font-semibold text-teal-700 underline">الأرباح وسجلات الصرف</a>
            <a href="{{ route('worker.disputes.index') }}" class="mt-6 mr-4 inline-block text-sm font-semibold text-teal-700 underline">نزاعاتي</a>
            @if($canReadInbox)
                <a href="{{ route('worker.inbox.index', $engagement->workspace_id) }}" class="mt-6 mr-4 inline-block text-sm font-semibold text-teal-700 underline">صندوق الوارد</a>
            @else
                <p class="mt-6 text-sm text-slate-600">صندوق الوارد غير متاح لأن صلاحية قراءة المحادثات لم تُمنح لهذا الارتباط.</p>
            @endif

            <a href="{{ route('worker.engagements.index') }}" class="mt-6 inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الارتباطات</a>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="font-semibold text-slate-900">تسجيل نزاع</h2>
            <p class="mt-1 text-sm text-slate-600">يسجل النزاع للمراجعة ولا يغيّر حالة الارتباط أو الأرباح تلقائياً.</p>
            <form method="POST" action="{{ route('worker.engagements.disputes.store', $engagement->id) }}" class="mt-4 space-y-3">
                @csrf
                <label class="block text-sm text-slate-700">
                    التصنيف
                    <input name="category" value="{{ old('category') }}" required maxlength="64" class="mt-1 w-full rounded-lg border-slate-300">
                </label>
                <label class="block text-sm text-slate-700">
                    الوصف
                    <textarea name="description" rows="4" required maxlength="5000" class="mt-1 w-full rounded-lg border-slate-300">{{ old('description') }}</textarea>
                </label>
                <button class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white">تسجيل النزاع</button>
            </form>
        </div>
    </div>
</x-guest-layout>
