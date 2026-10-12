<x-guest-layout title="جلسات العمل والنشاط">
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
                وقت العمل الموثق يبدأ وينتهي بأزرار الخادم فقط. فتح الصفحة أو إبقاؤها مفتوحة لا ينشئ نشاطاً.
            </p>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block font-semibold">الجلسات</span>{{ $sessionSummary['count'] }}</div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block font-semibold">المدة الموثقة</span>{{ number_format($sessionSummary['recorded_duration_seconds']) }} ثانية</div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block font-semibold">الحالة الحالية</span>{{ $sessionSummary['active'] ? 'جلسة نشطة' : 'لا توجد جلسة نشطة' }}</div>
            </div>

            @if($activeSession)
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                    <h2 class="font-bold text-emerald-900">جلسة نشطة</h2>
                    <p class="mt-2 text-sm text-emerald-800">
                        بدأت في {{ $activeSession->started_at?->format('Y-m-d H:i:s') }}
                        · الحالة الحالية: {{ $openSegment?->segment_type?->label() ?: 'غير محددة' }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-3">
                        @if($openSegment?->segment_type === \App\Enums\WorkerWorkSessionSegmentType::Active)
                            <form method="POST" action="{{ route('worker.engagements.sessions.break', $engagement->id) }}">
                                @csrf
                                <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white">بدء استراحة</button>
                            </form>
                        @elseif($openSegment?->segment_type === \App\Enums\WorkerWorkSessionSegmentType::Break)
                            <form method="POST" action="{{ route('worker.engagements.sessions.resume', $engagement->id) }}">
                                @csrf
                                <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">استئناف العمل</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('worker.engagements.sessions.end', $engagement->id) }}">
                            @csrf
                            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">إنهاء الجلسة</button>
                        </form>
                    </div>
                </div>
            @elseif($engagement->status === \App\Enums\WorkerEngagementStatus::Active && $workerSuspended)
                <p class="mt-6 rounded-lg bg-red-50 p-4 text-sm text-red-800">
                    لا يمكن بدء جلسة عمل لأن حساب العامل معلّق.
                </p>
            @elseif($engagement->status === \App\Enums\WorkerEngagementStatus::Active)
                <form method="POST" action="{{ route('worker.engagements.sessions.start', $engagement->id) }}" class="mt-6">
                    @csrf
                    <button class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white">بدء جلسة العمل</button>
                </form>
            @else
                <p class="mt-6 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                    {{ $engagement->status->sessionStartBlockedMessage() }}
                </p>
            @endif
        </div>

        <form method="GET" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-5">
            <label class="text-sm text-slate-700">
                من
                <input type="date" name="from" value="{{ request('from') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="text-sm text-slate-700">
                إلى
                <input type="date" name="to" value="{{ request('to') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="text-sm text-slate-700">
                نوع النشاط
                <select name="event_type" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">كل الأنواع</option>
                    @foreach(\App\Enums\WorkerActivityEventType::cases() as $eventType)
                        <option value="{{ $eventType->value }}" @selected(request('event_type') === $eventType->value)>{{ $eventType->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-700">
                رقم الجلسة
                <input type="number" min="1" name="session_id" value="{{ request('session_id') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <button class="self-end rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white">تصفية</button>
        </form>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">السجل</h2>
            <div class="mt-4 space-y-4">
                @forelse($sessions as $session)
                    <div class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap justify-between gap-3 text-sm">
                            <span class="font-semibold">{{ $session->status->label() }}</span>
                            <span class="text-slate-500">
                                {{ $session->duration_seconds !== null ? number_format($session->duration_seconds).' ثانية' : 'قيد التشغيل' }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-slate-600">
                            {{ $session->started_at?->format('Y-m-d H:i:s') }}
                            @if($session->ended_at)
                                — {{ $session->ended_at->format('Y-m-d H:i:s') }}
                            @endif
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                            @foreach($session->segments as $segment)
                                <span class="rounded-full bg-slate-100 px-2 py-1">
                                    {{ $segment->segment_type->label() }}:
                                    {{ $segment->duration_seconds !== null ? number_format($segment->duration_seconds).'ث' : 'مفتوح' }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">لا توجد جلسات مسجلة.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $sessions->links() }}</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900">الأحداث الموثقة</h2>
            <p class="mt-1 text-xs text-slate-500">هذه الأحداث سجل تاريخي وليست مقياساً للإنتاجية أو الجودة.</p>
            <ul class="mt-4 divide-y text-sm">
                @forelse($activities as $event)
                    <li class="flex flex-wrap justify-between gap-3 py-3">
                        <span>{{ $event->event_type->label() }}</span>
                        <span class="text-slate-500">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</span>
                    </li>
                @empty
                    <li class="py-3 text-slate-500">لا توجد أحداث مطابقة.</li>
                @endforelse
            </ul>
            <div class="mt-4">{{ $activities->links() }}</div>
        </div>

        <a href="{{ route('worker.engagements.show', $engagement->id) }}" class="mt-6 inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الارتباط</a>
    </div>
</x-guest-layout>
