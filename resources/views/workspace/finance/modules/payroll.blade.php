@extends('layouts.financial', ['pageTitle' => 'الرواتب'])

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-bold text-slate-900">الرواتب والمستحقات</h2>
            <a href="{{ route('workspace.finance.employees.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">إدارة موظفي المالية</a>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">موظفو المالية</h3>
                <div class="space-y-2">
                    @forelse($employees as $employee)
                        <div class="rounded-lg border border-slate-200 p-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-semibold">{{ $employee->full_name }}</p>
                                <span class="text-xs text-slate-500">{{ $employee->employee_code }}</span>
                            </div>
                            <p class="text-xs text-slate-500">{{ $employee->job_title ?: 'بدون مسمى' }} | سجلات: {{ $employee->payroll_records_count }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">لا يوجد موظفون.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $employees->links() }}</div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">آخر سجلات الاستحقاقات</h3>
                <div class="space-y-2">
                    @forelse($latestRecords as $record)
                        <div class="rounded-lg border border-slate-200 p-3 text-sm">
                            <p class="font-semibold">{{ $record->employee?->full_name ?: '—' }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $record->period_start->format('Y-m-d') }} → {{ $record->period_end->format('Y-m-d') }}
                                | صافي: {{ number_format((float) $record->net_amount, 2) }}
                                | الحالة: {{ $record->payment_status }}
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد سجلات استحقاقات بعد.</p>
                    @endforelse
                </div>
            </article>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">مسيرات الرواتب</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">الفترة</th>
                            <th class="px-2 py-2 text-right">الحالة</th>
                            <th class="px-2 py-2 text-right">إجمالي الراتب</th>
                            <th class="px-2 py-2 text-right">إجمالي الخصومات</th>
                            <th class="px-2 py-2 text-right">صافي الراتب</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($runs as $run)
                            <tr>
                                <td class="px-2 py-2">{{ $run->period_month }}</td>
                                <td class="px-2 py-2">{{ $run->status }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $run->total_gross, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $run->total_deductions, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $run->total_net, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-2 py-6 text-center text-slate-500">لا توجد مسيرات رواتب.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </div>
@endsection
