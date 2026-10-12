<?php

declare(strict_types=1);

namespace App\AI\Employees\Drafts;

use App\AI\Employees\EmployeeModelPolicy;
use App\AI\Employees\EmployeeResponseLength;
use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\InvalidEmployeeData;
use App\AI\Employees\Models\AiEmployee;

/**
 * Converts between the nested array shape (API payloads and preset JSON), drafts and
 * stored employees. Keys present in the array override the base draft; nested
 * persona, memory and model keys merge; skills and tools replace when present.
 */
final class EmployeeDraftMapper
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function fromArray(array $data, ?EmployeeDraft $base = null): EmployeeDraft
    {
        $identity = fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) ? $data[$key] : $fallback;

        return new EmployeeDraft(
            name: (string) $identity('name', $base?->name ?? ''),
            role: (string) $identity('role', $base?->role ?? ''),
            jobTitle: $this->nullableString($identity('job_title', $base?->jobTitle)),
            avatarKey: $this->nullableString($identity('avatar_key', $base?->avatarKey)),
            gender: $this->nullableString($identity('gender', $base?->gender)),
            instructions: $this->nullableString($identity('instructions', $base?->instructions)),
            trainingProfile: $this->nullableString($identity('training_profile', $base?->trainingProfile)),
            experienceNotes: $this->nullableString($identity('experience_notes', $base?->experienceNotes)),
            status: $base?->status ?? EmployeeStatus::Active,
            persona: $this->persona($this->section($data, 'persona'), $base?->persona),
            skills: array_key_exists('skills', $data) ? $this->skills($data['skills']) : ($base?->skills ?? []),
            tools: array_key_exists('tools', $data) ? $this->tools($data['tools']) : ($base?->tools ?? []),
            memory: $this->memory($this->section($data, 'memory'), $base?->memory),
            model: $this->model($this->section($data, 'model'), $base?->model),
            presetKey: $base?->presetKey,
            presetVersion: $base?->presetVersion,
            legacyAiSettingId: $base?->legacyAiSettingId,
        );
    }

    public function fromEmployee(AiEmployee $employee): EmployeeDraft
    {
        $persona = $employee->persona;
        $model = $employee->modelSettings;
        $memory = $employee->memorySettings;

        return new EmployeeDraft(
            name: $employee->name,
            role: (string) $employee->role,
            jobTitle: $employee->job_title,
            avatarKey: $employee->avatar_key,
            gender: $employee->gender,
            instructions: $employee->instructions,
            trainingProfile: $employee->training_profile,
            experienceNotes: $employee->experience_notes,
            status: $employee->status,
            persona: $persona === null ? new EmployeePersonaDraft : new EmployeePersonaDraft(
                language: (string) $persona->language,
                dialect: $persona->dialect,
                formality: (string) $persona->formality,
                tone: (string) $persona->tone,
                emojiPolicy: (string) $persona->emoji_policy,
                greetingPolicy: (string) $persona->greeting_policy,
                traits: $persona->traits ?? [],
                styleNotes: $persona->style_notes,
            ),
            skills: $employee->skills->mapWithKeys(fn ($skill): array => [$skill->skill_key => $skill->settings ?? []])->all(),
            tools: $employee->toolPermissions->map(fn ($tool): EmployeeToolDraft => new EmployeeToolDraft(
                $tool->tool_name,
                (bool) $tool->allowed,
                $tool->max_calls_per_run,
            ))->values()->all(),
            memory: $memory === null ? new EmployeeMemoryDraft : new EmployeeMemoryDraft(
                (bool) $memory->conversation_memory_enabled,
                (bool) $memory->customer_memory_enabled,
                (bool) $memory->commerce_context_enabled,
                (bool) $memory->relevant_history_enabled,
            ),
            model: $model === null ? null : new EmployeeModelDraft(
                provider: (string) $model->provider,
                model: (string) $model->model,
                temperature: (float) $model->temperature,
                responseLength: $model->response_length,
                maxOutputTokens: $model->max_output_tokens,
                thinkingBudget: $model->thinking_budget,
            ),
            presetKey: $employee->preset_key,
            presetVersion: $employee->preset_version,
            legacyAiSettingId: $employee->legacy_ai_setting_id,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return ?array<string, mixed>
     */
    private function section(array $data, string $key): ?array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! is_array($data[$key])) {
            throw InvalidEmployeeData::field($key, 'Must be an object.');
        }

        return $data[$key];
    }

    /**
     * @param  ?array<string, mixed>  $data
     */
    private function persona(?array $data, ?EmployeePersonaDraft $base): EmployeePersonaDraft
    {
        $base ??= new EmployeePersonaDraft;
        if ($data === null) {
            return $base;
        }
        $value = fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) ? $data[$key] : $fallback;

        return new EmployeePersonaDraft(
            language: (string) $value('language', $base->language),
            dialect: $this->nullableString($value('dialect', $base->dialect)),
            formality: (string) $value('formality', $base->formality),
            tone: (string) $value('tone', $base->tone),
            emojiPolicy: (string) $value('emoji_policy', $base->emojiPolicy),
            greetingPolicy: (string) $value('greeting_policy', $base->greetingPolicy),
            traits: $this->stringList($value('traits', $base->traits), 'persona.traits'),
            styleNotes: $this->nullableString($value('style_notes', $base->styleNotes)),
        );
    }

    /**
     * @param  ?array<string, mixed>  $data
     */
    private function memory(?array $data, ?EmployeeMemoryDraft $base): EmployeeMemoryDraft
    {
        $base ??= new EmployeeMemoryDraft;
        if ($data === null) {
            return $base;
        }
        $flag = fn (string $key, bool $fallback): bool => array_key_exists($key, $data) ? (bool) $data[$key] : $fallback;

        return new EmployeeMemoryDraft(
            $flag('conversation_memory_enabled', $base->conversationMemoryEnabled),
            $flag('customer_memory_enabled', $base->customerMemoryEnabled),
            $flag('commerce_context_enabled', $base->commerceContextEnabled),
            $flag('relevant_history_enabled', $base->relevantHistoryEnabled),
        );
    }

    /**
     * @param  ?array<string, mixed>  $data
     */
    private function model(?array $data, ?EmployeeModelDraft $base): ?EmployeeModelDraft
    {
        if ($data === null) {
            return $base;
        }
        $value = fn (string $key, mixed $fallback): mixed => array_key_exists($key, $data) ? $data[$key] : $fallback;
        $length = $value('response_length', $base?->responseLength->value ?? EmployeeResponseLength::Short->value);
        $responseLength = is_string($length) ? EmployeeResponseLength::tryFrom($length) : null;
        if ($responseLength === null) {
            throw InvalidEmployeeData::field('model.response_length', 'Unsupported response length.');
        }

        $defaults = app(EmployeeModelPolicy::class)->defaults();

        return new EmployeeModelDraft(
            provider: $defaults->provider,
            model: $defaults->model,
            temperature: (float) $value('temperature', $base?->temperature ?? 0.4),
            responseLength: $responseLength,
            maxOutputTokens: $this->nullableInt($value('max_output_tokens', $base?->maxOutputTokens)),
            thinkingBudget: $this->nullableInt($value('thinking_budget', $base?->thinkingBudget)),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function skills(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw InvalidEmployeeData::field('skills', 'Skills must be a list.');
        }
        $skills = [];
        foreach ($raw as $item) {
            [$key, $settings] = is_string($item) ? [$item, []] : [$item['key'] ?? null, $item['settings'] ?? []];
            if (! is_string($key) || ! is_array($settings) || isset($skills[$key])) {
                throw InvalidEmployeeData::field('skills', 'Each skill needs a unique key and settings object.');
            }
            $skills[$key] = $settings;
        }

        return $skills;
    }

    /**
     * @return list<EmployeeToolDraft>
     */
    private function tools(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw InvalidEmployeeData::field('tools', 'Tools must be a list.');
        }

        return array_map(function (mixed $item): EmployeeToolDraft {
            if (is_string($item)) {
                return new EmployeeToolDraft($item);
            }
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                throw InvalidEmployeeData::field('tools', 'Each tool needs a name.');
            }

            return new EmployeeToolDraft($item['name'], (bool) ($item['allowed'] ?? true), $this->nullableInt($item['max_calls_per_run'] ?? null));
        }, $raw);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw, string $field): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw InvalidEmployeeData::field($field, 'Must be a list.');
        }
        foreach ($raw as $item) {
            if (! is_string($item)) {
                throw InvalidEmployeeData::field($field, 'Must contain text values.');
            }
        }

        return $raw;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw InvalidEmployeeData::field('model', 'Expected a number.');
        }

        return (int) $value;
    }
}
