<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Employees\Drafts\EmployeeDraft;
use App\AI\Employees\Drafts\EmployeeDraftMapper;
use App\AI\Employees\Presets\PresetRepository;

/**
 * Builds employee drafts from presets or from API payloads. A draft built from a preset
 * is a copy: later preset changes never reach employees created from it.
 */
final class EmployeeFactory
{
    public function __construct(
        private readonly PresetRepository $presets,
        private readonly EmployeeDraftMapper $mapper,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  Employee fields to apply on top of the preset (such as the name).
     */
    public function fromPreset(string $presetKey, array $overrides = []): EmployeeDraft
    {
        $preset = $this->presets->get($presetKey);
        $draft = $this->mapper->fromArray([...$preset->employee, ...$overrides]);

        return new EmployeeDraft(
            name: $draft->name,
            role: $draft->role,
            jobTitle: $draft->jobTitle,
            avatarKey: $draft->avatarKey ?? $preset->avatarKey,
            gender: $draft->gender,
            instructions: $draft->instructions,
            trainingProfile: $draft->trainingProfile,
            experienceNotes: $draft->experienceNotes,
            status: $draft->status,
            persona: $draft->persona,
            skills: $draft->skills,
            tools: $draft->tools,
            memory: $draft->memory,
            model: $draft->model,
            presetKey: $preset->key,
            presetVersion: $preset->version,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function fromArray(array $data): EmployeeDraft
    {
        return $this->mapper->fromArray($data);
    }
}
