<?php

declare(strict_types=1);

namespace App\AI\Employees\Legacy;

use App\AI\Employees\Drafts\EmployeeDraft;
use App\AI\Employees\Drafts\EmployeeMemoryDraft;
use App\AI\Employees\Drafts\EmployeeModelDraft;
use App\AI\Employees\Drafts\EmployeePersonaDraft;
use App\AI\Employees\Drafts\EmployeeToolDraft;
use App\AI\Employees\EmployeeModelPolicy;
use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Employees\EmployeeResponseLength;
use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\Models\AiEmployee;
use App\Models\AiSetting;
use App\Services\AI\AssistantName;
use App\Support\Chat\ChatAiRole;

/**
 * The one mapping between legacy ai_settings and employees. The importer uses
 * {@see self::draftFromSetting()}; the old settings APIs will use {@see self::legacyView()}
 * during the Inbox migration. It never writes ai_settings.
 */
final class LegacyAiSettingsBridge
{
    private const DEFAULT_TRAINING_PROFILE = 'default';

    private const WARMTH_TO_TONE = ['low' => 'neutral', 'medium' => 'friendly', 'high' => 'warm'];

    private const MAX_INSTRUCTIONS = 4000;

    public function __construct(private readonly EmployeeModelPolicy $models) {}

    /**
     * @param  list<string>  $toolNames  Tools the imported employee may use (the current production tool set).
     */
    public function draftFromSetting(AiSetting $setting, array $toolNames): EmployeeDraft
    {
        $persona = is_array($setting->agent_persona) ? $setting->agent_persona : [];
        $rules = is_array($setting->rules) ? $setting->rules : [];
        $business = is_array($setting->business_information) ? $setting->business_information : [];
        $role = ChatAiRole::normalize((string) ($setting->ai_role ?? ChatAiRole::GENERAL));

        return new EmployeeDraft(
            name: $this->name($setting, $persona),
            role: $role,
            jobTitle: $this->text(data_get($persona, 'identity.role'), 128),
            instructions: $this->instructions($setting, $persona),
            trainingProfile: self::DEFAULT_TRAINING_PROFILE,
            experienceNotes: $this->text($business['experience'] ?? null, 1000),
            status: $setting->is_active === false ? EmployeeStatus::Inactive : EmployeeStatus::Active,
            persona: $this->persona($setting, $persona),
            skills: LegacyRoleSkillDefaults::for($role),
            tools: array_map(static fn (string $name): EmployeeToolDraft => new EmployeeToolDraft($name), array_values(array_unique($toolNames))),
            memory: EmployeeMemoryDraft::allEnabled(! array_key_exists('memory_enabled', $rules) || (bool) $rules['memory_enabled']),
            model: $this->model($setting),
            legacyAiSettingId: (int) $setting->id,
        );
    }

    /**
     * Employee values in the legacy settings shape, for the old APIs during migration.
     *
     * @return array<string, mixed>
     */
    public function legacyView(AiEmployee $employee): array
    {
        $persona = $employee->persona;
        $model = $employee->modelSettings;

        return [
            'name' => $employee->name,
            'assistant_name' => $employee->name,
            'ai_role' => $employee->role,
            'instructions' => $employee->instructions,
            'is_active' => $employee->isActive(),
            'provider' => $model?->provider,
            'model' => $model?->model,
            'temperature' => $model !== null ? (float) $model->temperature : null,
            'max_tokens' => $model?->effectiveMaxOutputTokens(),
            'reply_style' => $model?->response_length->value,
            'agent_persona' => [
                'identity' => ['name' => $employee->name, 'role' => $employee->job_title],
                'tone' => [
                    'language' => $persona?->language,
                    'dialect' => $persona?->dialect,
                    'formality' => $persona?->formality,
                    'emoji_usage' => $persona?->emoji_policy,
                ],
                'traits' => $persona?->traits ?? [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function name(AiSetting $setting, array $persona): string
    {
        $personaName = AssistantName::sanitize((string) data_get($persona, 'identity.name', ''));

        return AssistantName::configured($setting)
            ?? ($personaName !== null && ! AssistantName::isGeneric($personaName) ? $personaName : null)
            ?? (string) __('ai_employees.default_name');
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function instructions(AiSetting $setting, array $persona): ?string
    {
        $parts = [];
        $instructions = trim((string) ($setting->instructions ?? ''));
        if ($instructions !== '') {
            $parts[] = $instructions;
        }
        foreach ((array) ($persona['behavioral_rules'] ?? []) as $rule) {
            if (is_string($rule) && trim($rule) !== '') {
                $parts[] = trim($rule);
            }
        }

        return $parts === [] ? null : mb_substr(implode("\n", $parts), 0, self::MAX_INSTRUCTIONS);
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function persona(AiSetting $setting, array $persona): EmployeePersonaDraft
    {
        $tone = is_array($persona['tone'] ?? null) ? $persona['tone'] : [];
        $defaults = new EmployeePersonaDraft;

        return new EmployeePersonaDraft(
            language: $this->option($tone['language'] ?? null, EmployeePersonaOptions::languages(), $defaults->language),
            dialect: $this->option($tone['dialect'] ?? null, EmployeePersonaOptions::dialects(), null),
            formality: $this->option($tone['formality'] ?? null, EmployeePersonaOptions::FORMALITY, $defaults->formality),
            tone: self::WARMTH_TO_TONE[$tone['warmth'] ?? ''] ?? $defaults->tone,
            emojiPolicy: $this->option($tone['emoji_usage'] ?? null, EmployeePersonaOptions::EMOJI_POLICIES, $defaults->emojiPolicy),
            traits: array_values(array_filter(
                (array) ($persona['traits'] ?? []),
                static fn (mixed $trait): bool => is_string($trait) && in_array($trait, EmployeePersonaOptions::TRAITS, true),
            )),
            styleNotes: $this->text($setting->tone, 500),
        );
    }

    private function model(AiSetting $setting): EmployeeModelDraft
    {
        $maxTokens = $setting->max_tokens !== null ? (int) $setting->max_tokens : null;
        $length = EmployeeResponseLength::tryFrom((string) $setting->reply_style)
            ?? ($maxTokens !== null ? EmployeeResponseLength::fromTokenBudget($maxTokens) : EmployeeResponseLength::Short);
        $override = $maxTokens !== null && $maxTokens !== $length->defaultMaxOutputTokens()
            ? max(64, min(1024, $maxTokens))
            : null;

        return $this->models->fromLegacy(
            $setting->provider,
            $setting->model,
            $setting->temperature !== null ? (float) $setting->temperature : null,
            $length,
            $override,
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function option(mixed $value, array $allowed, ?string $fallback): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
