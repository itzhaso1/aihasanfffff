@extends('layouts.financial', ['pageTitle' => 'موظفو المالية'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">موظفو المالية</h2>
        <p class="text-sm text-slate-500">سجل مستقل بالكامل لموظفي المالية، بدون الاعتماد على موظفي الصفحة الرئيسية.</p>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">إضافة موظف مالية</h3>
            <form method="POST" action="{{ route('workspace.finance.employees.store') }}" class="grid gap-2 md:grid-cols-4">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الاسم الكامل</label>
                    <input name="full_name" required value="{{ old('full_name') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="مثال: أحمد محمد">
                    @error('full_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">كود الموظف</label>
                    <input name="employee_code" value="{{ old('employee_code') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="FEMP-00001 (اختياري)">
                    @error('employee_code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">المسمى الوظيفي</label>
                    <input name="job_title" value="{{ old('job_title') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="محاسب">
                    @error('job_title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">حالة الموظف</label>
                    <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                        @foreach($statusLabels as $statusKey => $statusLabel)
                            <option value="{{ $statusKey }}" @selected(old('status', 'active') === $statusKey)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الراتب الأساسي</label>
                    <input name="basic_salary" type="number" step="0.01" min="0" value="{{ old('basic_salary', 0) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('basic_salary')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ التعيين</label>
                    <input name="hire_date" type="date" value="{{ old('hire_date') }}" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('hire_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الهاتف</label>
                    <input name="phone" value="{{ old('phone') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="+9665...">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">البريد الإلكتروني</label>
                    <input name="email" type="email" value="{{ old('email') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="finance.employee@company.com">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">جهة اتصال للطوارئ</label>
                    <input name="emergency_contact" value="{{ old('emergency_contact') }}" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('emergency_contact')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">العنوان</label>
                    <input name="address" value="{{ old('address') }}" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('address')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-4">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm" placeholder="ملاحظات مالية أو تشغيلية">{{ old('notes') }}</textarea>
                    @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-4">
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">إضافة موظف</button>
                </div>
            </form>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('workspace.finance.employees.index') }}" class="mb-3 grid gap-2 md:grid-cols-4">
                <input name="search" value="{{ $filters['search'] ?? '' }}" class="rounded-lg border-slate-300 text-sm" placeholder="بحث باسم/كود/هاتف/بريد">
                <input name="job_title" value="{{ $filters['job_title'] ?? '' }}" class="rounded-lg border-slate-300 text-sm" placeholder="فلترة بالمسمى الوظيفي">
                <select name="status" class="rounded-lg border-slate-300 text-sm">
                    <option value="">كل الحالات</option>
                    @foreach($statusLabels as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">تطبيق</button>
            </form>

            <h3 class="mb-3 text-sm font-bold">قائمة موظفي المالية</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">الكود</th>
                            <th class="px-2 py-2 text-right">الاسم</th>
                            <th class="px-2 py-2 text-right">المسمى الوظيفي</th>
                            <th class="px-2 py-2 text-right">الراتب الأساسي</th>
                            <th class="px-2 py-2 text-right">تاريخ التعيين</th>
                            <th class="px-2 py-2 text-right">بيانات التواصل</th>
                            <th class="px-2 py-2 text-right">الحالة</th>
                            <th class="px-2 py-2 text-right">الاستحقاقات</th>
                            <th class="px-2 py-2 text-right">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($employees as $employee)
                            <tr>
                                <td class="px-2 py-2 font-mono text-xs text-slate-700">{{ $employee->employee_code }}</td>
                                <td class="px-2 py-2 font-semibold text-slate-900">{{ $employee->full_name }}</td>
                                <td class="px-2 py-2">{{ $employee->job_title ?: '—' }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $employee->basic_salary, 2) }}</td>
                                <td class="px-2 py-2">{{ optional($employee->hire_date)->format('Y-m-d') ?: '—' }}</td>
                                <td class="px-2 py-2 text-xs text-slate-600">
                                    {{ $employee->phone ?: '—' }}<br>{{ $employee->email ?: '—' }}
                                </td>
                                <td class="px-2 py-2">
                                    <span class="rounded-full px-2 py-1 text-xs font-semibold
                                        @if($employee->status === 'active') bg-emerald-100 text-emerald-700
                                        @elseif($employee->status === 'suspended') bg-amber-100 text-amber-700
                                        @else bg-slate-100 text-slate-600 @endif">
                                        {{ $statusLabels[$employee->status] ?? $employee->status }}
                                    </span>
                                </td>
                                <td class="px-2 py-2 text-center">{{ $employee->payroll_records_count }}</td>
                                <td class="px-2 py-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('workspace.finance.employees.show', $employee) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">تفاصيل</a>
                                        <form method="POST" action="{{ route('workspace.finance.employees.destroy', $employee) }}" onsubmit="return confirm('حذف الموظف من سجل المالية؟')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-2 py-8 text-center text-slate-500">لا يوجد موظفو مالية حتى الآن.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $employees->links() }}</div>
        </article>
    </div>
@endsection
