@extends('layouts.financial', ['pageTitle' => 'المصروفات'])

@section('content')
    <div x-data="{ attachmentName: '' }" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xl font-bold text-slate-900">المصروفات</h2>
            <a href="{{ route('workspace.finance.exports.download', 'expenses') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">CSV</a>
        </div>

        <form method="POST" action="{{ route('workspace.finance.expenses.store') }}" enctype="multipart/form-data" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
            @csrf
            <input type="date" name="expense_date" value="{{ now()->toDateString() }}" class="rounded-lg border-slate-300 text-sm" required>
            <input type="number" step="0.01" min="0.01" name="amount" placeholder="المبلغ" class="rounded-lg border-slate-300 text-sm" required>
            <select name="supplier_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">مورد (اختياري)</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <select name="category_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">تصنيف المصروف</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select name="tax_profile_type" class="rounded-lg border-slate-300 text-sm">
                @foreach($taxRates as $rate)
                    <option value="{{ $rate->type }}">{{ $rate->name }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" min="0" max="100" name="tax_rate" value="15" class="rounded-lg border-slate-300 text-sm" placeholder="VAT %">
            <select name="payment_method" class="rounded-lg border-slate-300 text-sm">
                @foreach(['cash', 'bank_transfer', 'card', 'other', 'credit'] as $method)
                    <option value="{{ $method }}">{{ $method }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                @foreach(['approved', 'paid', 'draft'] as $status)
                    <option value="{{ $status }}">{{ $status }}</option>
                @endforeach
            </select>
            <select name="treasury_account_id" class="rounded-lg border-slate-300 text-sm lg:col-span-2">
                <option value="">حساب النقد/البنك</option>
                @foreach($treasuryAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->type }})</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_recurring" value="1"> متكرر
            </label>
            <select name="recurring_frequency" class="rounded-lg border-slate-300 text-sm">
                <option value="">دورية التكرار</option>
                <option value="weekly">أسبوعي</option>
                <option value="monthly">شهري</option>
                <option value="quarterly">ربع سنوي</option>
                <option value="yearly">سنوي</option>
            </select>
            <div class="lg:col-span-2">
                <label class="mb-1 block text-xs font-semibold text-slate-600">مرفق المصروف</label>
                <input
                    id="expense_attachment"
                    type="file"
                    name="attachment_file"
                    class="sr-only"
                    @change="attachmentName = $event.target.files.length ? $event.target.files[0].name : ''"
                >
                <div class="flex items-center gap-2 rounded-xl border border-slate-300 bg-slate-50 p-2">
                    <label for="expense_attachment" class="cursor-pointer rounded-lg bg-slate-800 px-3 py-2 text-xs font-bold text-white hover:bg-slate-700">اختيار مرفق</label>
                    <span class="truncate text-xs text-slate-600" x-text="attachmentName || 'لا يوجد ملف محدد'"></span>
                </div>
            </div>
            <textarea name="description" rows="2" class="rounded-lg border-slate-300 text-sm lg:col-span-4" placeholder="وصف المصروف"></textarea>
            <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white lg:col-span-4">حفظ المصروف</button>
        </form>

        <form method="GET" action="{{ route('workspace.finance.expenses.index') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="بحث برقم/وصف المصروف" class="rounded-lg border-slate-300 text-sm">
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الحالات</option>
                @foreach(['draft', 'approved', 'paid', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white sm:col-span-2">تطبيق</button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3 text-right">رقم المصروف</th>
                        <th class="px-3 py-3 text-right">التاريخ</th>
                        <th class="px-3 py-3 text-right">المورد</th>
                        <th class="px-3 py-3 text-right">المبلغ</th>
                        <th class="px-3 py-3 text-right">ضريبة القيمة المضافة</th>
                        <th class="px-3 py-3 text-right">الإجمالي</th>
                        <th class="px-3 py-3 text-right">الحالة</th>
                        <th class="px-3 py-3 text-right">الدفع</th>
                        <th class="px-3 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($expenses as $expense)
                        <tr>
                            <td class="px-3 py-3 font-semibold">{{ $expense->expense_number }}</td>
                            <td class="px-3 py-3">{{ $expense->expense_date }}</td>
                            <td class="px-3 py-3">{{ $expense->supplier?->name ?? '-' }}</td>
                            <td class="px-3 py-3">{{ number_format((float) $expense->amount, 2) }}</td>
                            <td class="px-3 py-3">{{ number_format((float) $expense->tax_amount, 2) }}</td>
                            <td class="px-3 py-3">{{ number_format((float) $expense->total, 2) }} {{ $expense->currency }}</td>
                            <td class="px-3 py-3"><span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $expense->status }}</span></td>
                            <td class="px-3 py-3">{{ $expense->payment_method }}</td>
                            <td class="px-3 py-3">
                                @if($expense->attachment_path)
                                    <a href="{{ route('workspace.finance.expenses.attachment', $expense) }}" class="ml-2 text-xs font-semibold text-[#06C2A4]">مرفق</a>
                                @endif
                                @if($expense->status === 'draft')
                                    <a href="{{ route('workspace.finance.expenses.edit', $expense) }}" class="ml-2 text-xs font-semibold text-slate-700">تعديل</a>
                                @endif
                                @if($expense->status !== 'cancelled')
                                    <form method="POST" action="{{ route('workspace.finance.expenses.destroy', $expense) }}" onsubmit="return confirm('عكس/إلغاء هذا المصروف؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-rose-600 hover:underline">إلغاء</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-8 text-center text-slate-500">لا توجد مصروفات بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $expenses->links() }}</div>
    </div>
@endsection
