@php
    $guideText = static function (?string $field) use ($guide): string {
        return (string) old(
            $field === null ? 'guide' : "guide.{$field}",
            $field === null ? null : data_get($guide, $field),
        );
    };
    $guideList = static function (string $field) use ($guide): string {
        $value = old("guide.{$field}", data_get($guide, $field, []));

        return is_array($value) ? collect($value)->join(PHP_EOL) : (string) $value;
    };
    $faqItems = data_get($guide, 'faq_items', []);
@endphp

<section class="rounded-xl border border-slate-200 bg-slate-50 p-5" dir="rtl">
    <div>
        <h2 class="text-lg font-bold text-slate-900">دليل العمل</h2>
        <p class="mt-1 text-sm text-slate-600">
            معلومات تشغيلية خاصة بهذا العامل وهذا الارتباط فقط. الحقول اختيارية، وسطر واحد لكل مهمة أو خطوة أو قاعدة.
        </p>
    </div>

    <div class="mt-4 grid gap-4">
        <label class="block text-sm text-slate-700">
            عن الشركة
            <textarea name="guide[company_overview]" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-slate-300">{{ $guideText('company_overview') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            ماذا نبيع؟
            <textarea name="guide[products_context]" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-slate-300">{{ $guideText('products_context') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            المهام والمسؤوليات
            <textarea name="guide[responsibilities]" rows="4" maxlength="3600" class="mt-1 w-full rounded-lg border-slate-300" placeholder="مهمة في كل سطر">{{ $guideList('responsibilities') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            طريقة العمل
            <textarea name="guide[workflow_steps]" rows="4" maxlength="3600" class="mt-1 w-full rounded-lg border-slate-300" placeholder="خطوة في كل سطر">{{ $guideList('workflow_steps') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            قواعد وتعليمات مهمة
            <textarea name="guide[important_rules]" rows="4" maxlength="3600" class="mt-1 w-full rounded-lg border-slate-300" placeholder="قاعدة في كل سطر">{{ $guideList('important_rules') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            التعامل مع العملاء
            <textarea name="guide[customer_guidance]" rows="4" maxlength="3600" class="mt-1 w-full rounded-lg border-slate-300" placeholder="إرشاد في كل سطر">{{ $guideList('customer_guidance') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            متى أتواصل مع المسؤول؟
            <textarea name="guide[escalation_rules]" rows="3" maxlength="3600" class="mt-1 w-full rounded-lg border-slate-300" placeholder="حالة في كل سطر">{{ $guideList('escalation_rules') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            أوقات ونظام العمل
            <textarea name="guide[work_schedule_notes]" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300">{{ $guideText('work_schedule_notes') }}</textarea>
        </label>

        <label class="block text-sm text-slate-700">
            ملاحظات مهمة
            <textarea name="guide[important_notes]" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-slate-300">{{ $guideText('important_notes') }}</textarea>
        </label>

        <div>
            <h3 class="text-sm font-semibold text-slate-700">أسئلة شائعة / معلومات سريعة</h3>
            <p class="mt-1 text-xs text-slate-500">يمكن إضافة حتى 12 سؤالاً. اترك الصفوف غير المستخدمة فارغة.</p>
            <div class="mt-3 grid gap-3">
                @for ($index = 0; $index < 3; $index++)
                    @php($faq = is_array($faqItems) ? ($faqItems[$index] ?? []) : [])
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <label class="block text-xs font-semibold text-slate-600">
                            السؤال
                            <input
                                name="guide[faq_items][{{ $index }}][question]"
                                value="{{ old("guide.faq_items.{$index}.question", $faq['question'] ?? '') }}"
                                maxlength="300"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            >
                        </label>
                        <label class="mt-2 block text-xs font-semibold text-slate-600">
                            الإجابة
                            <textarea
                                name="guide[faq_items][{{ $index }}][answer]"
                                rows="2"
                                maxlength="1000"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                            >{{ old("guide.faq_items.{$index}.answer", $faq['answer'] ?? '') }}</textarea>
                        </label>
                    </div>
                @endfor
            </div>
        </div>
    </div>
</section>
