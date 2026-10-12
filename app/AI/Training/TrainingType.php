<?php

declare(strict_types=1);

namespace App\AI\Training;

enum TrainingType: string
{
    case Rule = 'rule';
    case Example = 'example';
    case Scenario = 'scenario';
    case Playbook = 'playbook';
    case Knowledge = 'knowledge';
    case Profile = 'profile';

    public static function forFolder(string $folder): ?self
    {
        return match ($folder) {
            'rules' => self::Rule,
            'examples' => self::Example,
            'scenarios' => self::Scenario,
            'playbooks' => self::Playbook,
            'knowledge' => self::Knowledge,
            'profiles' => self::Profile,
            default => null,
        };
    }

    public function folder(): string
    {
        return match ($this) {
            self::Rule => 'rules',
            self::Example => 'examples',
            self::Scenario => 'scenarios',
            self::Playbook => 'playbooks',
            self::Knowledge => 'knowledge',
            self::Profile => 'profiles',
        };
    }

    /** Examples may be grouped one folder deep; other types live directly in their folder. */
    public function allowsSubfolders(): bool
    {
        return $this === self::Example;
    }

    /** Situational material is only selected when the conversation intent matches it. */
    public function isSituational(): bool
    {
        return $this === self::Example || $this === self::Scenario;
    }

    /** Profiles select other documents; they are never sent as prompt content. */
    public function isPromptContent(): bool
    {
        return $this !== self::Profile;
    }

    /**
     * Headings a document of this type must contain, in any order.
     *
     * @return list<string>
     */
    public function requiredHeadings(): array
    {
        return match ($this) {
            self::Example => ['Customer', 'Expected behavior', 'Good response', 'Bad response', 'Why'],
            self::Scenario => ['Context', 'Customer intent', 'Expected behavior', 'Allowed actions', 'Forbidden behavior', 'Good example', 'Bad example'],
            default => [],
        };
    }
}
