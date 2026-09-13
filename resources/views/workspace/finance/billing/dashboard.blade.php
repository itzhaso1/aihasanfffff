@extends('layouts.financial', ['pageTitle' => 'لوحة الفوترة'])

@php
    $cards = [
        ['label' => 'إجمالي الفواتير', 'value' => $metrics['total_invoices'] ?? 0, 'money' => false],
        ['label' => 'مسودة', 'value' => $metrics['draft'] ?? 0, 'money' => false],
        ['label' => 'معتمدة', 'value' => $metrics['issued'] ?? 0, 'money' => false],
        ['label' => 'مدفوعة', 'value' => $metrics['paid'] ?? 0, 'money' => false],
        ['label' => 'مدفوعة جزئيًا', 'value' => $metrics['partial'] ?? 0, 'money' => false],
        ['label' => 'غير مدفوعة', 'value' => $metrics['unpaid'] ?? 0, 'money' => false],
        ['label' => 'متأخرة', 'value' => $metrics['overdue'] ?? 0, 'money' => false],
        ['label' => 'ملغاة', 'value' => $metrics['cancelled'] ?? 0, 'money' => false],
        ['label' => 'مستحقة اليوم', 'value' => $metrics['due_today'] ?? 0, 'money' => false],
        ['label' => 'قادمة خلال 7 أيام', 'value' => $metrics['upcoming_due'] ?? 0, 'money' => false],
        ['label' => 'إجمالي الإيراد', 'value' => $metrics['total_revenue'] ?? 0, 'money' => true],
        ['label' => 'المتبقي', 'value' => $metrics['outstanding_amount'] ?? 0, 'money' => true],
        ['label' => 'المتأخر ماليًا', 'value' => $metrics['overdue_amount'] ?? 0, 'money' => true],
        ['label' => 'الدفعات المستلمة', 'value' => $metrics['payments_received'] ?? 0, 'money' => true],
        ['label' => 'إشعارات دائن', 'value' => $metrics['credits_issued'] ?? 0, 'money' => true],
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">لوحة الفوترة</h2>
                <p class="text-xs text-slate-500">ملخص حالات الفواتير والتحصيل داخل وحدة المالية فقط</p>
            </div>
            <a href="{{ route('workspace.finance.invoices.index') }}" class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">قائمة الفواتير</a>
        </div>

        <form method="GET" action="{{ route('workspace.finance.billing.dashboard') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6">
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-lg border-slate-300 text-sm" aria-label="من تاريخ">
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="rounded-lg border-slate-300 text-sm" aria-label="إلى تاريخ">
            <select name="customer_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل العملاء</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((string) ($filters['customer_id'] ?? '') === (string) $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
            <select name="type" class="rounded-lg border-slate-300 text-sm">
                <option value="">كل الأنواع</option>
                <option value="sales" @selected(($filters['type'] ?? '') === 'sales')>مبيعات</option>
                <option value="purchase" @selected(($filters['type'] ?? '') === 'purchase')>مشتريات</option>
            </select>
            <input type="text" name="currency" value="{{ $filters['currency'] ?? '' }}" maxlength="3" placeholder="العملة" class="rounded-lg border-slate-300 text-sm">
            <div class="flex gap-2">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تصفية</button>
                <a href="{{ route('workspace.finance.billing.dashboard') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">إعادة ضبط</a>
            </div>
        </form>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach($cards as $card)
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold text-slate-500">{{ $card['label'] }}</p>
                    <p class="mt-2 text-2xl font-extrabold text-slate-900">
                        {{ $card['money'] ? number_format((float) $card['value'], 2) : number_format((float) $card['value']) }}
                    </p>
                </article>
            @endforeach
        </section>
    </div>
@endsection
