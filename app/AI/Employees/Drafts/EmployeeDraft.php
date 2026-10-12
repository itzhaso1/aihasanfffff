<?php

declare(strict_types=1);

namespace App\AI\Employees\Drafts;

use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\InvalidEmployeeData;
use App\AI\Skills\SkillRegistry;
use App\AI\Skills\SkillRegistryException;
use App\Services\AI\AssistantName;
use App\Support\Chat\ChatAiRole;

/**
 * Complete, validated configuration of one employee before it is persisted.
 * Presets, API payloads and the legacy importer all produce this one shape.
 */
final readonly class EmployeeDraft
{
    private const MAX_INSTRUCTIONS = 4000;

    private const MAX_EXPERIENCE = 1000;

    private const MAX_JOB_TITLE = 128;

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const GENDERS = ['male', 'female'];

    public string $name;

    /** @var array<string, array<string, mixed>> Skill key => settings. */
    public array $skills;

    /** @var list<EmployeeToolDraft> */
    public array $tools;

    /**
     * @param  array<string, array<string, mixed>>  $skills
     * @param  list<EmployeeToolDraft>  $tools
     */
    public function __construct(
        string $name,
        public string $role,
        public ?string $jobTitle = null,
        public ?string $avatarKey = null,
        public ?string $gender = null,
        public ?string $instructions = null,
        public ?string $trainingProfile = null,
        public ?string $experienceNotes = null,
        public EmployeeStatus $status = EmployeeStatus::Active,
        public EmployeePersonaDraft $persona = new EmployeePersonaDraft,
        array $skills = [],
        array $tools = [],
        public EmployeeMemoryDraft $memory = new EmployeeMemoryDraft,
        public ?EmployeeModelDraft $model = null,
        public ?string $presetKey = null,
        public ?int $presetVersion = null,
        public ?int $legacyAiSettingId = null,
    ) {
        if (AssistantName::containsMarkup($name) || ($clean = AssistantName::sanitize($name)) === null) {
            throw InvalidEmployeeData::field('name', 'The employee name is empty or contains invalid characters.');
        }
        $this->name = $clean;

        if (! in_array($role, ChatAiRole::all(), true)) {
            throw InvalidEmployeeData::field('role', 'Unsupported role.');
        }
        self::maxLength('job_title', $jobTitle, self::MAX_JOB_TITLE);
        self::maxLength('instructions', $instructions, self::MAX_INSTRUCTIONS);
        self::maxLength('experience_notes', $experienceNotes, self::MAX_EXPERIENCE);
        self::key('avatar_key', $avatarKey);
        self::key('training_profile', $trainingProfile);
        self::key('preset_key', $presetKey);
        if ($gender !== null && ! in_array($gender, self::GENDERS, true)) {
            throw InvalidEmployeeData::field('gender', 'Unsupported gender.');
        }

        foreach ($skills as $key => $settings) {
            if (! is_string($key) || ! is_array($settings)) {
                throw InvalidEmployeeData::field('skills', 'Each skill needs a valid key and settings object.');
            }
        }
        try {
            SkillRegistry::validateKeys(array_keys($skills));
        } catch (SkillRegistryException $exception) {
            throw InvalidEmployeeData::field('skills', $exception->getMessage());
        }
        ksort($skills, SORT_STRING);
        $this->skills = $skills;

        $names = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof EmployeeToolDraft || isset($names[$tool->name])) {
                throw InvalidEmployeeData::field('tools', 'Tools must be unique tool permissions.');
            }
            $names[$tool->name] = true;
        }
        usort($tools, static fn (EmployeeToolDraft $a, EmployeeToolDraft $b): int => strcmp($a->name, $b->name));
        $this->tools = $tools;
    }

    /**
     * @return list<string>
     */
    public function skillKeys(): array
    {
        return array_keys($this->skills);
    }

    private static function maxLength(string $field, ?string $value, int $max): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            throw InvalidEmployeeData::field($field, 'Value is too long.');
        }
    }

    private static function key(string $field, ?string $value): void
    {
        if ($value !== null && preg_match(self::KEY_PATTERN, $value) !== 1) {
            throw InvalidEmployeeData::field($field, 'Value must be a snake_case identifier.');
        }
    }
}
