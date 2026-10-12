<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">جلسات ونشاط العامل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4">
            @include('workspace.partials.nav')

            <div class="rounded-xl border bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">{{ $engagement->workerProfile->display_name }}</h1>
                        <p class="mt-1 text-sm text-slate-600">{{ $engagement->job_title ?: 'ارتباط مستقل' }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
                </div>

                <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                    هذا سجل للقراءة فقط. لا تثبت مشاهدة الصفحة أو وجود المتصفح وقت عمل، ولا يمكن للشركة إنشاء أو تعديل نشاط العامل.
                </p>

                <div class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block font-semibold">الجلسات</span>{{ $sessionSummary['count'] }}</div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block font-semibold">المدة الموثقة</span>{{ number_format($sessionSummary['recorded_duration_seconds']) }} ثانية</div>
                    <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block font-semibold">الحالة الحالية</span>{{ $sessionSummary['active'] ? 'جلسة نشطة' : 'لا توجد جلسة نشطة' }}</div>
                </div>
            </div>

            <form method="GET" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-5">
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
                <button class="self-end rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تصفية</button>
            </form>

            <div class="space-y-4">
                @forelse($sessions as $session)
                    <div class="rounded-xl border bg-white p-6">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <h2 class="font-bold text-slate-900">جلسة {{ $session->id }}</h2>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ $session->started_at?->format('Y-m-d H:i:s') }}
                                    @if($session->ended_at)
                                        — {{ $session->ended_at->format('Y-m-d H:i:s') }}
                                    @endif
                                </p>
                            </div>
                            <div class="text-left text-sm">
                                <p class="font-semibold">{{ $session->status->label() }}</p>
                                <p class="text-slate-500">
                                    {{ $session->duration_seconds !== null ? number_format($session->duration_seconds).' ثانية' : 'قيد التشغيل' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2 text-xs text-slate-500">
                            @foreach($session->segments as $segment)
                                <span class="rounded-full bg-slate-100 px-2 py-1">
                                    {{ $segment->segment_type->label() }}:
                                    {{ $segment->duration_seconds !== null ? number_format($segment->duration_seconds).'ث' : 'مفتوح' }}
                                </span>
                            @endforeach
                        </div>

                        @if($session->activityEvents->isNotEmpty())
                            <div class="mt-4 border-t pt-4">
                                <h3 class="text-sm font-semibold text-slate-700">الأحداث الموثقة</h3>
                                <ul class="mt-2 space-y-2 text-sm text-slate-600">
                                    @foreach($session->activityEvents as $event)
                                        <li class="flex flex-wrap justify-between gap-3">
                                            <span>{{ $event->event_type->label() }}</span>
                                            <span>{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border bg-white p-6 text-sm text-slate-500">لا توجد جلسات مسجلة.</div>
                @endforelse
            </div>
            <div>{{ $sessions->links() }}</div>

            <div class="rounded-xl border bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold text-slate-900">الأحداث الموثقة</h2>
                        <p class="mt-1 text-xs text-slate-500">الأحداث المعروضة مصدرها سجل النشاط append-only ولا تمثل تقييماً للإنتاجية.</p>
                    </div>
                </div>
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

            <a href="{{ route('workspace.worker-engagements.show', $engagement) }}" class="inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الارتباط</a>
        </div>
    </div>
</x-app-layout>
