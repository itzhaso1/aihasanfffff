@extends('layouts.pos', ['pageTitle' => 'تعديل فاتورة '.$invoice->invoice_number])

@section('content')
    <section class="space-y-4">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-bold text-slate-900">تعديل {{ $invoice->invoice_number }}</h2>
                    <p class="text-xs text-slate-500">
                        الطاولة: {{ $invoice->table?->name ?: '—' }} •
                        الإغلاق: {{ $invoice->closed_at?->format('Y-m-d H:i') }}
                    </p>
                </div>
                <a href="{{ route('workspace.pos.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">رجوع</a>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="POST" action="{{ route('workspace.pos.invoices.update', $invoice) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-slate-500">
                            <tr>
                                <th class="py-2 text-right">الصنف</th>
                                <th class="py-2 text-right">الكمية</th>
                                <th class="py-2 text-right">السعر</th>
                                <th class="py-2 text-right">خصم السطر</th>
                                <th class="py-2 text-right">حذف</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $index => $item)
                                <tr class="border-t border-slate-100 align-top">
                                    <td class="py-2">
                                        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}" />
                                        <p class="font-semibold text-slate-800">{{ $item->item_name }}{{ $item->size_label ? ' - '.$item->size_label : '' }}</p>
                                        <p class="text-[11px] text-slate-400">{{ $item->item_type ?: 'عام' }}</p>
                                    </td>
                                    <td class="py-2">
                                        <input
                                            type="number"
                                            min="1"
                                            name="items[{{ $index }}][quantity]"
                                            value="{{ old('items.'.$index.'.quantity', (int) $item->quantity) }}"
                                            class="w-24 rounded-lg border-slate-200 text-sm"
                                            required
                                        />
                                    </td>
                                    <td class="py-2">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            name="items[{{ $index }}][unit_price]"
                                            value="{{ old('items.'.$index.'.unit_price', number_format((float) $item->unit_price, 2, '.', '')) }}"
                                            class="w-28 rounded-lg border-slate-200 text-sm"
                                            required
                                        />
                                    </td>
                                    <td class="py-2">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            name="items[{{ $index }}][discount_amount]"
                                            value="{{ old('items.'.$index.'.discount_amount', number_format((float) $item->discount_amount, 2, '.', '')) }}"
                                            class="w-28 rounded-lg border-slate-200 text-sm"
                                        />
                                    </td>
                                    <td class="py-2">
                                        <label class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600">
                                            <input
                                                type="checkbox"
                                                name="items[{{ $index }}][remove]"
                                                value="1"
                                                class="rounded border-slate-300 text-rose-600"
                                                @checked((string) old('items.'.$index.'.remove') === '1')
                                            />
                                            حذف
                                        </label>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">خصم الفاتورة</span>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            name="discount_amount"
                            value="{{ old('discount_amount', number_format((float) $invoice->discount_amount, 2, '.', '')) }}"
                            class="w-full rounded-lg border-slate-200 text-sm"
                        />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs font-semibold text-slate-500">نسبة خصم % (اختيارية — تتجاوز مبلغ الخصم)</span>
                        <input
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            name="discount_percent"
                            value="{{ old('discount_percent') }}"
                            class="w-full rounded-lg border-slate-200 text-sm"
                            placeholder="مثال: 10"
                        />
                    </label>
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block text-xs font-semibold text-slate-500">ملاحظة على الفاتورة</span>
                    <textarea
                        name="notes"
                        rows="2"
                        maxlength="500"
                        class="w-full rounded-lg border-slate-200 text-sm"
                        placeholder="سبب التعديل أو ملاحظة للكاشير"
                    >{{ old('notes', data_get($invoice->metadata, 'notes')) }}</textarea>
                </label>

                <div class="flex flex-wrap gap-2">
                    <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white">حفظ التعديلات</button>
                    <a href="{{ route('workspace.pos.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">إلغاء</a>
                </div>
            </form>
        </article>
    </section>
@endsection
