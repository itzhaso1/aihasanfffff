@extends('layouts.financial', ['pageTitle' => 'تفاصيل موظف المالية'])

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">{{ $employee->full_name }}</h2>
                <p class="mt-1 text-xs text-slate-500">الكود: {{ $employee->employee_code }} • {{ $statusLabels[$employee->status] ?? $employee->status }}</p>
            </div>
            <a href="{{ route('workspace.finance.employees.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">العودة لقائمة الموظفين</a>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                <h3 class="mb-3 text-sm font-bold text-slate-900">تعديل بيانات الموظف</h3>
                <form method="POST" action="{{ route('workspace.finance.employees.update', $employee) }}" class="grid gap-2 md:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الاسم الكامل</label>
                        <input name="full_name" required value="{{ old('full_name', $employee->full_name) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">كود الموظف</label>
                        <input name="employee_code" value="{{ old('employee_code', $employee->employee_code) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">المسمى الوظيفي</label>
                        <input name="job_title" value="{{ old('job_title', $employee->job_title) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">حالة الموظف</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                            @foreach($statusLabels as $statusKey => $statusLabel)
                                <option value="{{ $statusKey }}" @selected(old('status', $employee->status) === $statusKey)>{{ $statusLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الراتب الأساسي</label>
                        <input name="basic_salary" type="number" step="0.01" min="0" value="{{ old('basic_salary', (float) $employee->basic_salary) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ التعيين</label>
                        <input name="hire_date" type="date" value="{{ old('hire_date', optional($employee->hire_date)->format('Y-m-d')) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الهاتف</label>
                        <input name="phone" value="{{ old('phone', $employee->phone) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">البريد الإلكتروني</label>
                        <input name="email" type="email" value="{{ old('email', $employee->email) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">العنوان</label>
                        <input name="address" value="{{ old('address', $employee->address) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">جهة اتصال للطوارئ</label>
                        <input name="emergency_contact" value="{{ old('emergency_contact', $employee->emergency_contact) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                        <textarea name="notes" rows="3" class="w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $employee->notes) }}</textarea>
                    </div>
                    <div class="md:col-span-2 flex flex-wrap gap-2">
                        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">حفظ التعديلات</button>
                    </div>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold text-slate-900">ملخص مالي</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">الراتب الأساسي</dt>
                        <dd class="font-semibold text-slate-900">{{ number_format((float) $employee->basic_salary, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">عدد سجلات الاستحقاق</dt>
                        <dd class="font-semibold text-slate-900">{{ $employee->payrollRecords->count() }}</dd>
                    </div>
                </dl>
            </article>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">إضافة/تحديث استحقاق لفترة</h3>
            <form method="POST" action="{{ route('workspace.finance.employees.payroll-records.store', $employee) }}" class="grid gap-2 md:grid-cols-4">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">من تاريخ</label>
                    <input name="period_start" type="date" required class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">إلى تاريخ</label>
                    <input name="period_end" type="date" required class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الراتب الأساسي</label>
                    <input name="basic_salary" type="number" min="0" step="0.01" value="{{ (float) $employee->basic_salary }}" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">حالة الدفع</label>
                    <select name="payment_status" class="w-full rounded-lg border-slate-300 text-sm">
                        @foreach($payrollStatusLabels as $statusKey => $statusLabel)
                            <option value="{{ $statusKey }}">{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الإضافات/البدلات</label>
                    <input name="allowances_total" type="number" min="0" step="0.01" value="0" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الخصومات</label>
                    <input name="deductions_total" type="number" min="0" step="0.01" value="0" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الدفع (اختياري)</label>
                    <input name="paid_at" type="date" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div class="md:col-span-4">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
                <div class="md:col-span-4">
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">حفظ سجل الاستحقاق</button>
                </div>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">سجلات المستحقات</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">الفترة</th>
                            <th class="px-2 py-2 text-right">الأساسي</th>
                            <th class="px-2 py-2 text-right">البدلات</th>
                            <th class="px-2 py-2 text-right">الخصومات</th>
                            <th class="px-2 py-2 text-right">الصافي</th>
                            <th class="px-2 py-2 text-right">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($employee->payrollRecords as $record)
                            <tr>
                                <td class="px-2 py-2">{{ $record->period_start->format('Y-m-d') }} → {{ $record->period_end->format('Y-m-d') }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $record->basic_salary, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $record->allowances_total, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $record->deductions_total, 2) }}</td>
                                <td class="px-2 py-2 font-semibold text-slate-900">{{ number_format((float) $record->net_amount, 2) }}</td>
                                <td class="px-2 py-2">
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ $payrollStatusLabels[$record->payment_status] ?? $record->payment_status }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-2 py-8 text-center text-slate-500">لا توجد سجلات استحقاقات للموظف.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </div>
@endsection
