@extends('layouts.financial', ['pageTitle' => $pageTitle ?? 'منشئ الفواتير'])

@section('content')
    @php
        $invoiceStatusLabels = [
                    'draft' => 'مسودة',
                    'issued' => 'إصدار / إرسال',
        ];
        $invoice = $invoice ?? new \App\Models\Finance\FinanceInvoice(['currency' => 'SAR', 'type' => 'sales']);
        $formAction = $formAction ?? route('workspace.finance.invoices.store');
        $formMethod = $formMethod ?? 'POST';
        $isEditing = $invoice->exists;
        $rawType = old('type', request('type', $invoice->type ?? 'sales'));
        $defaultType = in_array($rawType, ['sales', 'purchase'], true) ? $rawType : 'sales';
        $rawInvoiceStatus = old('invoice_status', old('status', $invoice->invoice_status ?? 'draft'));
        $defaultInvoiceStatus = in_array($rawInvoiceStatus, ['draft', 'issued'], true) ? $rawInvoiceStatus : 'draft';
        $allowManualInvoiceNumbers = $allowManualInvoiceNumbers ?? false;
        $defaultTaxRate = (float) ($defaultTaxRate ?? \App\Services\Finance\Tax\TaxCalculationService::FALLBACK_STANDARD_RATE);
        $workspaceName = request()->attributes->get('workspace')?->name ?: 'حاسم المالية';
        $oldCustomerId = old('customer_id', $invoice->customer_id);
        $oldCustomerName = old('customer_name', $invoice->customer_name);
        $defaultPartyMode = (trim((string) $oldCustomerName) !== '' && ! $oldCustomerId) ? 'walkin' : 'registered';
        $emptyItem = [
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
        ];
        $mapItem = static function ($item) use ($invoice, $defaultTaxRate, $emptyItem): array {
            $row = is_array($item) ? $item : [
                'product_id' => $item->product_id ?? '',
                'product_name' => $item->product_name ?? '',
                'description' => $item->description ?? '',
                'unit' => $item->unit ?? '',
                'quantity' => (float) ($item->quantity ?? 1),
                'unit_price' => (float) ($item->unit_price ?? 0),
                'discount' => (float) ($item->discount ?? 0),
                'tax_rate' => (float) ($item->tax_rate ?? $defaultTaxRate),
                'tax_type' => $item->tax_profile_type ?? ($item->tax_type ?? ($invoice->tax_profile_type ?: 'standard')),
                'exemption_reason' => $item->exemption_reason ?? '',
                'exemption_code' => $item->exemption_code ?? '',
                'total' => (float) ($item->total ?? 0),
            ];

            return array_merge($emptyItem, $row, [
                'uid' => $row['uid'] ?? uniqid('line_', true),
            ]);
        };
        $decodedOldItems = json_decode((string) old('items_json', ''), true);
        if (is_array($decodedOldItems) && $decodedOldItems !== []) {
            $builderItems = collect($decodedOldItems)->map($mapItem)->values();
        } elseif ($invoice->exists) {
            $builderItems = $invoice->items->map($mapItem)->values();
        } elseif (! empty($contractPrefillItems ?? [])) {
            $builderItems = collect($contractPrefillItems)->map($mapItem)->values();
        } else {
            $builderItems = collect([$emptyItem]);
        }
        $linkedContract = $selectedContract ?? null;
        $linkedContractSummary = $contractSummary ?? null;
        $customerLookup = ($customers ?? collect())->mapWithKeys(
            fn ($customer) => [(string) $customer->id => $customer->name.' ('.$customer->phone.')']
        );
        $supplierLookup = ($suppliers ?? collect())->mapWithKeys(
            fn ($supplier) => [(string) $supplier->id => $supplier->name]
        );
    @endphp
    <div
        x-data="financeInvoiceBuilder(@js($defaultType))"
        class="hs-inv-page"
    >
        <div class="hs-inv-hero">
            <div>
                <p class="hs-inv-kicker">النظام المالي</p>
                <h2>{{ $pageTitle ?? 'إنشاء فاتورة' }}</h2>
                <p>أنشئ فاتورة مبيعات أو شراء كاملة مع الضريبة والبنود والمرفقات، ثم احفظها كمسودة أو أصدرها مباشرة.</p>
            </div>
            <div class="hs-inv-pills">
                <span class="hs-inv-pill is-live" x-text="form.type === 'purchase' ? 'فاتورة شراء' : 'فاتورة مبيعات'"></span>
                <span class="hs-inv-pill" x-text="form.invoice_status === 'issued' ? 'جاهزة للإصدار' : 'مسودة'"></span>
                <a href="{{ route('workspace.finance.invoices.index') }}" class="hs-inv-pill">رجوع للفواتير</a>
            </div>
        </div>

        @if($linkedContract)
            <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 text-sm text-emerald-950">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">عقد مرتبط</p>
                        <h3 class="mt-1 text-base font-black">{{ $linkedContract->contract_number }} · {{ $linkedContract->title }}</h3>
                        <p class="mt-1 text-emerald-800">
                            {{ $linkedContract->customer?->name ?: 'بدون عميل' }}
                            @if($linkedContract->project?->name)
                                · {{ $linkedContract->project->name }}
                            @endif
                        </p>
                    </div>
                    @if($linkedContractSummary)
                        <dl class="grid min-w-[220px] gap-1 text-xs sm:text-sm">
                            <div class="flex justify-between gap-4"><dt>قيمة العقد</dt><dd class="font-semibold">{{ $linkedContractSummary['contract_value'] }} {{ $linkedContract->currency ?: 'SAR' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>المفوتر</dt><dd class="font-semibold">{{ $linkedContractSummary['invoiced_total'] }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>غير المفوتر</dt><dd class="font-semibold">{{ $linkedContractSummary['unbilled'] }}</dd></div>
                            <div class="flex justify-between gap-4"><dt>المتبقي</dt><dd class="font-semibold">{{ $linkedContractSummary['outstanding'] }}</dd></div>
                        </dl>
                    @endif
                </div>
                @if(! empty($contractPrefillItems ?? []))
                    <p class="mt-3 text-xs text-emerald-700">تم تعبئة بنود الفاتورة من بنود العقد. يمكنك تعديلها قبل الحفظ.</p>
                @endif
            </div>
        @endif

        <form
            method="POST"
            action="{{ $formAction }}"
            enctype="multipart/form-data"
            x-ref="invoiceForm"
            class="hs-inv-form"
        >
            @csrf
            @if(strtoupper($formMethod) !== 'POST')
                @method($formMethod)
            @endif
            <input type="hidden" name="invoice_status" :value="form.invoice_status">
            <input type="hidden" name="customer_id" :value="form.type === 'sales' && partyMode === 'registered' ? form.customer_id : ''">
            <input type="hidden" name="customer_name" :value="form.type === 'sales' && partyMode === 'walkin' ? form.customer_name : ''">

            <div class="hs-inv-layout">
                <div class="hs-inv-stack">
                <section class="hs-inv-card">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                        </span>
                        <div>
                            <h3>بيانات العميل</h3>
                            <p>اختر عميلاً مسجلاً أو أدخل عميلاً نقدياً. فواتير الشراء تتطلب مورداً.</p>
                        </div>
                    </div>
                    <div class="hs-inv-grid">
                        <div class="hs-inv-span-2">
                            <span class="hs-inv-label">نوع الطرف</span>
                            <div class="hs-inv-seg" role="group" aria-label="نوع الطرف">
                                <button type="button" :class="{ 'is-on': form.type === 'sales' }" @click="form.type = 'sales'">عميل / مبيعات</button>
                                <button type="button" :class="{ 'is-on': form.type === 'purchase' }" @click="form.type = 'purchase'">مورد / شراء</button>
                            </div>
                        </div>
                        <div class="hs-inv-span-2" x-show="form.type === 'sales'">
                            <span class="hs-inv-label">طريقة تحديد العميل <span class="hs-inv-req">*</span></span>
                            <div class="hs-inv-seg">
                                <button type="button" :class="{ 'is-on': partyMode === 'registered' }" @click="partyMode = 'registered'">عميل مسجل</button>
                                <button type="button" :class="{ 'is-on': partyMode === 'walkin' }" @click="partyMode = 'walkin'">عميل نقدي / عابر</button>
                            </div>
                        </div>
                        <div class="hs-inv-span-2" x-show="form.type === 'sales' && partyMode === 'registered'" x-cloak>
                            <label class="hs-inv-label" for="invoice-customer">العميل المسجل</label>
                            <select id="invoice-customer" x-model="form.customer_id" class="hs-inv-input">
                                <option value="">اختر عميل</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) $oldCustomerId === (string) $customer->id)>{{ $customer->name }} ({{ $customer->phone }})</option>
                                @endforeach
                            </select>
                            @error('customer_id')
                                <p class="hs-inv-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="hs-inv-span-2" x-show="form.type === 'sales' && partyMode === 'walkin'" x-cloak>
                            <label class="hs-inv-label" for="invoice-customer-name">اسم عميل نقدي/عابر <span class="hs-inv-req">*</span></label>
                            <input
                                id="invoice-customer-name"
                                type="text"
                                x-model="form.customer_name"
                                class="hs-inv-input"
                                placeholder="مثال: عميل نقدي - نقطة البيع"
                            >
                            @error('customer_name')
                                <p class="hs-inv-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="hs-inv-span-2" x-show="form.type === 'purchase'">
                            <label class="hs-inv-label" for="invoice-supplier">المورد <span class="hs-inv-req">*</span></label>
                            <select id="invoice-supplier" name="supplier_id" x-model="form.supplier_id" class="hs-inv-input">
                                <option value="">اختر مورد</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $invoice->supplier_id) === (string) $supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @error('supplier_id')
                                <p class="hs-inv-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </section>

                <section class="hs-inv-card">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </span>
                        <div>
                            <h3>معلومات الفاتورة</h3>
                            <p>تصنيف المستند والعملة والارتباطات التشغيلية. حالة الدفع تُحسب تلقائيًا بعد الحفظ.</p>
                        </div>
                    </div>
                    <div class="hs-inv-grid is-3">
                        <div>
                            <label class="hs-inv-label" for="invoice-type">نوع الفاتورة <span class="hs-inv-req">*</span></label>
                            <select id="invoice-type" name="type" x-model="form.type" class="hs-inv-input" required>
                                <option value="sales">فاتورة مبيعات</option>
                                <option value="purchase">فاتورة شراء</option>
                            </select>
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-tax-doc">تصنيف المستند الضريبي</label>
                            <select id="invoice-tax-doc" name="tax_document_subtype" x-model="form.tax_document_subtype" class="hs-inv-input">
                                <option value="standard" @selected(old('tax_document_subtype', $invoice->tax_document_subtype ?: 'standard') === 'standard')>قياسية</option>
                                <option value="simplified" @selected(old('tax_document_subtype', $invoice->tax_document_subtype) === 'simplified')>مبسطة</option>
                            </select>
                        </div>
                        <div x-show="form.type === 'sales'">
                            <label class="hs-inv-label" for="invoice-zatca">متطلب الفوترة الإلكترونية (داخلي)</label>
                            <select id="invoice-zatca" name="zatca_requirement" class="hs-inv-input">
                                <option value="not_required" @selected(old('zatca_requirement', $invoice->zatca_requirement ?: 'not_required') === 'not_required')>غير مطلوب</option>
                                <option value="required" @selected(old('zatca_requirement', $invoice->zatca_requirement) === 'required')>مطلوب لاحقاً</option>
                            </select>
                            <p class="hs-inv-help">لا يفعّل الربط مع ZATCA ولا يغيّر حالة الفاتورة. فواتير الشراء تبقى غير مطلوبة.</p>
                        </div>
                        @if($allowManualInvoiceNumbers)
                            <div>
                                <label class="hs-inv-label" for="invoice-number">رقم الفاتورة (يدوي)</label>
                                <input id="invoice-number" type="text" name="invoice_number" x-model="form.invoice_number" class="hs-inv-input" placeholder="INV-000001">
                                @error('invoice_number')
                                    <p class="hs-inv-error">{{ $message }}</p>
                                @enderror
                            </div>
                        @elseif($invoice->exists && $invoice->invoice_number)
                            <div>
                                <label class="hs-inv-label">رقم الفاتورة</label>
                                <input type="text" value="{{ $invoice->invoice_number }}" class="hs-inv-input bg-slate-50" disabled>
                            </div>
                        @else
                            <div>
                                <label class="hs-inv-label">رقم الفاتورة</label>
                                <input type="text" value="يُولَّد تلقائيًا عند الحفظ" class="hs-inv-input bg-slate-50" disabled>
                            </div>
                        @endif
                        <div>
                            <label class="hs-inv-label" for="invoice-currency">العملة</label>
                            <input id="invoice-currency" type="text" name="currency" x-model="form.currency" class="hs-inv-input" maxlength="3">
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-contract">العقد المرتبط</label>
                            <select id="invoice-contract" name="contract_id" class="hs-inv-input">
                                <option value="">بدون عقد</option>
                                @foreach($contracts ?? [] as $contract)
                                    <option value="{{ $contract->id }}" @selected((string) old('contract_id', $invoice->contract_id) === (string) $contract->id)>{{ $contract->contract_number }} · {{ $contract->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-project">المشروع</label>
                            <select id="invoice-project" name="project_id" class="hs-inv-input">
                                <option value="">بدون مشروع</option>
                                @foreach($projects ?? [] as $project)
                                    <option value="{{ $project->id }}" @selected((string) old('project_id', $invoice->project_id) === (string) $project->id)>{{ $project->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </section>

                <section class="hs-inv-card">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3M4 11h16M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        </span>
                        <div>
                            <h3>التواريخ والشروط</h3>
                            <p>تاريخ الإصدار إلزامي. تاريخ الاستحقاق والشروط يظهران على الفاتورة المطبوعة.</p>
                        </div>
                    </div>
                    <div class="hs-inv-grid">
                        <div>
                            <label class="hs-inv-label" for="invoice-issue-date">تاريخ الإصدار <span class="hs-inv-req">*</span></label>
                            <input id="invoice-issue-date" type="date" name="issue_date" x-model="form.issue_date" class="hs-inv-input" required>
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-due-date">تاريخ الاستحقاق</label>
                            <input id="invoice-due-date" type="date" name="due_date" x-model="form.due_date" class="hs-inv-input">
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-supply-date">تاريخ التوريد</label>
                            <input id="invoice-supply-date" type="date" name="supply_date" value="{{ old('supply_date', optional($invoice->supply_date)->format('Y-m-d')) }}" class="hs-inv-input">
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-terms">الشروط</label>
                            <input id="invoice-terms" type="text" name="payment_terms" x-model="form.payment_terms" class="hs-inv-input" placeholder="مثال: صافي 30 يوم">
                        </div>
                    </div>
                </section>

                <section class="hs-inv-card">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 10v-1" /></svg>
                        </span>
                        <div>
                            <h3>الضريبة</h3>
                            <p>القيم الافتراضية للبنود الجديدة. الحساب النهائي يتم في الخادم. المعاينة هنا تقريبية.</p>
                        </div>
                    </div>
                    <div class="hs-inv-grid is-3">
                        <div>
                            <label class="hs-inv-label" for="invoice-tax-type">نوع الضريبة</label>
                            <select id="invoice-tax-type" x-model="form.tax_profile_type" name="tax_profile_type" class="hs-inv-input">
                                @foreach($taxRates as $rate)
                                    <option value="{{ $rate->type }}">{{ $rate->name }} ({{ number_format((float) $rate->rate, 2) }}%)</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-tax-rate">نسبة VAT %</label>
                            <input id="invoice-tax-rate" type="number" step="0.01" min="0" max="100" x-model.number="form.tax_rate" name="tax_rate" class="hs-inv-input">
                        </div>
                        <div>
                            <label class="hs-inv-label" for="invoice-tax-mode">تسعير الضريبة</label>
                            <select id="invoice-tax-mode" name="tax_price_mode" x-model="form.tax_price_mode" class="hs-inv-input">
                                <option value="exclusive" @selected(old('tax_price_mode', $invoice->tax_price_mode ?: 'exclusive') === 'exclusive')>غير شامل الضريبة</option>
                                <option value="inclusive" @selected(old('tax_price_mode', $invoice->tax_price_mode) === 'inclusive')>شامل الضريبة</option>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="hs-inv-card">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h10" /></svg>
                        </span>
                        <div>
                            <h3>بنود الفاتورة</h3>
                            <p>يمكن إدخال بند حر بالكامل دون اختيار منتج من الكتالوج.</p>
                        </div>
                    </div>
                    <div class="hs-inv-table-wrap">
                        <table class="hs-inv-table">
                            <thead>
                                <tr>
                                    <th>ترتيب</th>
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
                                        <td data-label="ترتيب">
                                            <div class="flex gap-1">
                                                <button type="button" class="hs-inv-icon-btn" @click="moveItem(idx, -1)" :disabled="idx === 0" title="أعلى">↑</button>
                                                <button type="button" class="hs-inv-icon-btn" @click="moveItem(idx, 1)" :disabled="idx === items.length - 1" title="أسفل">↓</button>
                                            </div>
                                        </td>
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
                </section>

                <section class="hs-inv-card">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 8h10M7 12h6m-6 8h10a2 2 0 002-2V6a2 2 0 00-2-2H7l-3 4v10a2 2 0 002 2z" /></svg>
                        </span>
                        <div>
                            <h3>الملاحظات والمرفقات</h3>
                            <p>الملاحظات تظهر على PDF. حتى 10 ملفات، الحد الأقصى 10MB للملف.</p>
                        </div>
                    </div>
                    <div class="hs-inv-grid">
                        <div class="hs-inv-span-2">
                            <label class="hs-inv-label" for="invoice-notes">ملاحظات</label>
                            <textarea id="invoice-notes" name="notes" x-model="form.notes" rows="4" class="hs-inv-input"></textarea>
                        </div>
                        <div class="hs-inv-span-2">
                            <label class="hs-inv-label" for="invoice-attachments">مرفقات (اختياري)</label>
                            <input id="invoice-attachments" type="file" name="attachments[]" multiple class="hs-inv-input">
                        </div>
                    </div>
                </section>
                </div>

            <aside class="hs-inv-side">
                <section class="hs-inv-card hs-inv-summary">
                    <div class="hs-inv-card-head">
                        <span class="hs-inv-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3v18h18M7 15l3-3 3 3 5-6" /></svg>
                        </span>
                        <div>
                            <h3>ملخص الفاتورة</h3>
                            <p>معاينة لحظية قبل إعادة الحساب في الخادم.</p>
                        </div>
                    </div>
                    <div class="hs-inv-sum-row"><span>المجموع قبل الخصم</span><strong x-text="format(summary.subtotal)"></strong></div>
                    <div class="hs-inv-sum-row"><span>الخصم</span><strong x-text="format(summary.discount)"></strong></div>
                    <div class="hs-inv-sum-row"><span>المبلغ الخاضع للضريبة</span><strong x-text="format(summary.taxable_amount)"></strong></div>
                    <div class="hs-inv-sum-row"><span>ضريبة القيمة المضافة</span><strong x-text="format(summary.tax_amount)"></strong></div>
                    <template x-for="(bucket, key) in summary.categories" :key="key">
                        <div class="hs-inv-sum-row text-xs">
                            <span x-text="bucket.label"></span>
                            <strong x-text="format(bucket.tax_amount)"></strong>
                        </div>
                    </template>
                    <div class="hs-inv-sum-total">
                        <span>الإجمالي النهائي</span>
                        <b><span x-text="format(summary.total)"></span> <span x-text="form.currency"></span></b>
                    </div>
                    <div class="hs-inv-sum-row"><span>المدفوع</span><strong x-text="format(0)"></strong></div>
                    <div class="hs-inv-sum-row"><span>المتبقي</span><strong class="hs-inv-remain" x-text="format(summary.total)"></strong></div>
                    <input type="hidden" name="items_json" :value="serializedItems">
                    @error('items_json')
                        <p class="hs-inv-error">{{ $message }}</p>
                    @enderror
                    <p class="hs-inv-help">سيتم إعادة حساب جميع القيم في الـBackend قبل الحفظ لضمان الدقة المحاسبية.</p>
                </section>
            </aside>
            </div>

            <div class="hs-inv-actions">
                <a href="{{ route('workspace.finance.invoices.index') }}" class="hs-inv-btn hs-inv-btn-ghost">إلغاء</a>
                <button type="button" class="hs-inv-btn hs-inv-btn-secondary" @click="previewOpen = true">معاينة الفاتورة</button>
                <button type="button" class="hs-inv-btn hs-inv-btn-secondary" :disabled="submitting" @click="submitInvoice('draft')">
                    {{ $isEditing ? 'حفظ التعديلات' : 'حفظ كمسودة' }}
                </button>
                <button type="button" class="hs-inv-btn hs-inv-btn-primary" :disabled="submitting" @click="submitInvoice('issued')">إصدار الفاتورة</button>
            </div>
        </form>

        <div
            x-cloak
            x-show="previewOpen"
            class="hs-inv-preview"
            role="dialog"
            aria-modal="true"
            aria-labelledby="invoice-preview-title"
            @keydown.escape.window="previewOpen = false"
        >
            <button type="button" class="hs-inv-preview-scrim" @click="previewOpen = false" aria-label="إغلاق المعاينة"></button>
            <div class="hs-inv-preview-sheet">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p class="hs-inv-kicker">معاينة تقريبية</p>
                        <h3 id="invoice-preview-title" class="text-lg font-black text-slate-900">{{ $workspaceName }}</h3>
                        <p class="mt-1 text-sm text-slate-500" x-text="(form.type === 'purchase' ? 'فاتورة شراء' : 'فاتورة مبيعات') + ' · ' + (form.invoice_number || 'رقم تلقائي')"></p>
                    </div>
                    <button type="button" class="hs-inv-btn hs-inv-btn-secondary" @click="previewOpen = false">إغلاق</button>
                </div>
                <div class="mb-4 grid gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3 text-sm md:grid-cols-2">
                    <div>
                        <p class="text-xs font-bold text-slate-500">الطرف</p>
                        <p class="font-semibold text-slate-900" x-text="previewPartyName()"></p>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-500">التواريخ</p>
                        <p class="font-semibold text-slate-900" x-text="'إصدار ' + (form.issue_date || '—') + ' · استحقاق ' + (form.due_date || '—')"></p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full min-w-[28rem] text-sm">
                    <thead>
                        <tr class="text-slate-500">
                            <th class="py-2 text-start">البند</th>
                            <th class="py-2 text-start">الكمية</th>
                            <th class="py-2 text-start">السعر</th>
                            <th class="py-2 text-start">الخصم</th>
                            <th class="py-2 text-start">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="item in items" :key="'preview-'+item.uid">
                            <tr class="border-t border-slate-100">
                                <td class="py-2">
                                    <div class="font-semibold" x-text="item.product_name || item.description || 'بند'"></div>
                                    <div class="text-xs text-slate-500" x-text="item.description && item.description !== item.product_name ? item.description : ''"></div>
                                </td>
                                <td class="py-2" x-text="item.quantity"></td>
                                <td class="py-2" x-text="format(item.unit_price)"></td>
                                <td class="py-2" x-text="format(item.discount)"></td>
                                <td class="py-2 font-bold" x-text="format(item.total)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
                <div class="mt-4 space-y-1 text-sm">
                    <div class="flex justify-between"><span>قبل الخصم</span><span x-text="format(summary.subtotal)"></span></div>
                    <div class="flex justify-between"><span>الخصم</span><span x-text="format(summary.discount)"></span></div>
                    <div class="flex justify-between"><span>الخاضع للضريبة</span><span x-text="format(summary.taxable_amount)"></span></div>
                    <div class="flex justify-between"><span>الضريبة</span><span x-text="format(summary.tax_amount)"></span></div>
                    <div class="flex justify-between border-t pt-2 text-base font-black text-[#049e86]"><span>الإجمالي النهائي</span><span x-text="format(summary.total) + ' ' + form.currency"></span></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function financeInvoiceBuilder(defaultType) {
            return {
                previewOpen: false,
                submitting: false,
                partyMode: @js($defaultPartyMode),
                customers: @json($customerLookup),
                suppliers: @json($supplierLookup),
                form: {
                    type: defaultType || 'sales',
                    invoice_status: @js($defaultInvoiceStatus),
                    customer_id: @js((string) ($oldCustomerId ?: '')),
                    customer_name: @js((string) $oldCustomerName),
                    supplier_id: @js((string) old('supplier_id', $invoice->supplier_id ?: '')),
                    invoice_number: @js((string) old('invoice_number', $invoice->invoice_number ?: '')),
                    tax_document_subtype: @js((string) old('tax_document_subtype', $invoice->tax_document_subtype ?: 'standard')),
                    currency: @js((string) old('currency', $invoice->currency ?: 'SAR')),
                    issue_date: @js((string) old('issue_date', optional($invoice->issue_date)->format('Y-m-d') ?? now()->toDateString())),
                    due_date: @js((string) old('due_date', optional($invoice->due_date)->format('Y-m-d'))),
                    payment_terms: @js((string) old('payment_terms', $invoice->payment_terms)),
                    notes: @js((string) old('notes', $invoice->notes)),
                    tax_profile_type: 'standard',
                    tax_rate: {{ $defaultTaxRate }},
                    tax_price_mode: '{{ old('tax_price_mode', $invoice->tax_price_mode ?: 'exclusive') }}',
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
                previewPartyName() {
                    if (this.form.type === 'purchase') {
                        return this.suppliers[this.form.supplier_id] || 'مورد غير محدد';
                    }
                    if (this.partyMode === 'walkin') {
                        return this.form.customer_name || 'عميل نقدي';
                    }
                    return this.customers[this.form.customer_id] || this.form.customer_name || 'عميل غير محدد';
                },
                submitInvoice(status) {
                    this.form.invoice_status = status;
                    this.submitting = true;
                    this.$nextTick(() => this.$refs.invoiceForm.submit());
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
                moveItem(index, direction) {
                    const next = index + direction;
                    if (next < 0 || next >= this.items.length) return;
                    const moved = this.items.splice(index, 1)[0];
                    this.items.splice(next, 0, moved);
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
                    this.$watch('previewOpen', (open) => {
                        document.body.classList.toggle('overflow-hidden', Boolean(open));
                    });
                }
            };
        }
    </script>
@endsection
