@if($guide?->hasContent())
    <section class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-5" dir="rtl">
        <h2 class="text-lg font-bold text-slate-900">دليل العمل</h2>
        <div class="mt-4 grid gap-3">
            @foreach([
                'company_overview' => 'عن الشركة',
                'products_context' => 'ماذا نبيع؟',
                'work_schedule_notes' => 'أوقات ونظام العمل',
                'important_notes' => 'ملاحظات مهمة',
            ] as $field => $label)
                @if(filled(data_get($guide, $field)))
                    <div class="rounded-lg bg-white p-3">
                        <h3 class="font-semibold text-slate-800">{{ $label }}</h3>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ data_get($guide, $field) }}</p>
                    </div>
                @endif
            @endforeach

            @foreach([
                'responsibilities' => 'المهام والمسؤوليات',
                'workflow_steps' => 'طريقة العمل',
                'important_rules' => 'قواعد وتعليمات مهمة',
                'customer_guidance' => 'التعامل مع العملاء',
                'escalation_rules' => 'متى أتواصل مع المسؤول؟',
            ] as $field => $label)
                @if(count((array) data_get($guide, $field, [])))
                    <div class="rounded-lg bg-white p-3">
                        <h3 class="font-semibold text-slate-800">{{ $label }}</h3>
                        <ul class="mt-1 list-inside list-disc space-y-1 text-sm text-slate-700">
                            @foreach((array) data_get($guide, $field, []) as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach

            @if(count($guide->faq_items ?? []))
                <div class="rounded-lg bg-white p-3">
                    <h3 class="font-semibold text-slate-800">أسئلة شائعة / معلومات سريعة</h3>
                    <div class="mt-2 space-y-3 text-sm text-slate-700">
                        @foreach($guide->faq_items as $faq)
                            <div>
                                <p class="font-semibold">{{ $faq['question'] ?? '' }}</p>
                                <p class="mt-1 whitespace-pre-line">{{ $faq['answer'] ?? '' }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif
