@extends('layouts.financial', ['pageTitle' => $title])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">{{ $title }}</h2>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">إضافة حركة جديدة</h3>
                <form method="POST" action="{{ route('workspace.finance.payroll-adjustments.store') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="type" value="{{ $type }}">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الموظف</label>
                        <select name="finance_employee_id" required class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="">اختر موظفًا</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->full_name }} ({{ $employee->employee_code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">العنوان</label>
                        <input type="text" name="title" required class="w-full rounded-lg border-slate-300 text-sm" placeholder="مثال: بدل سكن شهر 8">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">القيمة</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الاستحقاق</label>
                            <input type="date" name="effective_date" value="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الحالة المبدئية</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="draft">مسودة</option>
                            <option value="approved">معتمد</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                        <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                    </div>
                    <button class="w-full rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">حفظ الحركة</button>
                </form>
            </article>

            <article class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ request()->url() }}" class="mb-3 grid gap-2 sm:grid-cols-3">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="بحث بعنوان الحركة أو الموظف" class="rounded-lg border-slate-300 text-sm sm:col-span-2">
                    <select name="status" class="rounded-lg border-slate-300 text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(['draft','approved','posted','cancelled'] as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">فلترة</button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-2 py-2 text-right">الموظف</th>
                                <th class="px-2 py-2 text-right">العنوان</th>
                                <th class="px-2 py-2 text-right">القيمة</th>
                                <th class="px-2 py-2 text-right">الاستحقاق</th>
                                <th class="px-2 py-2 text-right">الحالة</th>
                                <th class="px-2 py-2 text-left">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($adjustments as $adjustment)
                                <tr>
                                    <td class="px-2 py-2">{{ $adjustment->financeEmployee?->full_name ?: $adjustment->user?->name ?: '—' }}</td>
                                    <td class="px-2 py-2">{{ $adjustment->title }}</td>
                                    <td class="px-2 py-2">{{ number_format((float) $adjustment->amount, 2) }}</td>
                                    <td class="px-2 py-2">{{ $adjustment->effective_date?->format('Y-m-d') }}</td>
                                    <td class="px-2 py-2">{{ $adjustment->status }}</td>
                                    <td class="px-2 py-2">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <form method="POST" action="{{ route('workspace.finance.payroll-adjustments.approve', $adjustment) }}">
                                                @csrf
                                                <button class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">اعتماد</button>
                                            </form>
                                            <details class="inline-block">
                                                <summary class="cursor-pointer rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">ترحيل</summary>
                                                <form method="POST" action="{{ route('workspace.finance.payroll-adjustments.post', $adjustment) }}" class="mt-1 rounded-lg border border-slate-200 bg-white p-2">
                                                    @csrf
                                                    <label class="mb-1 block text-xs text-slate-600">ربط بمسير رواتب (اختياري)</label>
                                                    <select name="payroll_run_id" class="w-full rounded-lg border-slate-300 text-xs">
                                                        <option value="">بدون مسير</option>
                                                        @foreach($runs as $run)
                                                            <option value="{{ $run->id }}">{{ $run->period_month?->format('Y-m') }} — {{ $run->status }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button class="mt-2 w-full rounded-md bg-slate-900 px-2 py-1 text-xs font-semibold text-white">تأكيد الترحيل</button>
                                                </form>
                                            </details>
                                            <form method="POST" action="{{ route('workspace.finance.payroll-adjustments.cancel', $adjustment) }}">
                                                @csrf
                                                <button class="rounded-md bg-rose-600 px-2 py-1 text-xs font-semibold text-white">إلغاء</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-2 py-8 text-center text-slate-500">لا توجد حركات حالياً.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $adjustments->links() }}</div>
            </article>
        </div>
    </div>
@endsection
