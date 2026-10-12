@php
    $durationUnit = old('duration_unit', 'day');
    $durationValue = old('duration_value', 1);
    $durationPreset = old('duration_preset');

    if ($durationPreset === null) {
        $durationPreset = ((int) $durationValue === 1 && in_array($durationUnit, ['day', 'week', 'month'], true))
            ? $durationUnit
            : 'custom';
    }
@endphp

<div
    class="grid gap-4 sm:grid-cols-2"
    data-worker-engagement-duration
    data-duration-start-input="starts_at"
>
    <div>
        <x-input-label for="starts_at" value="تاريخ البداية" />
        <x-text-input
            id="starts_at"
            class="mt-1 block w-full"
            type="date"
            name="starts_at"
            :value="old('starts_at')"
            required
            data-duration-start
        />
    </div>
    <div>
        <x-input-label for="duration_preset" value="مدة التعاقد" />
        <select
            id="duration_preset"
            name="duration_preset"
            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm"
            data-duration-preset
        >
            <option value="day" @selected($durationPreset === 'day')>يوم واحد</option>
            <option value="week" @selected($durationPreset === 'week')>أسبوع واحد</option>
            <option value="month" @selected($durationPreset === 'month')>شهر واحد</option>
            <option value="custom" @selected($durationPreset === 'custom')>مدة مخصصة</option>
        </select>
    </div>
    <div data-duration-custom-fields @class(['hidden' => $durationPreset !== 'custom'])>
        <x-input-label for="duration_value" value="قيمة المدة" />
        <x-text-input
            id="duration_value"
            class="mt-1 block w-full"
            type="number"
            name="duration_value"
            min="1"
            step="1"
            :value="$durationValue"
            data-duration-value
        />
    </div>
    <div data-duration-custom-fields @class(['hidden' => $durationPreset !== 'custom'])>
        <x-input-label for="duration_unit_select" value="وحدة المدة" />
        <select
            id="duration_unit_select"
            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm"
            data-duration-unit-select
        >
            <option value="day" @selected($durationUnit === 'day')>يوم</option>
            <option value="week" @selected($durationUnit === 'week')>أسبوع</option>
            <option value="month" @selected($durationUnit === 'month')>شهر</option>
        </select>
        <input type="hidden" name="duration_unit" value="{{ $durationUnit }}" data-duration-unit />
    </div>
    <div class="sm:col-span-2">
        <p class="mt-1 text-sm text-slate-600" data-duration-preview>
            ستظهر النهاية المحسوبة بعد إدخال تاريخ البداية والمدة.
        </p>
        <p class="mt-1 text-sm font-semibold text-teal-700" data-contract-price>
            سيظهر سعر التعاقد بعد اختيار المدة.
        </p>
        <p class="mt-1 text-xs text-slate-500">
            يحسب الخادم تاريخ النهاية النهائي ويحفظه في التعاقد.
        </p>
    </div>
</div>

@once
    <script>
        (() => {
            const addMonthsNoOverflow = (date, months) => {
                const day = date.getDate();
                const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
                const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

                return new Date(
                    target.getFullYear(),
                    target.getMonth(),
                    Math.min(day, lastDay),
                );
            };

            const formatDate = (date) => [
                date.getFullYear(),
                String(date.getMonth() + 1).padStart(2, '0'),
                String(date.getDate()).padStart(2, '0'),
            ].join('-');

            document.querySelectorAll('[data-worker-engagement-duration]').forEach((root) => {
                const start = root.querySelector('[data-duration-start]');
                const preset = root.querySelector('[data-duration-preset]');
                const value = root.querySelector('[data-duration-value]');
                const unit = root.querySelector('[data-duration-unit]');
                const unitSelect = root.querySelector('[data-duration-unit-select]');
                const customFields = root.querySelectorAll('[data-duration-custom-fields]');
                const preview = root.querySelector('[data-duration-preview]');
                const contractPrice = root.querySelector('[data-contract-price]');
                const hostForm = root.closest('form');
                const contractPriceUrl = root.dataset.workerContractPriceUrl
                    || hostForm?.dataset.workerContractPriceUrl;
                let contractPriceRequest = 0;

                const update = () => {
                    const selectedPreset = preset.value;
                    const customValue = Number.parseInt(value.value, 10);
                    const selectedValue = selectedPreset === 'custom'
                        ? customValue
                        : ({ day: 1, week: 1, month: 1 }[selectedPreset] || 1);
                    const selectedUnit = selectedPreset === 'custom' ? unitSelect.value : selectedPreset;
                    const startParts = start.value.split('-').map(Number);
                    const startDate = startParts.length === 3
                        ? new Date(startParts[0], startParts[1] - 1, startParts[2])
                        : null;

                    if (selectedPreset !== 'custom') {
                        value.value = String(selectedValue);
                    }
                    value.readOnly = selectedPreset !== 'custom';
                    unit.value = selectedUnit;
                    customFields.forEach((field) => field.classList.toggle('hidden', selectedPreset !== 'custom'));

                    if (
                        startDate === null
                        || !Number.isInteger(selectedValue)
                        || selectedValue < 1
                        || Number.isNaN(startDate.getTime())
                    ) {
                        preview.textContent = 'ستظهر النهاية المحسوبة بعد إدخال تاريخ البداية والمدة.';
                        if (contractPrice) {
                            contractPrice.textContent = 'سيظهر سعر التعاقد بعد اختيار المدة.';
                        }
                        return;
                    }

                    let endDate;
                    if (selectedUnit === 'day') {
                        endDate = new Date(startDate);
                        endDate.setDate(endDate.getDate() + selectedValue);
                    } else if (selectedUnit === 'week') {
                        endDate = new Date(startDate);
                        endDate.setDate(endDate.getDate() + (selectedValue * 7));
                    } else {
                        endDate = addMonthsNoOverflow(startDate, selectedValue);
                    }

                    preview.textContent = `النهاية المحسوبة: ${formatDate(endDate)}`;

                    if (!contractPrice || !contractPriceUrl) {
                        return;
                    }

                    const requestId = ++contractPriceRequest;
                    contractPrice.textContent = 'جارٍ تحميل سعر التعاقد...';
                    const params = new URLSearchParams({
                        duration_value: String(selectedValue),
                        duration_unit: selectedUnit,
                    });
                    fetch(`${contractPriceUrl}?${params.toString()}`, {
                        headers: { Accept: 'application/json' },
                    })
                        .then(async (response) => {
                            const data = await response.json();
                            if (requestId !== contractPriceRequest) return;
                            if (!response.ok || data.amount_minor === undefined) {
                                throw new Error(data.message || 'rate-unavailable');
                            }
                            const minor = Number(data.amount_minor);
                            const major = Math.floor(minor / 100);
                            const fraction = String(Math.abs(minor % 100)).padStart(2, '0');
                            contractPrice.textContent =
                                `سعر التعاقد: ${major}.${fraction} ${data.currency || ''}`.trim();
                        })
                        .catch(() => {
                            if (requestId !== contractPriceRequest) return;
                            contractPrice.textContent = 'لا يوجد سعر منصة لهذه المدة.';
                        });
                };

                preset.addEventListener('change', update);
                start.addEventListener('input', update);
                value.addEventListener('input', update);
                unitSelect.addEventListener('change', update);
                update();
            });
        })();
    </script>
@endonce
