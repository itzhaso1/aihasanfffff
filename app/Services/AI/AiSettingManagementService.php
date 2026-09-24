<?php

namespace App\Services\AI;

use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidationException;
use App\Services\AI\Inbox\Persona\InboxAgentPersonaValidator;
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

        if (array_key_exists('provider', $validated)) {
            $validated['provider'] = $this->safety->resolveProvider((string) $validated['provider']);
        }
        if (array_key_exists('model', $validated)) {
            $provider = (string) ($validated['provider'] ?? $setting->provider ?? config('ai.default_provider', 'google_ai_studio'));
            $validated['model'] = $this->safety->resolveModel(
                $this->safety->resolveProvider($provider),
                (string) $validated['model'],
            );
        }
        if (array_key_exists('max_tokens', $validated)) {
            $validated['max_tokens'] = $this->safety->capMaxTokens((int) $validated['max_tokens']);
        }
        if (array_key_exists('temperature', $validated)) {
            $validated['temperature'] = $this->safety->capTemperature((float) $validated['temperature']);
        }
        if (array_key_exists('ai_role', $validated)) {
            $validated['ai_role'] = ChatAiRole::normalize($validated['ai_role'] ?? ChatAiRole::GENERAL);
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

    /**
     * @return array<string, mixed>
     */
    private function defaultAttributes(): array
    {
        return [
            'name' => 'AI Assistant',
            'ai_role' => ChatAiRole::GENERAL,
            'provider' => config('ai.default_provider', 'google_ai_studio'),
            'model' => config('ai.google_ai_studio.model', 'gemini-2.5-flash'),
            'temperature' => config('ai.google_ai_studio.temperature', 0.3),
            'max_tokens' => config('ai.google_ai_studio.max_tokens', 1024),
            'is_active' => true,
            'rules' => [],
            'business_information' => [],
        ];
    }
}
