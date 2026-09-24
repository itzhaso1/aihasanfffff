<?php

namespace App\Services\Finance\Assistant;

use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\AiProviderManager;
use App\Services\AI\AiSafety;

final class FinanceAssistantAnswerComposer
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiSafety $safety,
        private readonly FinancialAssistantMetricRegistry $metrics,
    ) {}

    /**
     * @param  list<FinanceAssistantToolResult>  $results
     */
    public function compose(Workspace $workspace, string $question, array $results, string $locale = 'ar'): string
    {
        $usable = array_values(array_filter($results, fn (FinanceAssistantToolResult $result): bool => $result->available));
        if ($usable === []) {
            foreach ($results as $result) {
                if ($result->reason === 'product_profit_not_available') {
                    return $locale === 'ar'
                        ? 'ربح المنتج على مستوى التكلفة غير متاح حاليًا من السجلات المالية، ولا يمكن استخدام صافي ربح الشركة كبديل.'
                        : 'Product-level cost and profit is not available from finance records yet, and company net profit cannot be used as a substitute.';
                }
                if ($result->reason === 'period_not_supported') {
                    return $locale === 'ar'
                        ? 'لا أستطيع الإجابة عن الفترة المطلوبة من السجلات المتاحة.'
                        : 'I cannot answer for the requested period from available records.';
                }
                if ($result->reason === 'permission_denied') {
                    return $locale === 'ar'
                        ? 'لا تملك صلاحية الوصول إلى بعض البيانات المطلوبة لهذا السؤال.'
                        : 'You do not have permission to access some data required for this question.';
                }
            }

            return $locale === 'ar'
                ? 'لا أستطيع إعطاء رقم دقيق من السجلات المتاحة لهذا السؤال.'
                : 'I cannot provide an exact figure from the available records for this question.';
        }

        if (! $this->providerConfigured()) {
            return $this->deterministicAnswer($usable, $locale);
        }

        $factsJson = json_encode(array_map(fn (FinanceAssistantToolResult $r): array => $r->toFactArray(), $usable), JSON_UNESCAPED_UNICODE);
        $setting = AiSetting::query()->first();
        $providerName = $this->safety->resolveProvider($setting?->provider);
        $provider = $this->providerManager->resolve($providerName);
        $model = $this->safety->resolveModel($providerName, $setting?->model);

        $metricLines = implode("\n", $this->metrics->promptLines($locale));
        $system = implode("\n", [
            'You are the HASEM Financial Assistant.',
            'Use ONLY the JSON facts provided. Never invent numbers.',
            'Speak naturally in '.($locale === 'ar' ? 'Arabic' : 'English').'. Never mention tools or internal systems.',
            'If a value is missing, say you cannot provide an exact figure.',
            'Do not mention internal IDs unless necessary.',
            'Metric definitions:',
            $metricLines,
            'Never mention payroll amounts unless payroll_paid_total is present in the facts JSON.',
            'Facts JSON:',
            (string) $factsJson,
        ]);

        try {
            $result = $provider->generate(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $this->safety->truncateInput($question, 2000)],
                ],
                model: $model,
                temperature: 0.2,
                maxTokens: 512,
            );
        } catch (\Throwable) {
            return $this->deterministicAnswer($usable, $locale);
        }

        $reply = $this->safety->sanitizeReply((string) ($result['content'] ?? ''));

        return $reply !== '' ? $reply : $this->deterministicAnswer($usable, $locale);
    }

    /**
     * @param  list<FinanceAssistantToolResult>  $results
     */
    private function deterministicAnswer(array $results, string $locale): string
    {
        $lines = [];
        foreach ($results as $result) {
            $data = $this->metrics->enrichFactData($result->data);
            $label = $this->metrics->labelForTool($result->tool, $data, $locale);

            if (isset($data['count']) && ! isset($data['value'])) {
                $lines[] = $label.': '.$data['count'];
            }
            if (isset($data['value'])) {
                $lines[] = $label.': '.$data['value'].' '.($data['currency'] ?? '');
            }
            if (isset($data['net_profit'])) {
                $lines[] = ($locale === 'ar' ? 'صافي الربح' : 'Net profit').': '.$data['net_profit'].' '.($data['currency'] ?? '');
            }
            if (isset($data['revenue'], $data['expenses'])) {
                $expensesLabel = $locale === 'ar'
                    ? ($data['expenses_label_ar'] ?? 'مصروفات التشغيل المرحّلة في الأرباح والخسائر')
                    : ($data['expenses_label_en'] ?? 'Posted ledger operating expenses (P&L)');
                $lines[] = ($locale === 'ar' ? 'الإيرادات' : 'Revenue').': '.$data['revenue'].' · '
                    .$expensesLabel.': '.$data['expenses'];
            }
            if (isset($data['payroll_paid_total'])) {
                $payrollLabel = $locale === 'ar'
                    ? ($data['payroll_label_ar'] ?? 'الرواتب المدفوعة خلال الفترة')
                    : ($data['payroll_label_en'] ?? 'Paid payroll for the period');
                $lines[] = $payrollLabel.': '.$data['payroll_paid_total'].' '.($data['currency'] ?? '');
            }
        }

        if ($lines === []) {
            return $locale === 'ar'
                ? 'تم جلب البيانات من السجلات، راجع الحقول الرقمية في facts.'
                : 'Data was fetched from records; see numeric facts.';
        }

        return implode("\n", $lines);
    }

    private function providerConfigured(): bool
    {
        return filled(config('services.openai.key')) || filled(config('services.google_ai_studio.key'));
    }
}
