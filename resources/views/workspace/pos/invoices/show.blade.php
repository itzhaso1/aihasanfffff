@extends('layouts.pos', ['pageTitle' => 'فاتورة '.$invoice->invoice_number])

@section('content')
    <section class="space-y-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-bold text-slate-900">{{ $invoice->invoice_number }}</h2>
                    <p class="text-xs text-slate-500">
                        الطاولة: {{ $invoice->table?->name ?: '—' }} •
                        الموظف: {{ $invoice->closer?->name ?: '—' }} •
                        الإغلاق: {{ $invoice->closed_at?->format('Y-m-d H:i') }}
                    </p>
                    @if(data_get($invoice->metadata, 'notes'))
                        <p class="mt-1 text-xs text-slate-600">ملاحظة: {{ data_get($invoice->metadata, 'notes') }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($canEdit ?? false)
                        <a href="{{ route('workspace.pos.invoices.edit', $invoice) }}" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white">تعديل الفاتورة</a>
                    @endif
                    <a href="{{ route('workspace.pos.invoices.print', $invoice) }}" target="_blank" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">طباعة</a>
                </div>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-slate-500">
                        <tr>
                            <th class="py-2 text-right">الصنف</th>
                            <th class="py-2 text-right">النوع</th>
                            <th class="py-2 text-right">الكمية</th>
                            <th class="py-2 text-right">السعر</th>
                            <th class="py-2 text-right">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoice->items as $item)
                            <tr class="border-t border-slate-100">
                                <td class="py-2">{{ $item->item_name }}{{ $item->size_label ? ' - '.$item->size_label : '' }}</td>
                                <td class="py-2">{{ $item->item_type ?: 'عام' }}</td>
                                <td class="py-2">{{ $item->quantity }}</td>
                                <td class="py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="py-2">{{ number_format((float) $item->total_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 grid gap-2 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3 text-sm">
                    <p class="text-xs text-slate-500">المجموع الفرعي</p>
                    <p class="font-bold">{{ number_format((float) $invoice->subtotal, 2) }}</p>
                </div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm">
                    <p class="text-xs text-slate-500">الخصم</p>
                    <p class="font-bold">{{ number_format((float) $invoice->discount_amount, 2) }}</p>
                </div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm">
                    <p class="text-xs text-slate-500">الإجمالي</p>
                    <p class="font-bold">{{ number_format((float) $invoice->total_amount, 2) }} {{ $invoice->currency }}</p>
                </div>
            </div>
        </article>
    </section>
@endsection
