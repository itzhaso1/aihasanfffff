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
    <div x-data="financeQuoteBuilder()" class="hs-inv-page">
        <div class="hs-inv-hero">
            <div>
                <p class="hs-inv-kicker">النظام المالي</p>
                <h2>{{ $pageTitle ?? 'منشئ عروض الأسعار' }}</h2>
                <p>أدخل بنود العرض والضريبة ثم احفظ المسودة أو أصدر العرض. الحساب النهائي يتم في الخادم.</p>
            </div>
            <a href="{{ route('workspace.finance.quotes.index') }}" class="hs-inv-pill">رجوع لعروض الأسعار</a>
        </div>

        <form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="hs-inv-form">
            @csrf
            @if(strtoupper($formMethod) !== 'POST')
                @method($formMethod)
            @endif

            <div class="hs-inv-card hs-inv-grid is-3">
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
                <div class="hs-inv-span-3">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">الشروط</label>
                    <textarea name="terms" rows="2" class="w-full rounded-lg border-slate-300 text-sm" placeholder="شروط العرض">{{ old('terms', $quote->terms) }}</textarea>
                </div>
                <div class="hs-inv-span-3">
                    <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $quote->notes) }}</textarea>
                </div>
            </div>

            <section class="hs-inv-card">
                <div class="hs-inv-table-wrap">
                    <table class="hs-inv-table">
                        <thead>
                            <tr>
                                <th>المنتج (اختياري)</th>
                                <th>اسم البند</th>
                                <th>الوحدة</th>
                                <th>الوصف</th>
                                <th>الكمية</th>
                                <th>سعر الوحدة</th>
                                <th>الخصم</th>
                                <th>تصنيف الضريبة</th>
                                <th>نسبة الضريبة %</th>
                                <th>سبب الإعفاء</th>
                                <th>الإجمالي</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="item.uid">
                                <tr>
                                    <td data-label="المنتج (اختياري)" data-span="all">
                                        <select class="hs-inv-input" :value="item.product_id" @change="applyProduct(idx, $event)">
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
                                    <td data-label="اسم البند" data-span="all">
                                        <input type="text" x-model="item.product_name" class="hs-inv-input" placeholder="مثال: عزل أسطح">
                                    </td>
                                    <td data-label="الوحدة">
                                        <input type="text" x-model="item.unit" maxlength="32" class="hs-inv-input" placeholder="متر">
                                    </td>
                                    <td data-label="الوصف" data-span="all">
                                        <textarea x-model="item.description" class="hs-inv-input" rows="2" placeholder="وصف اختياري"></textarea>
                                    </td>
                                    <td data-label="الكمية">
                                        <input type="number" step="0.001" min="0.001" x-model.number="item.quantity" @input="recalculate()" class="hs-inv-input">
                                    </td>
                                    <td data-label="سعر الوحدة">
                                        <input type="number" step="0.01" min="0" x-model.number="item.unit_price" @input="recalculate()" class="hs-inv-input">
                                    </td>
                                    <td data-label="الخصم">
                                        <input type="number" step="0.01" min="0" x-model.number="item.discount" @input="recalculate()" class="hs-inv-input">
                                    </td>
                                    <td data-label="تصنيف الضريبة">
                                        <select x-model="item.tax_type" @change="recalculate()" class="hs-inv-input">
                                            <option value="standard">قياسية</option>
                                            <option value="zero_rated">صفرية</option>
                                            <option value="exempt">معفاة</option>
                                            <option value="out_of_scope">خارج النطاق</option>
                                        </select>
                                    </td>
                                    <td data-label="نسبة الضريبة %">
                                        <input type="number" step="0.01" min="0" max="100" x-model.number="item.tax_rate" @input="recalculate()" class="hs-inv-input">
                                    </td>
                                    <td data-label="سبب الإعفاء">
                                        <input
                                            type="text"
                                            x-show="item.tax_type !== 'standard'"
                                            x-model="item.exemption_reason"
                                            maxlength="255"
                                            placeholder="اختياري"
                                            class="hs-inv-input"
                                        >
                                        <input type="hidden" x-model="item.exemption_code">
                                    </td>
                                    <td data-label="الإجمالي">
                                        <span class="hs-inv-line-total" x-text="format(item.total)"></span>
                                    </td>
                                    <td data-label="إجراءات">
                                        <button type="button" @click="removeItem(idx)" class="hs-inv-icon-btn is-danger" title="حذف">حذف</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <button type="button" @click="addItem()" class="hs-inv-add">
                    + إضافة بند
                </button>
                <p class="hs-inv-help">يمكن إدخال بند حر بالكامل دون اختيار منتج من الكتالوج.</p>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="hs-inv-card hs-inv-summary">
                    <h3 class="mb-3 text-sm font-bold text-slate-900">ملخص الحساب</h3>
                    <div class="hs-inv-sum-row"><span>الإجمالي قبل الضريبة</span><strong x-text="format(summary.subtotal)"></strong></div>
                    <div class="hs-inv-sum-row"><span>الخصم</span><strong x-text="format(summary.discount)"></strong></div>
                    <div class="hs-inv-sum-row"><span>المبلغ الخاضع للضريبة</span><strong x-text="format(summary.taxable_amount)"></strong></div>
                    <div class="hs-inv-sum-row"><span>ضريبة القيمة المضافة</span><strong x-text="format(summary.tax_amount)"></strong></div>
                    <div class="hs-inv-sum-total">
                        <span>الإجمالي النهائي</span>
                        <b x-text="format(summary.total)"></b>
                    </div>
                    <template x-for="(bucket, key) in summary.categories" :key="key">
                        <div class="hs-inv-sum-row text-xs">
                            <span x-text="bucket.label"></span>
                            <strong x-text="format(bucket.tax_amount)"></strong>
                        </div>
                    </template>
                </section>

                <section class="hs-inv-card">
                    <h3 class="mb-3 text-sm font-bold text-slate-900">حفظ عرض السعر</h3>
                    <input type="hidden" name="items_json" :value="serializedItems">
                    @error('items_json')
                        <p class="hs-inv-error">{{ $message }}</p>
                    @enderror
                    <p class="hs-inv-help">
                        سيتم إعادة حساب جميع القيم في الخادم. عرض السعر ليس فاتورة ولا ينشئ قيدًا محاسبيًا.
                    </p>
                    <div class="mt-3">
                        <label class="hs-inv-label" for="quote-attachments">مرفقات (اختياري)</label>
                        <input id="quote-attachments" type="file" name="attachments[]" multiple class="hs-inv-input">
                    </div>
                    <button type="submit" class="hs-inv-btn hs-inv-btn-primary mt-4">
                        {{ $quote->exists ? 'حفظ التعديلات' : 'حفظ عرض السعر' }}
                    </button>
                </section>
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
                nextUid() {
                    return 'line_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
                },
                addItem() {
                    this.items.push({uid: this.nextUid(), product_id: '', product_name: '', description: '', unit: '', quantity: 1, unit_price: 0, discount: 0, tax_rate: Number(this.form.tax_rate || 0), tax_type: this.form.tax_profile_type, exemption_reason: '', exemption_code: '', total: 0});
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
                    this.items = this.items.map((item) => ({
                        ...item,
                        uid: item.uid || this.nextUid(),
                    }));
                    this.recalculate();
                    this.$watch('form.tax_profile_type', () => this.recalculate());
                    this.$watch('form.tax_rate', () => this.recalculate());
                    this.$watch('form.tax_price_mode', () => this.recalculate());
                }
            };
        }
    </script>
@endsection
