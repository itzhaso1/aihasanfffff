<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

final class WorkerEngagementGuideRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'guide' => ['nullable', 'array'],
            'guide.company_overview' => ['nullable', 'string', 'max:2000'],
            'guide.products_context' => ['nullable', 'string', 'max:2000'],
            'guide.responsibilities' => ['nullable', 'array', 'max:12'],
            'guide.responsibilities.*' => ['required', 'string', 'max:300'],
            'guide.workflow_steps' => ['nullable', 'array', 'max:12'],
            'guide.workflow_steps.*' => ['required', 'string', 'max:300'],
            'guide.important_rules' => ['nullable', 'array', 'max:12'],
            'guide.important_rules.*' => ['required', 'string', 'max:300'],
            'guide.customer_guidance' => ['nullable', 'array', 'max:12'],
            'guide.customer_guidance.*' => ['required', 'string', 'max:300'],
            'guide.escalation_rules' => ['nullable', 'array', 'max:12'],
            'guide.escalation_rules.*' => ['required', 'string', 'max:300'],
            'guide.work_schedule_notes' => ['nullable', 'string', 'max:1000'],
            'guide.important_notes' => ['nullable', 'string', 'max:2000'],
            'guide.faq_items' => ['nullable', 'array', 'max:12'],
            'guide.faq_items.*' => ['required', 'array'],
            'guide.faq_items.*.question' => ['required', 'string', 'max:300'],
            'guide.faq_items.*.answer' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [
            'guide.array' => 'صيغة دليل العمل غير صالحة.',
            'guide.*.string' => 'يجب أن يكون محتوى دليل العمل نصًا.',
            'guide.*.max' => 'تجاوز أحد حقول دليل العمل الحد المسموح.',
            'guide.responsibilities.array' => 'يجب إدخال المهام في قائمة صالحة.',
            'guide.workflow_steps.array' => 'يجب إدخال خطوات العمل في قائمة صالحة.',
            'guide.important_rules.array' => 'يجب إدخال القواعد في قائمة صالحة.',
            'guide.customer_guidance.array' => 'يجب إدخال إرشادات العملاء في قائمة صالحة.',
            'guide.escalation_rules.array' => 'يجب إدخال حالات التصعيد في قائمة صالحة.',
            'guide.faq_items.array' => 'يجب إدخال الأسئلة الشائعة في قائمة صالحة.',
            'guide.faq_items.*.array' => 'صيغة السؤال الشائع غير صالحة.',
            'guide.faq_items.*.question.required' => 'اكتب سؤال الأسئلة الشائعة.',
            'guide.faq_items.*.answer.required' => 'اكتب إجابة السؤال الشائع.',
            'guide.faq_items.*.question.max' => 'يجب ألا يتجاوز السؤال 300 حرف.',
            'guide.faq_items.*.answer.max' => 'يجب ألا تتجاوز الإجابة 1000 حرف.',
        ];

        foreach ([
            'responsibilities' => 'المهمة',
            'workflow_steps' => 'خطوة العمل',
            'important_rules' => 'القاعدة',
            'customer_guidance' => 'إرشاد العملاء',
            'escalation_rules' => 'حالة التصعيد',
        ] as $field => $label) {
            $messages["guide.{$field}.max"] = "يمكن إضافة 12 {$label} كحد أقصى.";
            $messages["guide.{$field}.*.required"] = "لا يمكن أن تكون {$label} فارغة.";
            $messages["guide.{$field}.*.max"] = "يجب ألا تتجاوز {$label} 300 حرف.";
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalize(mixed $guide): array
    {
        if (! is_array($guide)) {
            return [];
        }

        $normalized = [];
        foreach ([
            'company_overview',
            'products_context',
            'work_schedule_notes',
            'important_notes',
        ] as $field) {
            $value = self::text($guide[$field] ?? null);
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach ([
            'responsibilities',
            'workflow_steps',
            'important_rules',
            'customer_guidance',
            'escalation_rules',
        ] as $field) {
            $items = self::list($guide[$field] ?? null);
            if ($items !== []) {
                $normalized[$field] = $items;
            }
        }

        $faqItems = self::faqItems($guide['faq_items'] ?? null);
        if ($faqItems !== []) {
            $normalized['faq_items'] = $faqItems;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function prepare(mixed $guide): ?array
    {
        if (! is_array($guide)) {
            return null;
        }

        $normalized = self::normalize($guide);

        foreach ([
            'company_overview',
            'products_context',
            'work_schedule_notes',
            'important_notes',
        ] as $field) {
            if (
                array_key_exists($field, $guide)
                && $guide[$field] !== null
                && ! is_string($guide[$field])
            ) {
                $normalized[$field] = $guide[$field];
            }
        }

        foreach ([
            'responsibilities',
            'workflow_steps',
            'important_rules',
            'customer_guidance',
            'escalation_rules',
        ] as $field) {
            $value = $guide[$field] ?? null;
            if (
                (is_array($value) && array_filter($value, fn (mixed $item): bool => ! is_string($item)) !== [])
                || (! is_array($value) && ! is_string($value) && $value !== null)
            ) {
                $normalized[$field] = $value;
            }
        }

        $faqItems = $guide['faq_items'] ?? null;
        if (
            (is_array($faqItems) && array_filter($faqItems, fn (mixed $item): bool => ! is_array($item)) !== [])
            || (! is_array($faqItems) && $faqItems !== null)
        ) {
            $normalized['faq_items'] = $faqItems;
        }

        return $normalized;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn (mixed $item): ?string => self::text($item),
                $value,
            ),
            fn (?string $item): bool => $item !== null,
        ));
    }

    /**
     * @return list<array{question:string, answer:string}>
     */
    private static function faqItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = self::text($item['question'] ?? null);
            $answer = self::text($item['answer'] ?? null);
            if ($question === null && $answer === null) {
                continue;
            }

            $items[] = [
                'question' => $question ?? '',
                'answer' => $answer ?? '',
            ];
        }

        return array_values($items);
    }
}
