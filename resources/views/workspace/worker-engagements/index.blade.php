<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">ارتباطات العمال المستقلين</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4">
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

            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">ارتباطات مساحة العمل</h1>
                    <p class="mt-1 text-sm text-slate-600">هذه العلاقة مستقلة عن أعضاء مساحة العمل والدعوات المعتادة.</p>
                </div>
                <a href="{{ route('workspace.worker-engagements.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">
                    عرض ارتباط جديد
                </a>
                <a href="{{ route('workspace.worker-disputes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                    النزاعات
                </a>
            </div>

            <form method="GET" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-3">
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
                <button class="self-end rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
            </form>

            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">العامل</th>
                            <th class="px-4 py-3 text-right">الدور</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">البداية</th>
                            <th class="px-4 py-3 text-right">تشغيل</th>
                            <th class="px-4 py-3 text-right">صندوق الوارد</th>
                            <th class="px-4 py-3 text-right">آخر نشاط</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($engagements as $engagement)
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="block text-xs font-semibold text-teal-700">عامل مستقل</span>
                                    {{ $engagement->workerProfile?->display_name ?: 'عامل مستقل' }}
                                </td>
                                <td class="px-4 py-3">{{ $engagement->job_title ?: '-' }}</td>
                                <td class="px-4 py-3">{{ $engagement->status->label() }}</td>
                                <td class="px-4 py-3">{{ $engagement->starts_at?->format('Y-m-d') ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    @if((int) $engagement->active_session_count > 0)
                                        <span class="text-emerald-700">جلسة نشطة</span>
                                    @else
                                        <span class="text-slate-500">لا توجد جلسة نشطة</span>
                                    @endif
                                    <span class="mt-1 block text-xs text-slate-500">{{ $engagement->workerProfile?->availability_status ?: '-' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="block">{{ $engagement->handled_conversations_count }} محادثة</span>
                                    <span class="text-xs text-slate-500">{{ $engagement->sent_messages_count }} رسالة</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500">
                                    {{ ($engagement->last_handled_at ?? $engagement->latest_activity_at)?->format('Y-m-d H:i') ?: '-' }}
                                </td>
                                <td class="px-4 py-3 text-left">
                                    <a class="font-semibold text-blue-700 underline" href="{{ route('workspace.worker-engagements.show', $engagement) }}">التفاصيل</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">لا توجد ارتباطات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="border-t p-4">{{ $engagements->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
