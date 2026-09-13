@extends('layouts.financial', ['pageTitle' => 'السنوات المالية'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">السنوات المالية والفترات المحاسبية</h2>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">إنشاء سنة مالية</h3>
                <form method="POST" action="{{ route('workspace.finance.fiscal-years.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">اسم السنة</label>
                        <input type="text" name="name" value="{{ old('name', now()->format('Y')) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">من</label>
                            <input type="date" name="start_date" value="{{ old('start_date', now()->startOfYear()->toDateString()) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">إلى</label>
                            <input type="date" name="end_date" value="{{ old('end_date', now()->endOfYear()->toDateString()) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                    </div>
                    <button class="w-full rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">حفظ</button>
                </form>
            </article>

            <article class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">قائمة السنوات المالية</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-2 py-2 text-right">الاسم</th>
                                <th class="px-2 py-2 text-right">الفترة</th>
                                <th class="px-2 py-2 text-right">الحالة</th>
                                <th class="px-2 py-2 text-right">الفترات</th>
                                <th class="px-2 py-2 text-left">إجراء</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($years as $year)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">{{ $year->name }}</td>
                                    <td class="px-2 py-2">{{ $year->start_date?->format('Y-m-d') }} → {{ $year->end_date?->format('Y-m-d') }}</td>
                                    <td class="px-2 py-2">{{ $year->status }}</td>
                                    <td class="px-2 py-2">{{ $year->periods_count }}</td>
                                    <td class="px-2 py-2">
                                        <a href="{{ route('workspace.finance.fiscal-years.index', ['fiscal_year_id' => $year->id]) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">فتح</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-2 py-8 text-center text-slate-500">لا توجد سنوات مالية.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $years->links() }}</div>
            </article>
        </div>

        @if($selectedYear)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-bold">{{ $selectedYear->name }} <span class="text-sm font-normal text-slate-500">({{ $selectedYear->status }})</span></h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('workspace.finance.fiscal-years.generate-monthly-periods', $selectedYear) }}">
                            @csrf
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">توليد فترات شهرية</button>
                        </form>
                        @if($selectedYear->status === 'open')
                            <form method="POST" action="{{ route('workspace.finance.fiscal-years.close', $selectedYear) }}">
                                @csrf
                                <button class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">إغلاق السنة</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('workspace.finance.fiscal-years.open', $selectedYear) }}">
                                @csrf
                                <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">إعادة فتح</button>
                            </form>
                        @endif
                    </div>
                </div>

                <form method="POST" action="{{ route('workspace.finance.fiscal-years.update', $selectedYear) }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 p-3 md:grid-cols-4">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="mb-1 block text-xs text-slate-600">الاسم</label>
                        <input type="text" name="name" value="{{ $selectedYear->name }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-600">من</label>
                        <input type="date" name="start_date" value="{{ $selectedYear->start_date?->toDateString() }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-600">إلى</label>
                        <input type="date" name="end_date" value="{{ $selectedYear->end_date?->toDateString() }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="flex items-end">
                        <button class="w-full rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">تحديث</button>
                    </div>
                </form>

                <div class="grid gap-4 xl:grid-cols-3">
                    <form method="POST" action="{{ route('workspace.finance.fiscal-years.periods.store', $selectedYear) }}" class="rounded-xl border border-slate-200 p-3">
                        @csrf
                        <h4 class="mb-2 text-sm font-bold">إضافة فترة محاسبية</h4>
                        <div class="space-y-2">
                            <input type="text" name="name" placeholder="مثال: 2026-09" class="w-full rounded-lg border-slate-300 text-sm" required>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="date" name="start_date" class="rounded-lg border-slate-300 text-sm" required>
                                <input type="date" name="end_date" class="rounded-lg border-slate-300 text-sm" required>
                            </div>
                            <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="open">مفتوحة</option>
                                <option value="closed">مغلقة</option>
                            </select>
                            <button class="w-full rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">إضافة</button>
                        </div>
                    </form>

                    <div class="xl:col-span-2 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-2 py-2 text-right">الفترة</th>
                                    <th class="px-2 py-2 text-right">التواريخ</th>
                                    <th class="px-2 py-2 text-right">الحالة</th>
                                    <th class="px-2 py-2 text-left">تغيير الحالة</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($selectedYear->periods as $period)
                                    <tr>
                                        <td class="px-2 py-2 font-semibold">{{ $period->name }}</td>
                                        <td class="px-2 py-2">{{ $period->start_date?->format('Y-m-d') }} → {{ $period->end_date?->format('Y-m-d') }}</td>
                                        <td class="px-2 py-2">{{ $period->status }}</td>
                                        <td class="px-2 py-2">
                                            <form method="POST" action="{{ route('workspace.finance.fiscal-years.periods.set-status', $period) }}" class="flex items-center gap-2">
                                                @csrf
                                                <select name="status" class="rounded-lg border-slate-300 text-xs">
                                                    <option value="open" @selected($period->status === 'open')>مفتوحة</option>
                                                    <option value="closed" @selected($period->status === 'closed')>مغلقة</option>
                                                </select>
                                                <button class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">حفظ</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-2 py-8 text-center text-slate-500">لا توجد فترات محاسبية بعد.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>
        @endif
    </div>
@endsection
