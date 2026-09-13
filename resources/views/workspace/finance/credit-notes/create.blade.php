@extends('layouts.financial', ['pageTitle' => 'إشعار مالي على '.$invoice->invoice_number])

@section('content')
    @php
        $type = $type === 'debit' ? 'debit' : 'credit';
    @endphp
    <div x-data="creditNoteBuilder()" class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-slate-900">{{ $type === 'debit' ? 'إشعار مدين' : 'إشعار دائن' }}</h2>
                <p class="text-xs text-slate-500">مستند مستقل مرتبط بالفاتورة {{ $invoice->invoice_number }} — المتبقي الحالي {{ number_format((float) $invoice->amount_due, 2) }} {{ $invoice->currency }}</p>
            </div>
            <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">رجوع</a>
        </div>

        <form method="POST" action="{{ route('workspace.finance.invoices.credit-notes.store', $invoice) }}" class="space-y-4">
            @csrf
            <div class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">النوع</label>
                    <select name="type" x-model="form.type" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="credit">إشعار دائن</option>
                        <option value="debit">إشعار مدين</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الإصدار</label>
                    <input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الحالة</label>
                    <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="draft">مسودة</option>
                        <option value="issued">إصدار وترحيل محاسبي</option>
                    </select>
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">السبب</label>
                    <input type="text" name="reason" value="{{ old('reason') }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                    @error('reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('notes') }}</textarea>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <h3 class="text-sm font-bold">البنود</h3>
                    <button type="button" @click="addItem()" class="text-xs font-semibold text-[#06C2A4]">إضافة بند</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-right">الوصف</th>
                                <th class="px-3 py-2 text-right">الكمية</th>
                                <th class="px-3 py-2 text-right">السعر</th>
                                <th class="px-3 py-2 text-right">الخصم</th>
                                <th class="px-3 py-2 text-right">التصنيف</th>
                                <th class="px-3 py-2 text-right">الضريبة %</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="idx">
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-2"><input type="text" x-model="item.product_name" class="w-full rounded-md border-slate-300 text-xs"></td>
                                    <td class="px-3 py-2"><input type="number" step="0.001" x-model.number="item.quantity" @input="recalculate()" class="w-24 rounded-md border-slate-300 text-xs"></td>
                                    <td class="px-3 py-2"><input type="number" step="0.01" x-model.number="item.unit_price" @input="recalculate()" class="w-24 rounded-md border-slate-300 text-xs"></td>
                                    <td class="px-3 py-2"><input type="number" step="0.01" x-model.number="item.discount" @input="recalculate()" class="w-24 rounded-md border-slate-300 text-xs"></td>
                                    <td class="px-3 py-2">
                                        <select x-model="item.tax_type" @change="recalculate()" class="w-28 rounded-md border-slate-300 text-xs">
                                            <option value="standard">قياسية</option>
                                            <option value="zero_rated">صفرية</option>
                                            <option value="exempt">معفاة</option>
                                            <option value="out_of_scope">خارج النطاق</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2"><input type="number" step="0.01" x-model.number="item.tax_rate" @input="recalculate()" class="w-20 rounded-md border-slate-300 text-xs"></td>
                                    <td class="px-3 py-2"><button type="button" @click="removeItem(idx)" class="text-xs text-rose-600">حذف</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="space-y-1 text-sm">
                    <div class="flex justify-between"><span>الإجمالي قبل الضريبة</span><span x-text="format(summary.subtotal)"></span></div>
                    <div class="flex justify-between"><span>الخصم</span><span x-text="format(summary.discount)"></span></div>
                    <div class="flex justify-between"><span>الضريبة</span><span x-text="format(summary.tax_amount)"></span></div>
                    <div class="flex justify-between border-t pt-2 font-bold"><span>الإجمالي</span><span x-text="format(summary.total)"></span></div>
                </div>
                <input type="hidden" name="items_json" :value="serializedItems">
                @error('items_json')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
                <button class="mt-4 rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">حفظ الإشعار</button>
            </div>
        </form>
    </div>

    <script>
        function creditNoteBuilder() {
            return {
                form: { type: '{{ $type }}' },
                items: [{ product_name: '', quantity: 1, unit_price: 0, discount: 0, tax_rate: {{ (float) $invoice->tax_rate }}, tax_type: '{{ $invoice->tax_profile_type ?: 'standard' }}' }],
                summary: { subtotal: 0, discount: 0, tax_amount: 0, total: 0 },
                get serializedItems() {
                    return JSON.stringify(this.items.map((item) => ({
                        product_name: item.product_name || '',
                        quantity: Number(item.quantity || 0),
                        unit_price: Number(item.unit_price || 0),
                        discount: Number(item.discount || 0),
                        tax_rate: Number(item.tax_rate || 0),
                        tax_type: item.tax_type || 'standard',
                    })));
                },
                addItem() {
                    this.items.push({ product_name: '', quantity: 1, unit_price: 0, discount: 0, tax_rate: {{ (float) $invoice->tax_rate }}, tax_type: '{{ $invoice->tax_profile_type ?: 'standard' }}' });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                    if (this.items.length === 0) this.addItem();
                    this.recalculate();
                },
                recalculate() {
                    let subtotal = 0, discount = 0, tax = 0, total = 0;
                    this.items.forEach((item) => {
                        const qty = Math.max(0, Number(item.quantity || 0));
                        const price = Math.max(0, Number(item.unit_price || 0));
                        const lineDiscount = Math.max(0, Number(item.discount || 0));
                        const rate = item.tax_type === 'standard' ? Math.max(0, Number(item.tax_rate || 0)) : 0;
                        const lineSubtotal = Math.round((qty * price) * 100) / 100;
                        const taxable = Math.max(0, lineSubtotal - lineDiscount);
                        const taxAmount = item.tax_type === 'standard' ? Math.round((taxable * rate / 100) * 100) / 100 : 0;
                        subtotal += lineSubtotal;
                        discount += lineDiscount;
                        tax += taxAmount;
                        total += taxable + taxAmount;
                    });
                    this.summary = {
                        subtotal: Math.round(subtotal * 100) / 100,
                        discount: Math.round(discount * 100) / 100,
                        tax_amount: Math.round(tax * 100) / 100,
                        total: Math.round(total * 100) / 100,
                    };
                },
                format(value) {
                    return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },
                init() { this.recalculate(); }
            };
        }
    </script>
@endsection
