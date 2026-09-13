@extends('layouts.financial', ['pageTitle' => $pageTitle ?? 'منشئ عروض الأسعار'])

@section('content')
    @php
        $statusLabels = [
            'draft' => 'مسودة',
            'issued' => 'إصدار',
        ];
        $quote = $quote ?? new \App\Models\Finance\FinanceQuote(['currency' => 'SAR', 'status' => 'draft']);
        $formAction = $formAction ?? route('workspace.finance.quotes.store');
        $formMethod = $formMethod ?? 'POST';
        $rawStatus = old('status', $quote->status ?? 'draft');
        $defaultStatus = in_array($rawStatus, ['draft', 'issued'], true) ? $rawStatus : 'draft';
        $defaultTaxRate = (float) ($defaultTaxRate ?? \App\Services\Finance\Tax\TaxCalculationService::FALLBACK_STANDARD_RATE);
        $decodedOldItems = json_decode((string) old('items_json', ''), true);
        $builderItems = is_array($decodedOldItems) && $decodedOldItems !== []
            ? collect($decodedOldItems)
            : ($quote->exists
                ? $quote->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'description' => $item->description,
                    'unit' => $item->unit ?? '',
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount,
                    'tax_rate' => (float) $item->tax_rate,
                    'tax_type' => $item->tax_profile_type ?: ($quote->tax_profile_type ?: 'standard'),
                    'exemption_reason' => $item->exemption_reason ?? '',
                    'exemption_code' => $item->exemption_code ?? '',
                    'total' => (float) $item->total,
                ])->values()
                : collect([[
                    'product_id' => '',
                    'product_name' => '',
                    'description' => '',
                    'unit' => '',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'discount' => 0,
                    'tax_rate' => $defaultTaxRate,
                    'tax_type' => 'standard',
                    'exemption_reason' => '',
                    'exemption_code' => '',
                    'total' => 0,
                ]]));
    @endphp
    <div x-data="financeQuoteBuilder()" class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-slate-900">{{ $pageTitle ?? 'منشئ عروض الأسعار' }}</h2>
            <a href="{{ route('workspace.finance.quotes.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">رجوع</a>
        </div>

        <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @if(strtoupper($formMethod) !== 'POST')
                @method($formMethod)
            @endif

            <div class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">رقم عرض السعر</label>
                    @if($quote->exists && $quote->quote_number)
                        <input type="text" value="{{ $quote->quote_number }}" class="w-full rounded-lg border-slate-200 bg-slate-50 text-sm" disabled>
                    @else
                        <input type="text" value="يُولَّد تلقائيًا عند الحفظ" class="w-full rounded-lg border-slate-200 bg-slate-50 text-sm text-slate-500" disabled>
                    @endif
                </div>
                @if(! $quote->exists)
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">حالة المستند</label>
                        <select name="status" class="w-full rounded-lg border-slate-300 text-sm">
                            @foreach(['draft', 'issued'] as $status)
                                <option value="{{ $status }}" @selected($defaultStatus === $status)>{{ $statusLabels[$status] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-slate-500">عرض السعر الصادر يُقفل ماليًا ولا يُرحّل محاسبيًا.</p>
                    </div>
                @endif
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">العميل</label>
                    <select name="customer_id" class="w-full rounded-lg border-slate-300 text-sm" required>
                        <option value="">اختر عميل</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id', $quote->customer_id) == $customer->id)>{{ $customer->name }} ({{ $customer->phone }})</option>
                        @endforeach
                    </select>
                    @error('customer_id')
                        <p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الإصدار</label>
                    <input type="date" name="issue_date" value="{{ old('issue_date', optional($quote->issue_date)->format('Y-m-d') ?? now()->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الصلاحية</label>
                    <input type="date" name="expiry_date" value="{{ old('expiry_date', optional($quote->expiry_date)->format('Y-m-d')) }}" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">العملة</label>
                    <input type="text" name="currency" value="{{ old('currency', $quote->currency ?: 'SAR') }}" class="w-full rounded-lg border-slate-300 text-sm" maxlength="3">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">نوع الضريبة</label>
                    <select x-model="form.tax_profile_type" name="tax_profile_type" class="w-full rounded-lg border-slate-300 text-sm">
                        @foreach($taxRates as $rate)
                            <option value="{{ $rate->type }}">{{ $rate->name }} ({{ number_format((float) $rate->rate, 2) }}%)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">نسبة VAT %</label>
                    <input type="number" step="0.01" min="0" max="100" x-model.number="form.tax_rate" name="tax_rate" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">تسعير الضريبة</label>
                    <select name="tax_price_mode" x-model="form.tax_price_mode" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="exclusive" @selected(old('tax_price_mode', $quote->tax_price_mode ?: 'exclusive') === 'exclusive')>غير شامل الضريبة</option>
                        <option value="inclusive" @selected(old('tax_price_mode', $quote->tax_price_mode) === 'inclusive')>شامل الضريبة</option>
                    </select>
                    <p class="mt-1 text-[11px] text-slate-500">الحساب النهائي يتم في الخادم. المعاينة هنا تقريبية.</p>
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الشروط</label>
                    <textarea name="terms" rows="2" class="w-full rounded-lg border-slate-300 text-sm" placeholder="شروط العرض">{{ old('terms', $quote->terms) }}</textarea>
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $quote->notes) }}</textarea>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-right">المنتج (اختياري)</th>
                                <th class="px-3 py-2 text-right">اسم البند</th>
                                <th class="px-3 py-2 text-right">الوحدة</th>
                                <th class="px-3 py-2 text-right">الوصف</th>
                                <th class="px-3 py-2 text-right">الكمية</th>
                                <th class="px-3 py-2 text-right">سعر الوحدة</th>
                                <th class="px-3 py-2 text-right">الخصم</th>
                                <th class="px-3 py-2 text-right">تصنيف الضريبة</th>
                                <th class="px-3 py-2 text-right">نسبة الضريبة %</th>
                                <th class="px-3 py-2 text-right">سبب الإعفاء</th>
                                <th class="px-3 py-2 text-right">الإجمالي</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="(item, idx) in items" :key="idx">
                                <tr>
                                    <td class="px-3 py-2">
                                        <select class="w-40 rounded-md border-slate-300 text-xs" :value="item.product_id" @change="applyProduct(idx, $event)">
                                            <option value="">بند حر</option>
                                            @foreach($products as $product)
                                                @php $listed = ($listPrices ?? [])[$product->id] ?? null; @endphp
                                                <option
                                                    value="{{ $product->id }}"
                                                    data-name="{{ $product->name }}"
                                                    data-price="{{ $listed['price'] ?? $product->price }}"
                                                    data-tax-rate="{{ $listed['tax_rate'] ?? '' }}"
                                                >
                                                    {{ $product->name }} ({{ $product->sku }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <input type="hidden" x-model="item.product_id">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" x-model="item.product_name" class="w-40 rounded-md border-slate-300 text-xs" placeholder="مثال: عزل أسطح">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" x-model="item.unit" maxlength="32" class="w-20 rounded-md border-slate-300 text-xs" placeholder="متر">
                                    </td>
                                    <td class="px-3 py-2">
                                        <textarea x-model="item.description" class="w-44 rounded-md border-slate-300 text-xs" rows="2" placeholder="وصف اختياري"></textarea>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.001" min="0.001" x-model.number="item.quantity" @input="recalculate()" class="w-20 rounded-md border-slate-300 text-xs">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" min="0" x-model.number="item.unit_price" @input="recalculate()" class="w-24 rounded-md border-slate-300 text-xs">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" min="0" x-model.number="item.discount" @input="recalculate()" class="w-24 rounded-md border-slate-300 text-xs">
                                    </td>
                                    <td class="px-3 py-2">
                                        <select x-model="item.tax_type" @change="recalculate()" class="w-28 rounded-md border-slate-300 text-xs">
                                            <option value="standard">قياسية</option>
                                            <option value="zero_rated">صفرية</option>
                                            <option value="exempt">معفاة</option>
                                            <option value="out_of_scope">خارج النطاق</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" min="0" max="100" x-model.number="item.tax_rate" @input="recalculate()" class="w-20 rounded-md border-slate-300 text-xs">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input
                                            type="text"
                                            x-show="item.tax_type !== 'standard'"
                                            x-model="item.exemption_reason"
                                            maxlength="255"
                                            placeholder="اختياري"
                                            class="w-36 rounded-md border-slate-300 text-xs"
                                        >
                                        <input type="hidden" x-model="item.exemption_code">
                                    </td>
                                    <td class="px-3 py-2 font-semibold text-slate-900" x-text="format(item.total)"></td>
                                    <td class="px-3 py-2">
                                        <button type="button" @click="removeItem(idx)" class="rounded-md border border-red-200 px-2 py-1 text-xs text-red-600">حذف</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 p-3">
                    <button type="button" @click="addItem()" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        + إضافة بند
                    </button>
                    <p class="mt-2 text-[11px] text-slate-500">يمكن إدخال بند حر بالكامل دون اختيار منتج من الكتالوج.</p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-3 text-sm font-bold text-slate-900">ملخص الحساب</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span>الإجمالي قبل الضريبة</span><span x-text="format(summary.subtotal)"></span></div>
                        <div class="flex justify-between"><span>الخصم</span><span x-text="format(summary.discount)"></span></div>
                        <div class="flex justify-between"><span>المبلغ الخاضع للضريبة</span><span x-text="format(summary.taxable_amount)"></span></div>
                        <div class="flex justify-between"><span>ضريبة القيمة المضافة</span><span x-text="format(summary.tax_amount)"></span></div>
                        <div class="flex justify-between border-t pt-2 font-bold"><span>الإجمالي النهائي</span><span x-text="format(summary.total)"></span></div>
                        <template x-for="(bucket, key) in summary.categories" :key="key">
                            <div class="flex justify-between text-xs text-slate-500">
                                <span x-text="bucket.label"></span>
                                <span x-text="format(bucket.tax_amount)"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-3 text-sm font-bold text-slate-900">حفظ عرض السعر</h3>
                    <input type="hidden" name="items_json" :value="serializedItems">
                    @error('items_json')
                        <p class="mb-2 text-sm font-semibold text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="text-sm text-slate-500">
                        سيتم إعادة حساب جميع القيم في الخادم. عرض السعر ليس فاتورة ولا ينشئ قيدًا محاسبيًا.
                    </p>
                    <div class="mt-3">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">مرفقات (اختياري)</label>
                        <input type="file" name="attachments[]" multiple class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <button type="submit" class="mt-4 rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">
                        {{ $quote->exists ? 'حفظ التعديلات' : 'حفظ عرض السعر' }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function financeQuoteBuilder() {
            return {
                form: {
                    tax_profile_type: '{{ old('tax_profile_type', $quote->tax_profile_type ?: 'standard') }}',
                    tax_rate: {{ $defaultTaxRate }},
                    tax_price_mode: '{{ old('tax_price_mode', $quote->tax_price_mode ?: 'exclusive') }}',
                },
                items: @json($builderItems),
                summary: {
                    subtotal: 0,
                    discount: 0,
                    taxable_amount: 0,
                    tax_amount: 0,
                    total: 0,
                    categories: {}
                },
                get serializedItems() {
                    return JSON.stringify(this.items.map((item) => ({
                        product_id: item.product_id || null,
                        product_name: item.product_name || item.description || '',
                        description: item.description || '',
                        unit: item.unit || '',
                        quantity: Number(item.quantity || 0),
                        unit_price: Number(item.unit_price || 0),
                        discount: Number(item.discount || 0),
                        tax_rate: Number(item.tax_rate || this.form.tax_rate || 0),
                        tax_type: item.tax_type || this.form.tax_profile_type,
                        exemption_reason: item.exemption_reason || '',
                        exemption_code: item.exemption_code || '',
                    })));
                },
                addItem() {
                    this.items.push({product_id: '', product_name: '', description: '', unit: '', quantity: 1, unit_price: 0, discount: 0, tax_rate: Number(this.form.tax_rate || 0), tax_type: this.form.tax_profile_type, exemption_reason: '', exemption_code: '', total: 0});
                    this.recalculate();
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                    if (this.items.length === 0) this.addItem();
                    this.recalculate();
                },
                applyProduct(index, event) {
                    const option = event.target.selectedOptions[0];
                    this.items[index].product_id = option.value || '';
                    this.items[index].product_name = option.dataset.name || '';
                    if (!this.items[index].description) {
                        this.items[index].description = option.dataset.name || '';
                    }
                    if (option.dataset.price) {
                        this.items[index].unit_price = Number(option.dataset.price);
                    }
                    if (option.dataset.taxRate !== undefined && option.dataset.taxRate !== '') {
                        this.items[index].tax_rate = Number(option.dataset.taxRate);
                    }
                    this.recalculate();
                },
                recalculate() {
                    let subtotal = 0;
                    let discount = 0;
                    let taxable = 0;
                    let tax = 0;
                    let total = 0;
                    const categories = {};
                    const labels = {
                        standard: 'قياسية',
                        zero_rated: 'صفرية',
                        exempt: 'معفاة',
                        out_of_scope: 'خارج النطاق',
                    };
                    const priceMode = this.form.tax_price_mode || 'exclusive';
                    this.items = this.items.map((item) => {
                        const qty = Math.max(0.001, Number(item.quantity || 0));
                        const unitPrice = Math.max(0, Number(item.unit_price || 0));
                        const lineDiscount = Math.max(0, Number(item.discount || 0));
                        const taxType = item.tax_type || this.form.tax_profile_type;
                        const taxRate = taxType === 'standard'
                            ? Math.max(0, Number(item.tax_rate ?? this.form.tax_rate ?? 0))
                            : 0;
                        const lineSubtotal = this.money(qty * unitPrice);
                        const boundedDiscount = Math.min(this.money(lineDiscount), lineSubtotal);
                        const net = this.money(lineSubtotal - boundedDiscount);
                        let taxAmount = 0;
                        let taxableAmount = net;
                        let lineTotal = net;
                        if (taxType === 'standard' && taxRate > 0) {
                            if (priceMode === 'inclusive') {
                                taxAmount = this.money(net * taxRate / (100 + taxRate));
                                taxableAmount = this.money(net - taxAmount);
                                lineTotal = net;
                            } else {
                                taxAmount = this.money(net * (taxRate / 100));
                                taxableAmount = net;
                                lineTotal = this.money(taxableAmount + taxAmount);
                            }
                        }

                        subtotal += lineSubtotal;
                        discount += boundedDiscount;
                        taxable += taxableAmount;
                        tax += taxAmount;
                        total += lineTotal;

                        const key = taxType + '@' + taxRate.toFixed(2);
                        if (!categories[key]) {
                            categories[key] = { label: (labels[taxType] || taxType) + ' @ ' + taxRate.toFixed(2) + '%', tax_amount: 0 };
                        }
                        categories[key].tax_amount = this.money(categories[key].tax_amount + taxAmount);

                        return {...item, quantity: qty, unit_price: unitPrice, discount: boundedDiscount, tax_rate: taxRate, tax_type: taxType, total: lineTotal};
                    });

                    this.summary = {
                        subtotal: this.money(subtotal),
                        discount: this.money(discount),
                        taxable_amount: this.money(taxable),
                        tax_amount: this.money(tax),
                        total: this.money(total),
                        categories,
                    };
                },
                money(value) {
                    return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
                },
                format(value) {
                    return this.money(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                },
                init() {
                    this.recalculate();
                    this.$watch('form.tax_profile_type', () => this.recalculate());
                    this.$watch('form.tax_rate', () => this.recalculate());
                    this.$watch('form.tax_price_mode', () => this.recalculate());
                }
            };
        }
    </script>
@endsection
