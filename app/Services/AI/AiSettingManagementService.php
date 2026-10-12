<?php

namespace App\Services\AI;

use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidationException;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidator;
use App\Services\Platform\PlatformAiConfiguration;
use App\Support\Chat\ChatAiRole;
use Illuminate\Validation\ValidationException;

class AiSettingManagementService
{
    public function __construct(
        private readonly AiSafety $safety,
        private readonly InboxAgentPersonaValidator $personaValidator,
    ) {}

    public function forWorkspace(Workspace $workspace): AiSetting
    {
        return AiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'workspace_id' => $workspace->id,
                ...$this->defaultAttributes(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Workspace $workspace, array $validated): AiSetting
    {
        $setting = $this->forWorkspace($workspace);
        unset($validated['provider'], $validated['model']);
        $platform = app(PlatformAiConfiguration::class)->snapshot();
        $validated['provider'] = $platform->provider;
        $validated['model'] = $platform->model;
        if (array_key_exists('max_tokens', $validated)) {
            $validated['max_tokens'] = $this->safety->capMaxTokens((int) $validated['max_tokens']);
        }
        if (array_key_exists('temperature', $validated)) {
            $validated['temperature'] = $this->safety->capTemperature((float) $validated['temperature']);
        }
        if (array_key_exists('ai_role', $validated)) {
            $validated['ai_role'] = ChatAiRole::normalize($validated['ai_role'] ?? ChatAiRole::GENERAL);
        }

        if (array_key_exists('assistant_name', $validated) || array_key_exists('name', $validated)) {
            $raw = array_key_exists('assistant_name', $validated)
                ? $validated['assistant_name']
                : ($validated['name'] ?? null);
            unset($validated['assistant_name']);
            $validated['name'] = $this->normalizeAssistantName($raw);
        }

        if (array_key_exists('agent_persona', $validated)) {
            $payload = is_array($validated['agent_persona']) ? $validated['agent_persona'] : [];
            if ($payload === []) {
                $validated['agent_persona'] = null;
            } else {
                try {
                    $validated['agent_persona'] = $this->personaValidator
                        ->validate($payload)
                        ->toArray();
                } catch (InboxAgentPersonaValidationException $exception) {
                    throw ValidationException::withMessages([
                        'agent_persona' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = (bool) $validated['is_active'];
        }

        $setting->fill($validated);
        $setting->save();

        return $setting->refresh();
    }

    private function normalizeAssistantName(mixed $raw): string
    {
        $value = is_string($raw) ? $raw : null;
        if (AssistantName::containsMarkup($value)) {
            throw ValidationException::withMessages([
                'assistant_name' => 'The assistant name cannot contain markup.',
            ]);
        }

        $clean = AssistantName::sanitize($value);
        if ($value !== null && trim($value) !== '' && $clean === null) {
            throw ValidationException::withMessages([
                'assistant_name' => 'The assistant name contains invalid characters.',
            ]);
        }

        return $clean ?? AssistantName::DEFAULT;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultAttributes(): array
    {
        $platform = app(PlatformAiConfiguration::class)->snapshot();

        return [
            'name' => AssistantName::DEFAULT,
            'ai_role' => ChatAiRole::GENERAL,
            'provider' => $platform->provider,
            'model' => $platform->model,
            'temperature' => config('ai.google_ai_studio.temperature', 0.3),
            'max_tokens' => config('ai.google_ai_studio.max_tokens', 1024),
            'is_active' => true,
            'rules' => [],
            'business_information' => [],
        ];
    }
}
