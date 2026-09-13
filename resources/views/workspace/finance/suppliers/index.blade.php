@extends('layouts.financial', ['pageTitle' => 'الموردون'])

@section('content')
    @php
        $supplierStatusLabels = [
            'active' => 'نشط',
            'inactive' => 'غير نشط',
        ];
    @endphp
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">إدارة الموردين</h2>

        <form method="POST" action="{{ route('workspace.finance.suppliers.store') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
            @csrf
            <input name="name" class="rounded-lg border-slate-300 text-sm" placeholder="اسم المورد" required>
            <input name="arabic_name" class="rounded-lg border-slate-300 text-sm" placeholder="الاسم العربي">
            <input name="phone" class="rounded-lg border-slate-300 text-sm" placeholder="الهاتف">
            <input name="email" type="email" class="rounded-lg border-slate-300 text-sm" placeholder="البريد الإلكتروني">
            <input name="vat_number" class="rounded-lg border-slate-300 text-sm" placeholder="الرقم الضريبي">
            <input name="commercial_registration" class="rounded-lg border-slate-300 text-sm" placeholder="السجل التجاري">
            <input name="payment_terms" class="rounded-lg border-slate-300 text-sm" placeholder="شروط الدفع">
            <input name="opening_balance" type="number" step="0.01" class="rounded-lg border-slate-300 text-sm" placeholder="الرصيد الافتتاحي">
            <textarea name="address" rows="2" class="rounded-lg border-slate-300 text-sm sm:col-span-2 lg:col-span-4" placeholder="العنوان"></textarea>
            <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white lg:col-span-4">إضافة مورد</button>
        </form>

        <form method="GET" action="{{ route('workspace.finance.suppliers.index') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="بحث بالاسم/الهاتف/الإيميل" class="w-full rounded-lg border-slate-300 text-sm">
        </form>

        <div class="space-y-3">
            @forelse($suppliers as $supplier)
                <details class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <summary class="cursor-pointer list-none">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $supplier->name }}</p>
                                <p class="text-xs text-slate-500">{{ $supplier->email ?? '-' }} | {{ $supplier->phone ?? '-' }}</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $supplierStatusLabels[$supplier->status] ?? $supplier->status }}</span>
                        </div>
                    </summary>
                    <form method="POST" action="{{ route('workspace.finance.suppliers.update', $supplier) }}" class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf
                        @method('PUT')
                        <input name="name" value="{{ $supplier->name }}" class="rounded-lg border-slate-300 text-sm" required>
                        <input name="arabic_name" value="{{ $supplier->arabic_name }}" class="rounded-lg border-slate-300 text-sm">
                        <input name="phone" value="{{ $supplier->phone }}" class="rounded-lg border-slate-300 text-sm">
                        <input name="email" type="email" value="{{ $supplier->email }}" class="rounded-lg border-slate-300 text-sm">
                        <input name="vat_number" value="{{ $supplier->vat_number }}" class="rounded-lg border-slate-300 text-sm">
                        <input name="commercial_registration" value="{{ $supplier->commercial_registration }}" class="rounded-lg border-slate-300 text-sm">
                        <input name="payment_terms" value="{{ $supplier->payment_terms }}" class="rounded-lg border-slate-300 text-sm">
                        <input name="opening_balance" type="number" step="0.01" value="{{ $supplier->opening_balance }}" class="rounded-lg border-slate-300 text-sm">
                        <select name="status" class="rounded-lg border-slate-300 text-sm">
                            <option value="active" @selected($supplier->status === 'active')>نشط</option>
                            <option value="inactive" @selected($supplier->status === 'inactive')>غير نشط</option>
                        </select>
                        <textarea name="address" rows="2" class="rounded-lg border-slate-300 text-sm sm:col-span-2 lg:col-span-4">{{ $supplier->address }}</textarea>
                        <button class="rounded-lg border border-[#06C2A4] px-4 py-2 text-sm font-semibold text-[#06C2A4] lg:col-span-4">تحديث المورد</button>
                    </form>
                </details>
            @empty
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">لا يوجد موردون بعد.</p>
            @endforelse
        </div>

        <div>{{ $suppliers->links() }}</div>
    </div>
@endsection
