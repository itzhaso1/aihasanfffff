@extends('layouts.financial', ['pageTitle' => 'تعديل مسودة مصروف'])

@section('content')
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold">تعديل {{ $expense->expense_number }}</h2>
            <a href="{{ route('workspace.finance.expenses.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">رجوع</a>
        </div>
        <form method="POST" action="{{ route('workspace.finance.expenses.update', $expense) }}" enctype="multipart/form-data" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2">
            @csrf
            @method('PUT')
            <input type="date" name="expense_date" value="{{ old('expense_date', optional($expense->expense_date)->toDateString()) }}" class="rounded-lg border-slate-300 text-sm" required>
            <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $expense->amount) }}" class="rounded-lg border-slate-300 text-sm" required>
            <select name="tax_profile_type" class="rounded-lg border-slate-300 text-sm">
                @foreach($taxRates as $rate)
                    <option value="{{ $rate->type }}" @selected(old('tax_profile_type') === $rate->type)>{{ $rate->name }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" min="0" max="100" name="tax_rate" value="{{ old('tax_rate', $expense->tax_rate) }}" class="rounded-lg border-slate-300 text-sm">
            <select name="payment_method" class="rounded-lg border-slate-300 text-sm">
                @foreach(['cash', 'bank_transfer', 'card', 'other', 'credit'] as $method)
                    <option value="{{ $method }}" @selected(old('payment_method', $expense->payment_method) === $method)>{{ $method }}</option>
                @endforeach
            </select>
            <input type="file" name="attachment_file" class="rounded-lg border-slate-300 text-sm">
            <textarea name="description" rows="3" class="rounded-lg border-slate-300 text-sm sm:col-span-2">{{ old('description', $expense->description) }}</textarea>
            <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white sm:col-span-2">حفظ المسودة</button>
        </form>
    </div>
@endsection
