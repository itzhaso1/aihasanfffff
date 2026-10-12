<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تفاصيل ارتباط العامل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4">
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
                        <p class="mt-1 text-sm text-slate-600">{{ $engagement->job_title ?: 'بدون مسمى محدد' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full bg-teal-50 px-3 py-1 text-sm font-semibold text-teal-800">عامل مستقل</span>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
                    </div>
                </div>

                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">الدولة</dt><dd>{{ $engagement->workerProfile->country_code }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">الجنسية</dt><dd>{{ $engagement->workerProfile->nationality }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">البداية</dt><dd>{{ $engagement->starts_at?->format('Y-m-d') ?: '-' }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">النهاية</dt><dd>{{ $engagement->ends_at?->format('Y-m-d') ?: '-' }}</dd></div>
                    @if($engagement->contractDurationLabel())
                        <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">مدة التعاقد</dt><dd>{{ $engagement->contractDurationLabel() }}</dd></div>
                    @endif
                    @if($engagement->contractPriceLabel())
                        <div class="rounded-lg bg-teal-50 p-3"><dt class="font-semibold">سعر التعاقد</dt><dd>{{ $engagement->contractPriceLabel() }}</dd></div>
                    @endif
                </dl>

                @if($engagement->job_description)
                    <div class="mt-6 rounded-lg bg-slate-50 p-4 text-sm">
                        <h2 class="font-semibold">وصف العمل</h2>
                        <p class="mt-2 whitespace-pre-line text-slate-700">{{ $engagement->job_description }}</p>
                    </div>
                @endif

                @can('manageGuide', $engagement)
                    <form method="POST" action="{{ route('workspace.worker-engagements.guide.update', $engagement) }}" class="mt-6">
                        @csrf
                        @method('PUT')
                        @include('workspace.worker-engagements._guide-fields', ['guide' => $engagement->guide])
                        <button class="mt-4 rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white">
                            حفظ دليل العمل
                        </button>
                    </form>
                @else
                    @include('workspace.worker-engagements._guide-readonly', ['guide' => $engagement->guide])
                @endcan

                @if($engagement->workerProfile->professional_summary)
                    <div class="mt-6 rounded-lg bg-slate-50 p-4 text-sm">
                        <h2 class="font-semibold">الملخص المهني</h2>
                        <p class="mt-2 whitespace-pre-line text-slate-700">{{ $engagement->workerProfile->professional_summary }}</p>
                    </div>
                @endif

                <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                    لا تعرض هذه الصفحة بيانات الهوية الخاصة أو مستندات التحقق.
                </p>

                <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                        <dt class="font-semibold">الجلسات</dt>
                        <dd class="mt-1">{{ $operationalSummary['sessions']['count'] }} جلسة</dd>
                        <dd class="text-xs text-slate-500">{{ number_format($operationalSummary['sessions']['recorded_duration_seconds']) }} ثانية موثقة</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                        <dt class="font-semibold">صندوق الوارد</dt>
                        <dd class="mt-1">{{ $operationalSummary['inbox']['assigned_conversations_count'] }} محادثة</dd>
                        <dd class="text-xs text-slate-500">{{ $operationalSummary['inbox']['sent_messages_count'] }} رسالة مرسلة</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                        <dt class="font-semibold">فترات الأرباح</dt>
                        <dd class="mt-1">{{ array_sum($operationalSummary['earnings']['period_statuses']) }} فترة</dd>
                        <dd class="text-xs text-slate-500">{{ $operationalSummary['earnings']['latest_period']?->status?->label() ?: 'لا توجد فترة' }}</dd>
                        @foreach($operationalSummary['earnings']['line_totals'] as $total)
                            <dd class="text-xs text-slate-500">{{ \App\Enums\WorkerEarningPeriodStatus::tryFrom((string) $total['period_status'])?->label() ?? $total['period_status'] }}: {{ \App\Support\Money\Money::fromMinor($total['amount_minor']) }} {{ $total['currency'] }}</dd>
                        @endforeach
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm">
                        <dt class="font-semibold">سجلات الصرف</dt>
                        <dd class="mt-1">{{ array_sum($operationalSummary['payouts']['status_counts']) }} سجل</dd>
                        <dd class="text-xs text-slate-500">{{ $operationalSummary['currentSession'] ? 'جلسة نشطة' : 'لا توجد جلسة نشطة' }}</dd>
                        @foreach($operationalSummary['payouts']['totals'] as $total)
                            <dd class="text-xs text-slate-500">{{ \App\Enums\WorkerPayoutStatus::tryFrom((string) $total['status'])?->label() ?? $total['status'] }}: {{ \App\Support\Money\Money::fromMinor($total['amount_minor']) }} {{ $total['currency'] }}</dd>
                        @endforeach
                    </div>
                </div>

                <div class="mt-6 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-4">
                        <h2 class="font-semibold">القدرات الممنوحة</h2>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            @forelse($operationalSummary['capabilities'] as $permission)
                                <span class="rounded-full bg-teal-50 px-2 py-1 text-teal-800">{{ $permission->capability_key }}</span>
                            @empty
                                <span class="text-slate-500">لا توجد قدرات ممنوحة.</span>
                            @endforelse
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-4">
                        <h2 class="font-semibold">آخر معالجة في صندوق الوارد</h2>
                        <p class="mt-3 text-sm text-slate-600">
                            {{ $operationalSummary['inbox']['last_handled_at']?->format('Y-m-d H:i') ?: 'لا توجد رسائل مرسلة.' }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap gap-2">
                    <a href="{{ route('workspace.worker-engagements.capabilities.index', $engagement) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                        إدارة القدرات
                    </a>
                    <a href="{{ route('workspace.worker-engagements.sessions.index', $engagement) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                        جلسات العمل والنشاط
                    </a>
                    <a href="{{ route('workspace.worker-engagements.earnings.index', $engagement) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                        الأرباح وسجلات الصرف
                    </a>
                    <a href="{{ route('workspace.worker-disputes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                        النزاعات
                    </a>
                    @if($engagement->status === \App\Enums\WorkerEngagementStatus::Accepted)
                        <form method="POST" action="{{ route('workspace.worker-engagements.activate', $engagement) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">تفعيل</button>
                        </form>
                    @endif
                    @if($engagement->status === \App\Enums\WorkerEngagementStatus::Active)
                        <form method="POST" action="{{ route('workspace.worker-engagements.pause', $engagement) }}">
                            @csrf
                            <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white">إيقاف مؤقت</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.worker-engagements.complete', $engagement) }}">
                            @csrf
                            <button class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white">إكمال</button>
                        </form>
                    @endif
                    @if($engagement->status === \App\Enums\WorkerEngagementStatus::Paused)
                        <form method="POST" action="{{ route('workspace.worker-engagements.resume', $engagement) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">استئناف</button>
                        </form>
                    @endif
                    @if(in_array($engagement->status, [\App\Enums\WorkerEngagementStatus::Active, \App\Enums\WorkerEngagementStatus::Paused], true))
                        <form method="POST" action="{{ route('workspace.worker-engagements.terminate', $engagement) }}" class="flex items-center gap-2">
                            @csrf
                            <input name="reason" class="rounded-lg border-slate-300 text-sm" placeholder="سبب الإنهاء" required>
                            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">إنهاء</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border bg-white p-6">
                <h2 class="font-semibold">تسجيل نزاع</h2>
                <p class="mt-1 text-sm text-slate-600">يسجل النزاع للمراجعة ولا يغيّر حالة الارتباط أو الأرباح تلقائياً.</p>
                <form method="POST" action="{{ route('workspace.worker-engagements.disputes.store', $engagement) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm text-slate-700">
                        التصنيف
                        <input name="category" value="{{ old('category') }}" required maxlength="64" class="mt-1 w-full rounded-lg border-slate-300">
                    </label>
                    <label class="block text-sm text-slate-700">
                        الوصف
                        <textarea name="description" rows="4" required maxlength="5000" class="mt-1 w-full rounded-lg border-slate-300">{{ old('description') }}</textarea>
                    </label>
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تسجيل النزاع</button>
                </form>
            </div>

            <div class="rounded-xl border bg-white p-6">
                <h2 class="font-semibold">سجل قبول العرض</h2>
                <div class="mt-3 divide-y text-sm">
                    @forelse($engagement->acceptances as $acceptance)
                        <div class="flex justify-between gap-4 py-3">
                            <span>{{ $acceptance->decision }}</span>
                            <span class="text-slate-500">{{ $acceptance->accepted_at?->format('Y-m-d H:i') ?: $acceptance->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                    @empty
                        <p class="py-3 text-slate-500">لم يرد العامل على العرض بعد.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
