<?php

namespace App\Services\Cashier\Assistant;

use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\AiProviderManager;
use App\Services\AI\AiSafety;

final class CashierAssistantAnswerComposer
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiSafety $safety,
    ) {}

    /**
     * @param  list<CashierAssistantToolResult>  $results
     */
    public function compose(Workspace $workspace, string $question, array $results, string $locale = 'ar'): string
    {
        $usable = array_values(array_filter($results, fn (CashierAssistantToolResult $result): bool => $result->available));
        if ($usable === []) {
            foreach ($results as $result) {
                if ($result->reason === 'product_profit_not_available') {
                    return $locale === 'ar'
                        ? 'ربح المنتج على مستوى التكلفة غير متاح حاليًا من سجلات الكاشير، ولا يمكن استخدام صافي ربح الشركة كبديل.'
                        : 'Product-level cost and profit is not available from cashier records yet, and company net profit cannot be used as a substitute.';
                }
                if ($result->reason === 'period_not_supported') {
                    return $locale === 'ar'
                        ? 'لا أستطيع الإجابة عن الفترة المطلوبة من السجلات المتاحة.'
                        : 'I cannot answer for the requested period from available records.';
                }
            }

            return $locale === 'ar'
                ? 'لا أستطيع إعطاء رقم دقيق من السجلات المتاحة لهذا السؤال.'
                : 'I cannot provide an exact figure from the available records for this question.';
        }

        if (! $this->providerConfigured()) {
            return $this->deterministicAnswer($usable, $locale);
        }

        $factsJson = json_encode(array_map(fn (CashierAssistantToolResult $r): array => $r->toFactArray(), $usable), JSON_UNESCAPED_UNICODE);
        $setting = AiSetting::query()->first();
        $providerName = $this->safety->resolveProvider($setting?->provider);
        $provider = $this->providerManager->resolve($providerName);
        $model = $this->safety->resolveModel($providerName, $setting?->model);

        $system = implode("\n", [
            'You are a cashier business assistant.',
            'Use ONLY the JSON facts provided. Never invent numbers.',
            'If a value is missing, say you cannot provide an exact figure.',
            'Do not mention internal IDs unless necessary.',
            'Respond in '.($locale === 'ar' ? 'Arabic' : 'English').'.',
            'Facts JSON:',
            (string) $factsJson,
        ]);

        $result = $provider->generate(
            messages: [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $this->safety->truncateInput($question, 2000)],
            ],
            model: $model,
            temperature: 0.2,
            maxTokens: 512,
        );

        $reply = $this->safety->sanitizeReply((string) ($result['content'] ?? ''));

        return $reply !== '' ? $reply : $this->deterministicAnswer($usable, $locale);
    }

    /**
     * @param  list<CashierAssistantToolResult>  $results
     */
    private function deterministicAnswer(array $results, string $locale): string
    {
        $lines = [];
        foreach ($results as $result) {
            $data = $result->data;
            if (isset($data['value'])) {
                $lines[] = ($locale === 'ar' ? 'القيمة: ' : 'Value: ').$data['value'].' '.($data['currency'] ?? '');
            }
            if (isset($data['items']) && is_array($data['items']) && $data['items'] !== []) {
                $first = $data['items'][0];
                if (is_array($first) && isset($first['product'])) {
                    $lines[] = ($locale === 'ar' ? 'أكثر منتج: ' : 'Top product: ').$first['product'];
                }
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
