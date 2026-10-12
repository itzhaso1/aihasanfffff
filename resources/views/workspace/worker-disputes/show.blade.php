<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تفاصيل نزاع العامل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4">
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
                        <p class="text-xs font-semibold text-teal-700">عامل مستقل</p>
                        <h1 class="mt-1 text-2xl font-bold text-slate-900">{{ $dispute->engagement?->workerProfile?->display_name ?: 'عامل مستقل' }}</h1>
                        <p class="mt-1 text-sm text-slate-600">{{ $dispute->engagement?->job_title ?: 'بدون مسمى محدد' }}</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $dispute->status?->label() }}</span>
                </div>

                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">التصنيف</dt><dd>{{ $dispute->category }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">المبلّغ</dt><dd>{{ $dispute->reporter_type?->label() }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">فتح النزاع</dt><dd>{{ $dispute->opened_at?->format('Y-m-d H:i') ?: '-' }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="font-semibold">آخر إغلاق</dt><dd>{{ $dispute->closed_at?->format('Y-m-d H:i') ?: '-' }}</dd></div>
                </dl>

                <div class="mt-6 rounded-lg border border-slate-200 p-4">
                    <h2 class="font-semibold">وصف النزاع</h2>
                    <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $dispute->description }}</p>
                </div>

                @if($dispute->resolution_summary)
                    <div class="mt-4 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">
                        <h2 class="font-semibold">ملخص الحل</h2>
                        <p class="mt-2 whitespace-pre-line">{{ $dispute->resolution_summary }}</p>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('workspace.worker-engagements.show', $dispute->worker_engagement_id) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">تفاصيل الارتباط</a>
                    <a href="{{ route('workspace.worker-disputes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">كل النزاعات</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
