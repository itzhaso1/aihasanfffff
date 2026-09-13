@php
    $routePrefix = $routePrefix ?? 'workspace.finance.contracts';
    $initialItems = old('items');
    if (! is_array($initialItems)) {
        $initialItems = ($contract->items ?? collect())->map(fn ($item): array => [
            'title' => $item->title,
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->all();
    }

    if ($initialItems === []) {
        $initialItems = [[
            'title' => '',
            'description' => '',
            'quantity' => 1,
            'unit_price' => 0,
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
                        <th class="px-2 py-2 text-right">الوصف</th>
                        <th class="px-2 py-2 text-right">الكمية</th>
                        <th class="px-2 py-2 text-right">سعر الوحدة</th>
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
                                <input name="items[{{ $index }}][description]" value="{{ $item['description'] ?? '' }}" class="w-full rounded-md border-slate-300 text-sm" placeholder="وصف">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][quantity]" type="number" min="0" step="0.001" value="{{ $item['quantity'] ?? 1 }}" class="w-full rounded-md border-slate-300 text-sm">
                            </td>
                            <td class="px-2 py-2">
                                <input name="items[{{ $index }}][unit_price]" type="number" min="0" step="0.01" value="{{ $item['unit_price'] ?? 0 }}" class="w-full rounded-md border-slate-300 text-sm">
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

        window.addContractItemRow = function () {
            const tableBody = document.querySelector('#contract-items-table tbody');
            if (!tableBody) return;

            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="px-2 py-2">
                    <input name="items[${contractItemIndex}][title]" class="w-full rounded-md border-slate-300 text-sm" placeholder="اسم البند">
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
                <td class="px-2 py-2 text-left">
                    <button type="button" class="rounded-md border border-red-200 px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50" onclick="this.closest('tr').remove();">حذف</button>
                </td>
            `;
            tableBody.appendChild(row);
            contractItemIndex += 1;
        };

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
