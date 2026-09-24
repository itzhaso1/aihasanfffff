@php
    $routePrefix = $routePrefix ?? 'workspace.finance.contracts';
    $initialItems = old('items');
    if (! is_array($initialItems)) {
        $initialItems = ($contract->items ?? collect())->map(fn ($item): array => [
            'title' => $item->title,
            'item_kind' => $item->item_kind ?? 'service',
            'unit' => $item->unit,
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount' => (float) ($item->discount ?? 0),
            'discount_type' => $item->discount_type ?? 'amount',
            'tax_rate' => (float) ($item->tax_rate ?? 0),
            'tax_type' => $item->tax_type ?? 'standard',
        ])->all();
    }

    if ($initialItems === []) {
        $initialItems = [[
            'title' => '',
            'item_kind' => 'service',
            'unit' => '',
            'description' => '',
            'quantity' => 1,
            'unit_price' => 0,
            'discount' => 0,
            'discount_type' => 'amount',
            'tax_rate' => 0,
            'tax_type' => 'standard',
        ]];
    }
@endphp

<form method="POST" action="{{ $formAction }}" enctype="multipart/form-data" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    @csrf
    @if($method === 'PUT')
        @method('PUT')
    @endif

    <div class="grid gap-3 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">رقم العقد</label>
            <input name="contract_number" value="{{ old('contract_number', $contract->contract_number) }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="CTR-000001">
            @error('contract_number')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">العملة</label>
            <input name="currency" value="{{ old('currency', $contract->currency ?: 'SAR') }}" class="w-full rounded-lg border-slate-300 text-sm uppercase" maxlength="3">
            @error('currency')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-slate-600">عنوان العقد</label>
            <input name="title" required value="{{ old('title', $contract->title) }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="مثال: عقد تصميم وتشغيل منصة">
            @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div class="md:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-slate-600">وصف العقد</label>
            <textarea name="description" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('description', $contract->description) }}</textarea>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">العميل</label>
            <select name="customer_id" class="w-full rounded-lg border-slate-300 text-sm">
                <option value="">بدون عميل</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((string) old('customer_id', $contract->customer_id) === (string) $customer->id)>
                        {{ $customer->name }}
                    </option>
                @endforeach
            </select>
            @error('customer_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">المشروع</label>
            <select name="project_id" class="w-full rounded-lg border-slate-300 text-sm">
                <option value="">بدون مشروع</option>
                @foreach($projects ?? [] as $project)
                    <option value="{{ $project->id }}" @selected((string) old('project_id', $contract->project_id) === (string) $project->id)>
                        {{ $project->name }}
                    </option>
                @endforeach
            </select>
            @error('project_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">قيمة العقد (اختياري)</label>
            <input name="value" type="number" step="0.01" min="0" value="{{ old('value', $contract->value) }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="سيتم الحساب تلقائيًا من البنود إن وُجدت">
            @error('value')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">شروط الدفع المختصرة</label>
            <input name="payment_terms" value="{{ old('payment_terms', $contract->payment_terms) }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="مثال: 30 يوماً">
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ البداية</label>
            <input name="start_date" type="date" value="{{ old('start_date', optional($contract->start_date)->format('Y-m-d')) }}" class="w-full rounded-lg border-slate-300 text-sm">
            @error('start_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ النهاية</label>
            <input name="end_date" type="date" value="{{ old('end_date', optional($contract->end_date)->format('Y-m-d')) }}" class="w-full rounded-lg border-slate-300 text-sm">
            @error('end_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="space-y-2">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-900">بنود العقد</h3>
            <button type="button" onclick="window.addContractItemRow()" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">إضافة بند</button>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm" id="contract-items-table">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-2 py-2 text-right">العنوان</th>
                        <th class="px-2 py-2 text-right">النوع</th>
                        <th class="px-2 py-2 text-right">الوحدة</th>
                        <th class="px-2 py-2 text-right">الوصف</th>
                        <th class="px-2 py-2 text-right">الكمية</th>
                        <th class="px-2 py-2 text-right">سعر الوحدة</th>
                        <th class="px-2 py-2 text-right">خصم</th>
                        <th class="px-2 py-2 text-right">ضريبة %</th>
                        <th class="px-2 py-2 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($initialItems as $index => $item)
                        <tr>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][title]" value="{{ $item['title'] ?? '' }}" class="w-full rounded-md border-slate-300 text-sm" placeholder="اسم البند">
                            </td>
                            <td class="px-2 py-2">
                                <select name="items[{{ $index }}][item_kind]" class="w-full rounded-md border-slate-300 text-sm">
                                    @foreach(['service' => 'خدمة', 'product' => 'منتج', 'subscription' => 'اشتراك', 'one_time' => 'مرة واحدة', 'monthly' => 'شهري', 'recurring' => 'دوري'] as $kind => $kindLabel)
                                        <option value="{{ $kind }}" @selected(($item['item_kind'] ?? 'service') === $kind)>{{ $kindLabel }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][unit]" value="{{ $item['unit'] ?? '' }}" class="w-full rounded-md border-slate-300 text-sm" placeholder="وحدة">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" class="w-full rounded-md border-slate-300 text-sm" placeholder="وصف">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][quantity]" type="number" min="0" step="0.001" value="{{ $item['quantity'] ?? 1 }}" class="w-full rounded-md border-slate-300 text-sm">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][unit_price]" type="number" min="0" step="0.01" value="{{ $item['unit_price'] ?? 0 }}" class="w-full rounded-md border-slate-300 text-sm">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][discount]" type="number" min="0" step="0.01" value="{{ $item['discount'] ?? 0 }}" class="w-24 rounded-md border-slate-300 text-sm">
                                <input type="hidden" name="items[{{ $index }}][discount_type]" value="{{ $item['discount_type'] ?? 'amount' }}">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][tax_rate]" type="number" min="0" step="0.01" value="{{ $item['tax_rate'] ?? 0 }}" class="w-20 rounded-md border-slate-300 text-sm">
                                <input type="hidden" name="items[{{ $index }}][tax_type]" value="{{ $item['tax_type'] ?? 'standard' }}">
                            </td>
                            <td class="px-2 py-2 text-left">
                                <button type="button" onclick="this.closest('tr').remove();" class="rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50">حذف</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('items')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.title')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.quantity')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        @error('items.*.unit_price')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <div id="contract-totals-preview" class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h4 class="font-bold text-slate-900">معاينة القيمة (تقريبية)</h4>
                <p class="text-xs text-slate-500">الحساب النهائي يتم في الخادم عند الحفظ</p>
            </div>
            <dl class="mt-3 grid gap-2 sm:grid-cols-3">
                <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"><dt class="text-slate-600">المجموع الفرعي</dt><dd id="contract-subtotal" class="font-semibold text-slate-900">0.00</dd></div>
                <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2"><dt class="text-slate-600">الضريبة</dt><dd id="contract-tax" class="font-semibold text-slate-900">0.00</dd></div>
                <div class="flex justify-between gap-3 rounded-lg bg-emerald-50 px-3 py-2"><dt class="font-semibold text-emerald-800">الإجمالي</dt><dd id="contract-grand-total" class="font-black text-emerald-900">0.00</dd></div>
            </dl>
        </div>
    </div>

    @php $termFields = old('term_fields', is_array($contract->metadata) ? ($contract->metadata['terms'] ?? []) : []); @endphp
    <div class="grid gap-3 md:grid-cols-2">
        @foreach([
            'payment' => 'شروط الدفع',
            'delivery' => 'شروط التسليم',
            'cancellation' => 'شروط الإلغاء',
            'renewal' => 'شروط التجديد',
            'warranty' => 'الضمان',
            'termination' => 'إنهاء العقد',
            'additional' => 'شروط إضافية',
        ] as $termKey => $termLabel)
            <div>
                <label class="mb-1 block text-xs font-semibold text-slate-600">{{ $termLabel }}</label>
                <textarea name="term_fields[{{ $termKey }}]" rows="3" class="w-full rounded-lg border-slate-300 text-sm">{{ $termFields[$termKey] ?? '' }}</textarea>
            </div>
        @endforeach
    </div>

    <div class="grid gap-3 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">الشروط</label>
            <textarea name="terms" rows="5" class="w-full rounded-lg border-slate-300 text-sm" placeholder="اكتب شروط العقد...">{{ old('terms', $contract->terms) }}</textarea>
            @error('terms')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
            <textarea name="notes" rows="5" class="w-full rounded-lg border-slate-300 text-sm" placeholder="ملاحظات داخلية أو تشغيلية...">{{ old('notes', $contract->notes) }}</textarea>
            @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <article class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
        <h3 class="mb-2 text-sm font-bold text-slate-900">مرفقات العقد</h3>
        <p class="mb-3 text-xs text-slate-500">أضف عدة مرفقات، وستظهر لاحقًا ببطاقات واضحة تتضمن الاسم والنوع والحالة.</p>
        <label for="contract-attachments-input" class="flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center hover:border-slate-400">
            <svg class="mb-2 h-6 w-6 text-slate-400" viewBox="0 0 24 24" aria-hidden="true"></svg>
            <span class="text-sm font-semibold text-slate-700">اضغط لاختيار الملفات</span>
            <span class="mt-1 text-xs text-slate-500">حتى 10 ملفات، 10MB لكل ملف</span>
        </label>
        <input id="contract-attachments-input" type="file" name="attachments[]" multiple class="sr-only">
        <ul id="attachments-preview-list" class="mt-3 grid gap-2 sm:grid-cols-2"></ul>
        @error('attachments')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('attachments.*')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
    </article>

    <div class="flex flex-wrap items-center gap-2">
        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">حفظ العقد</button>
        <a href="{{ route($routePrefix.'.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">العودة</a>
    </div>
</form>

<script>
    (function () {
        const attachmentsInput = document.getElementById('contract-attachments-input');
        const attachmentsList = document.getElementById('attachments-preview-list');
        let contractItemIndex = {{ count($initialItems) }};
        const valueInput = document.querySelector('input[name="value"]');

        window.recalcContractTotals = function recalcContractTotals() {
            const table = document.getElementById('contract-items-table');
            if (!table) return;

            let subtotal = 0;
            let tax = 0;
            let total = 0;
            let hasItems = false;

            table.querySelectorAll('tbody tr').forEach(function (row) {
                const title = row.querySelector('[name*="[title]"]')?.value?.trim() || '';
                const qty = Number(row.querySelector('[name*="[quantity]"]')?.value || 0);
                const price = Number(row.querySelector('[name*="[unit_price]"]')?.value || 0);
                const discount = Number(row.querySelector('[name*="[discount]"]')?.value || 0);
                const taxRate = Number(row.querySelector('[name*="[tax_rate]"]')?.value || 0);
                if (!title && qty <= 0 && price <= 0) return;
                hasItems = true;
                const lineBase = Math.max(0, (qty * price) - discount);
                const lineTax = lineBase * (taxRate / 100);
                subtotal += lineBase;
                tax += lineTax;
                total += lineBase + lineTax;
            });

            const subtotalEl = document.getElementById('contract-subtotal');
            const taxEl = document.getElementById('contract-tax');
            const totalEl = document.getElementById('contract-grand-total');
            if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);
            if (taxEl) taxEl.textContent = tax.toFixed(2);
            if (totalEl) totalEl.textContent = total.toFixed(2);
            if (valueInput && hasItems) {
                valueInput.value = total.toFixed(2);
                valueInput.readOnly = true;
                valueInput.classList.add('bg-slate-50');
            } else if (valueInput) {
                valueInput.readOnly = false;
                valueInput.classList.remove('bg-slate-50');
            }
        }

        window.addContractItemRow = function () {
            const tableBody = document.querySelector('#contract-items-table tbody');
            if (!tableBody) return;

            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][title]" class="w-full rounded-md border-slate-300 text-sm" placeholder="اسم البند">
                </td>
                <td class="px-2 py-2">
                    <select name="items[${contractItemIndex}][item_kind]" class="w-full rounded-md border-slate-300 text-sm">
                        <option value="service">خدمة</option>
                        <option value="product">منتج</option>
                        <option value="subscription">اشتراك</option>
                        <option value="one_time">مرة واحدة</option>
                        <option value="monthly">شهري</option>
                        <option value="recurring">دوري</option>
                    </select>
                </td>
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][unit]" class="w-full rounded-md border-slate-300 text-sm" placeholder="وحدة">
                </td>
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][description]" class="w-full rounded-md border-slate-300 text-sm" placeholder="وصف">
                </td>
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][quantity]" type="number" min="0" step="0.001" value="1" class="w-full rounded-md border-slate-300 text-sm">
                </td>
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][unit_price]" type="number" min="0" step="0.01" value="0" class="w-full rounded-md border-slate-300 text-sm">
                </td>
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][discount]" type="number" min="0" step="0.01" value="0" class="w-24 rounded-md border-slate-300 text-sm">
                    <input type="hidden" name="items[${contractItemIndex}][discount_type]" value="amount">
                </td>
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][tax_rate]" type="number" min="0" step="0.01" value="0" class="w-20 rounded-md border-slate-300 text-sm">
                    <input type="hidden" name="items[${contractItemIndex}][tax_type]" value="standard">
                </td>
                <td class="px-2 py-2 text-left">
                                <button type="button" class="rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50" onclick="this.closest('tr').remove(); if (typeof recalcContractTotals === 'function') recalcContractTotals();">حذف</button>
                </td>
            `;
            tableBody.appendChild(row);
            contractItemIndex += 1;
            row.querySelectorAll('input, select').forEach(function (input) {
                input.addEventListener('input', window.recalcContractTotals);
                input.addEventListener('change', window.recalcContractTotals);
            });
            window.recalcContractTotals();
        };

        const itemsTable = document.getElementById('contract-items-table');
        if (itemsTable) {
            itemsTable.addEventListener('input', window.recalcContractTotals);
            itemsTable.addEventListener('change', window.recalcContractTotals);
            window.recalcContractTotals();
        }

        if (!attachmentsInput || !attachmentsList) {
            return;
        }

        attachmentsInput.addEventListener('change', function () {
            attachmentsList.innerHTML = '';

            if (!attachmentsInput.files || attachmentsInput.files.length === 0) {
                return;
            }

            Array.from(attachmentsInput.files).forEach(function (file) {
                const row = document.createElement('li');
                row.className = 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600';

                const sizeKb = (file.size / 1024).toFixed(1);
                row.innerHTML = `
                    <p class="truncate font-semibold text-slate-800">${file.name}</p>
                    <p class="mt-1 text-[11px]">${file.type || 'unknown'} • ${sizeKb} KB</p>
                `;

                attachmentsList.appendChild(row);
            });
        });
    })();
</script>
