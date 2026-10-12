<x-guest-layout title="نزاعاتي">
    <div dir="rtl" class="mx-auto max-w-4xl space-y-6 px-4 py-8">
        <x-worker-account-nav />
        @include('partials.flash')

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">نزاعاتي</h1>
                <p class="mt-2 text-sm text-slate-600">تعرض هذه الصفحة نزاعات ارتباطاتك فقط.</p>
            </div>
            <a href="{{ route('worker.engagements.index') }}" class="text-sm font-semibold text-teal-700 underline">الارتباطات</a>
        </div>

        <form method="GET" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-4">
            <label class="text-sm text-slate-700">
                الحالة
                <select name="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <option value="">كل الحالات</option>
                    @foreach(\App\Enums\WorkerDisputeStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-700">
                من
                <input type="date" name="from" value="{{ request('from') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <label class="text-sm text-slate-700">
                إلى
                <input type="date" name="to" value="{{ request('to') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
            </label>
            <button class="self-end rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
        </form>

        <div class="grid gap-4">
            @forelse($disputes as $dispute)
                <a href="{{ route('worker.disputes.show', $dispute->id) }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-teal-400">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-bold text-slate-900">{{ $dispute->engagement?->workspace?->name ?: 'مساحة عمل' }}</h2>
                            <p class="mt-1 text-sm text-slate-600">{{ $dispute->category }}</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $dispute->status?->label() }}</span>
                    </div>
                    <p class="mt-4 text-sm text-slate-500">{{ $dispute->opened_at?->format('Y-m-d H:i') ?: '-' }}</p>
                </a>
            @empty
                <div class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">لا توجد نزاعات.</div>
            @endforelse
        </div>
        <div>{{ $disputes->links() }}</div>
    </div>
</x-guest-layout>
