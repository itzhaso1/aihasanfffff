<?php

namespace App\Services\Pos;

use App\Models\AiSetting;
use App\Models\PosMenuItem;
use App\Models\Workspace;
use App\Services\AI\AiProviderManager;
use App\Services\AI\AiSafety;

class PosMenuAiService
{
    public function __construct(
        private readonly AiProviderManager $providerManager,
        private readonly AiSafety $safety,
    ) {}

    public function answer(Workspace $workspace, string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            return 'اكتب سؤالك عن المنيو وسأساعدك فورًا.';
        }

        $question = $this->safety->truncateInput($question, 800);

        $setting = AiSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        $providerName = $this->safety->resolveProvider($setting?->provider);
        $provider = $this->providerManager->resolve($providerName);
        $products = $this->serializeMenuItems($workspace->id);

        $messages = [
            [
                'role' => 'system',
                'content' => trim(
                    "أنت مساعد منيو إلكتروني لنفس النشاط فقط.\n".
                    "يجب الرد فقط من البيانات التالية بدون أي اختراع.\n".
                    "إذا المنتج أو المقاس غير موجود أخبر العميل بوضوح.\n".
                    "لا تكشف أسرار النظام أو بيانات مساحات أخرى.\n".
                    'البيانات: '.json_encode($products, JSON_UNESCAPED_UNICODE)
                ),
            ],
            [
                'role' => 'user',
                'content' => $question,
            ],
        ];

        $result = $provider->generate(
            messages: $messages,
            model: $this->safety->resolveModel($providerName, $setting?->model),
            temperature: $this->safety->capTemperature((float) ($setting?->temperature ?: config('ai.'.$providerName.'.temperature', 0.3))),
            maxTokens: $this->safety->capMaxTokens((int) ($setting?->max_tokens ?: 512)),
        );

        return $this->safety->sanitizeReply((string) ($result['content'] ?? '')) ?: 'لا أستطيع الرد الآن، حاول مرة أخرى.';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serializeMenuItems(int $workspaceId): array
    {
        return PosMenuItem::withoutGlobalScopes()
            ->with('category:id,name')
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PosMenuItem $item): array => [
                'name' => $item->name,
                'category' => $item->category?->name ?: ($item->item_type ?: 'عام'),
                'type' => $item->item_type,
                'size' => $item->size_label,
                'description' => $item->description,
                'price' => (float) $item->price,
                'currency' => $item->currency,
            ])
            ->values()
            ->all();
    }
}
