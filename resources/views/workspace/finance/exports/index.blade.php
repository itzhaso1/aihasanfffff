@extends('layouts.financial', ['pageTitle' => 'تصدير البيانات'])

@section('content')
    <div class="space-y-4">
        <div>
            <h2 class="text-xl font-black text-slate-900">تصدير CSV</h2>
            <p class="text-sm text-slate-500">تصدير من الخادم ضمن مساحة العمل الحالية. لا تُصدَّر أسرار أو بيانات منشآت أخرى.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach([
                ['invoices', 'الفواتير', 'رقم وحالة المستند والدفع والمبالغ'],
                ['payments', 'الدفعات', 'تاريخ وطريقة ومبلغ كل دفعة مالية'],
                ['customers', 'أرصدة العملاء', 'الرصيد المستحق من فواتير المبيعات الصادرة'],
                ['expenses', 'المصروفات', 'المبلغ والضريبة والحالة'],
                ['quotes', 'عروض الأسعار', 'الحالة والنتيجة والإجمالي'],
            ] as [$dataset, $label, $hint])
                <a href="{{ route('workspace.finance.exports.download', $dataset) }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:shadow-md">
                    <p class="text-sm font-bold text-slate-900">{{ $label }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
                    <p class="mt-3 text-xs font-semibold text-[#06C2A4]">تنزيل CSV</p>
                </a>
            @endforeach
        </div>
    </div>
@endsection
