<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">نزاعات العمال المستقلين</h2></x-slot>

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

            <div>
                <h1 class="text-2xl font-bold text-slate-900">نزاعات مساحة العمل</h1>
                <p class="mt-1 text-sm text-slate-600">السجلات للمتابعة التشغيلية فقط ولا تغيّر حالة الارتباط أو الأرباح تلقائياً.</p>
            </div>

            <form method="GET" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-4">
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
                <button class="self-end rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
            </form>

            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">العامل</th>
                            <th class="px-4 py-3 text-right">التصنيف</th>
                            <th class="px-4 py-3 text-right">المبلّغ</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">تاريخ الفتح</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($disputes as $dispute)
                            <tr>
                                <td class="px-4 py-3">{{ $dispute->engagement?->workerProfile?->display_name ?: 'عامل مستقل' }}</td>
                                <td class="px-4 py-3">{{ $dispute->category }}</td>
                                <td class="px-4 py-3">{{ $dispute->reporter_type?->label() ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $dispute->status?->label() ?: '-' }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ $dispute->opened_at?->format('Y-m-d H:i') ?: '-' }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.worker-disputes.show', $dispute) }}" class="font-semibold text-blue-700 underline">التفاصيل</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">لا توجد نزاعات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="border-t p-4">{{ $disputes->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
