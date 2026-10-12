<x-guest-layout title="ارتباطاتي المهنية">
    <div dir="rtl" class="mx-auto max-w-4xl space-y-6 px-4 py-8">
        <x-worker-account-nav />
        @include('partials.flash')

        <div>
            <h1 class="text-2xl font-extrabold text-slate-900">ارتباطاتي المهنية</h1>
            <p class="mt-2 text-sm text-slate-600">هذه العروض والارتباطات تخص حساب العامل المستقل على مستوى المنصة.</p>
        </div>
        <a href="{{ route('worker.disputes.index') }}" class="inline-block text-sm font-semibold text-teal-700 underline">نزاعاتي</a>

        <form method="GET" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-3">
            <label class="text-sm text-slate-700">
                حالة الارتباط
                <select name="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">كل الحالات</option>
                    @foreach(\App\Enums\WorkerEngagementStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-700">
                عدد النتائج
                <select name="per_page" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    @foreach([20, 50] as $pageSize)
                        <option value="{{ $pageSize }}" @selected((int) request('per_page', 20) === $pageSize)>{{ $pageSize }}</option>
                    @endforeach
                </select>
            </label>
            <button class="self-end rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
        </form>

        @if($errors->any())
            <div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-4">
            @forelse($engagements as $engagement)
                <a href="{{ route('worker.engagements.show', $engagement->id) }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-400">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-bold text-slate-900">{{ $engagement->workspace->name }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ $engagement->job_title ?: 'بدون مسمى محدد' }}</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $engagement->status->label() }}</span>
                    </div>
                    <p class="mt-4 text-sm text-slate-500">
                        {{ $engagement->starts_at?->format('Y-m-d') ?: 'تاريخ بداية غير محدد' }}
                    </p>
                </a>
            @empty
                <div class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">لا توجد عروض ارتباط حالياً.</div>
            @endforelse
        </div>
        <div>{{ $engagements->links() }}</div>
    </div>
</x-guest-layout>
