<?php

namespace App\Services\Finance\Assistant;

use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\AiProviderManager;
use App\Services\AI\AiSafety;
use App\Services\Finance\Assistant\Conversation\ConversationEntityState;

/**
 * @deprecated Compatibility planner used only when the Gemini agent cannot run.
 */
final class FinanceAssistantLlmPlanner
{
    public function __construct(
        private readonly FinanceAssistantToolRegistry $registry,
        private readonly AiProviderManager $providerManager,
        private readonly AiSafety $safety,
        private readonly FinanceAssistantHeuristicPlanner $heuristicPlanner,
    ) {}

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function plan(Workspace $workspace, string $question, string $locale = 'ar', ?ConversationEntityState $context = null): array
    {
        if (! $this->providerConfigured()) {
            return $this->heuristicPlanner->plan($question, $context);
        }

        $setting = AiSetting::query()->first();
        $providerName = $this->safety->resolveProvider($setting?->provider);
        $provider = $this->providerManager->resolve($providerName);
        $model = $this->safety->resolveModel($providerName, $setting?->model);

        $catalog = json_encode($this->registry->catalog(), JSON_UNESCAPED_UNICODE);
        $system = implode("\n", [
            'You are a tool planner for a finance business assistant.',
            'Return ONLY valid JSON with this shape: {"tools":[{"name":"tool.name","arguments":{}}]}',
            'Never invent numbers or SQL.',
            'Never include workspace_id in arguments.',
            'Use only tools from this catalog:',
            $catalog,
            'Allowed argument keys: preset (today|week|month|last_month), from, to, limit.',
            'If the question is unclear, return {"tools":[]}.',
            'Locale: '.$locale,
        ]);

        try {
            $result = $provider->generate(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $this->safety->truncateInput($question, 2000)],
                ],
                model: $model,
                temperature: 0.1,
                maxTokens: 512,
            );
        } catch (\Throwable) {
            return $this->heuristicPlanner->plan($question, $context);
        }

        $parsed = $this->parseToolPlan((string) ($result['content'] ?? ''));
        if ($parsed !== []) {
            return $parsed;
        }

        return $this->heuristicPlanner->plan($question, $context);
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function parseToolPlan(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! is_array($decoded['tools'] ?? null)) {
            return [];
        }

        $plans = [];
        foreach ($decoded['tools'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            try {
                $this->registry->get($name);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $arguments = is_array($entry['arguments'] ?? null) ? $entry['arguments'] : [];
            unset($arguments['workspace_id'], $arguments['tenant'], $arguments['role'], $arguments['permissions']);
            $plans[] = [
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $plans;
    }

    private function providerConfigured(): bool
    {
        return filled(config('services.openai.key')) || filled(config('services.google_ai_studio.key'));
    }
}
