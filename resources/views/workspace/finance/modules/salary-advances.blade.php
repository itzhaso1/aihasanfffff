@extends('layouts.financial', ['pageTitle' => 'السلف'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">السلف والقروض للموظفين</h2>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">إصدار سلفة جديدة</h3>
                <form method="POST" action="{{ route('workspace.finance.salary-advances.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الموظف</label>
                        <select name="finance_employee_id" required class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="">اختر موظفًا</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->full_name }} ({{ $employee->employee_code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">القيمة</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">التاريخ</label>
                            <input type="date" name="issued_at" value="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">النوع</label>
                            <select name="type" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="salary_advance">سلفة راتب</option>
                                <option value="employee_loan">قرض موظف</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">طريقة الصرف</label>
                            <select name="payment_method" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="cash">نقدًا</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                                <option value="card">بطاقة</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">حساب الخزينة</label>
                        <select name="treasury_account_id" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="">تلقائي</option>
                            @foreach($treasuryAccounts as $treasuryAccount)
                                <option value="{{ $treasuryAccount->id }}">{{ $treasuryAccount->name }} ({{ $treasuryAccount->type }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                        <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                    </div>
                    <button class="w-full rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">إصدار السلفة</button>
                </form>
            </article>

            <article class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('workspace.finance.salary-advances.index') }}" class="mb-3 grid gap-2 sm:grid-cols-4">
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="rounded-lg border-slate-300 text-sm sm:col-span-2" placeholder="بحث باسم الموظف أو الملاحظات">
                    <select name="status" class="rounded-lg border-slate-300 text-sm">
                        <option value="">كل الحالات</option>
                        <option value="open" @selected($filters['status'] === 'open')>مفتوحة</option>
                        <option value="closed" @selected($filters['status'] === 'closed')>مغلقة</option>
                    </select>
                    <select name="type" class="rounded-lg border-slate-300 text-sm">
                        <option value="">كل الأنواع</option>
                        <option value="salary_advance" @selected($filters['type'] === 'salary_advance')>سلفة راتب</option>
                        <option value="employee_loan" @selected($filters['type'] === 'employee_loan')>قرض موظف</option>
                    </select>
                    <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">فلترة</button>
                </form>

                <div class="space-y-3">
                    @forelse($advances as $advance)
                        <div class="rounded-xl border border-slate-200 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="font-semibold">{{ $advance->financeEmployee?->full_name ?: $advance->user?->name ?: '—' }} — {{ $advance->type }}</p>
                                    <p class="text-xs text-slate-500">
                                        تاريخ الإصدار: {{ $advance->issued_at?->format('Y-m-d') }}
                                        | الحالة: {{ $advance->status }}
                                    </p>
                                </div>
                                <div class="text-sm text-slate-700">
                                    <p>القيمة: <strong>{{ number_format((float) $advance->amount, 2) }}</strong></p>
                                    <p>المتبقي: <strong>{{ number_format((float) $advance->remaining_amount, 2) }}</strong></p>
                                </div>
                            </div>

                            @if($advance->status === 'open')
                                <form method="POST" action="{{ route('workspace.finance.salary-advances.repay', $advance) }}" class="mt-3 grid gap-2 rounded-lg bg-slate-50 p-3 md:grid-cols-6">
                                    @csrf
                                    <div>
                                        <label class="mb-1 block text-xs text-slate-600">تاريخ السداد</label>
                                        <input type="date" name="payment_date" value="{{ now()->toDateString() }}" required class="w-full rounded-lg border-slate-300 text-xs">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-slate-600">القيمة</label>
                                        <input type="number" step="0.01" min="0.01" max="{{ $advance->remaining_amount }}" name="amount" required class="w-full rounded-lg border-slate-300 text-xs">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-slate-600">الطريقة</label>
                                        <select name="method" class="w-full rounded-lg border-slate-300 text-xs">
                                            <option value="cash">نقدًا</option>
                                            <option value="bank_transfer">تحويل بنكي</option>
                                            <option value="card">بطاقة</option>
                                            <option value="other">أخرى</option>
                                            <option value="payroll_deduction">خصم من الراتب</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-slate-600">الخزينة</label>
                                        <select name="treasury_account_id" class="w-full rounded-lg border-slate-300 text-xs">
                                            <option value="">تلقائي/غير مطلوب</option>
                                            @foreach($treasuryAccounts as $treasuryAccount)
                                                <option value="{{ $treasuryAccount->id }}">{{ $treasuryAccount->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="mb-1 block text-xs text-slate-600">ملاحظات</label>
                                        <input type="text" name="notes" class="w-full rounded-lg border-slate-300 text-xs">
                                    </div>
                                    <div class="md:col-span-6">
                                        <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">تسجيل السداد</button>
                                    </div>
                                </form>
                            @endif

                            @if($advance->repayments->isNotEmpty())
                                <div class="mt-3 overflow-x-auto">
                                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                                        <thead class="bg-slate-50">
                                            <tr>
                                                <th class="px-2 py-1 text-right">التاريخ</th>
                                                <th class="px-2 py-1 text-right">القيمة</th>
                                                <th class="px-2 py-1 text-right">الطريقة</th>
                                                <th class="px-2 py-1 text-right">الحساب</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach($advance->repayments as $repayment)
                                                <tr>
                                                    <td class="px-2 py-1">{{ $repayment->payment_date?->format('Y-m-d') }}</td>
                                                    <td class="px-2 py-1">{{ number_format((float) $repayment->amount, 2) }}</td>
                                                    <td class="px-2 py-1">{{ $repayment->method }}</td>
                                                    <td class="px-2 py-1">{{ $repayment->treasuryAccount?->name ?: 'خصم رواتب' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">لا توجد سلف مسجلة حالياً.</p>
                    @endforelse
                </div>

                <div class="mt-3">{{ $advances->links() }}</div>
            </article>
        </div>
    </div>
@endsection
