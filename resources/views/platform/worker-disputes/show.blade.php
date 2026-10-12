@extends('platform.layout')

@section('content')
    <div dir="rtl" class="space-y-6">
        @include('platform.partials.nav')

        @if(session('success'))
            <div class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700">
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
                    <p class="text-xs font-semibold text-blue-700">سجل نزاع</p>
                    <h1 class="mt-1 text-2xl font-bold text-gray-900">{{ $dispute->engagement?->workerProfile?->display_name ?: 'عامل مستقل' }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $dispute->engagement?->workspace?->name ?: '-' }} · {{ $dispute->engagement?->job_title ?: 'بدون مسمى محدد' }}</p>
                </div>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold">{{ $dispute->status?->label() }}</span>
            </div>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div class="rounded-lg bg-gray-50 p-3"><dt class="font-semibold">التصنيف</dt><dd>{{ $dispute->category }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-3"><dt class="font-semibold">المبلّغ</dt><dd>{{ $dispute->reporter_type?->label() }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-3"><dt class="font-semibold">فتح النزاع</dt><dd>{{ $dispute->opened_at?->format('Y-m-d H:i') ?: '-' }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-3"><dt class="font-semibold">المراجع</dt><dd>{{ $dispute->reviewer?->name ?: 'غير معيّن' }}</dd></div>
            </dl>

            <div class="mt-6 rounded-lg border border-gray-200 p-4">
                <h2 class="font-semibold">الوصف</h2>
                <p class="mt-2 whitespace-pre-line text-sm text-gray-700">{{ $dispute->description }}</p>
            </div>

            <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">
                <h2 class="font-semibold">ملاحظات المراجعة الداخلية</h2>
                <p class="mt-2 whitespace-pre-line">{{ $dispute->review_notes ?: 'لا توجد ملاحظات داخلية.' }}</p>
            </div>

            @if($dispute->resolution_summary)
                <div class="mt-4 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">
                    <h2 class="font-semibold">ملخص الحل</h2>
                    <p class="mt-2 whitespace-pre-line">{{ $dispute->resolution_summary }}</p>
                </div>
            @endif

            @if($dispute->status === \App\Enums\WorkerDisputeStatus::Open)
                <form method="POST" action="{{ route('platform.worker-disputes.under-review', $dispute->id) }}" class="mt-6 space-y-3 rounded-lg border border-gray-200 p-4">
                    @csrf
                    <label class="block text-sm text-gray-700">
                        ملاحظات المراجعة
                        <textarea name="review_notes" rows="3" class="mt-1 w-full rounded-lg border-gray-300"></textarea>
                    </label>
                    <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">بدء المراجعة</button>
                </form>
            @endif

            @if($dispute->status === \App\Enums\WorkerDisputeStatus::UnderReview)
                <form method="POST" action="{{ route('platform.worker-disputes.resolve', $dispute->id) }}" class="mt-6 space-y-3 rounded-lg border border-gray-200 p-4">
                    @csrf
                    <label class="block text-sm text-gray-700">
                        ملخص الحل
                        <textarea name="resolution_summary" rows="3" required class="mt-1 w-full rounded-lg border-gray-300"></textarea>
                    </label>
                    <label class="block text-sm text-gray-700">
                        ملاحظات داخلية
                        <textarea name="review_notes" rows="3" class="mt-1 w-full rounded-lg border-gray-300"></textarea>
                    </label>
                    <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">حل النزاع</button>
                </form>
            @endif

            @if($dispute->status === \App\Enums\WorkerDisputeStatus::Resolved)
                <form method="POST" action="{{ route('platform.worker-disputes.close', $dispute->id) }}" class="mt-6 rounded-lg border border-gray-200 p-4">
                    @csrf
                    <label class="block text-sm text-gray-700">
                        ملاحظات الإغلاق
                        <textarea name="review_notes" rows="3" class="mt-1 w-full rounded-lg border-gray-300"></textarea>
                    </label>
                    <button class="mt-3 rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white">إغلاق النزاع</button>
                </form>
            @endif

            <a href="{{ route('platform.worker-disputes.index') }}" class="mt-6 inline-block font-semibold text-blue-700 underline">العودة إلى النزاعات</a>
        </div>
    </div>
@endsection
