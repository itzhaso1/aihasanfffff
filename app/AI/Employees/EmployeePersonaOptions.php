<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Skills\SkillRegistry;
use App\AI\Training\TrainingDimension;
use App\AI\Training\TrainingVocabulary;

/**
 * Allowed employee communication values. Language and dialect come from the training
 * vocabulary, and employee skills come from the canonical skill registry.
 */
final class EmployeePersonaOptions
{
    public const AUTO = 'auto';

    /** @var list<string> */
    public const FORMALITY = ['casual', 'casual_professional', 'professional', 'formal'];

    /** @var list<string> */
    public const TONES = ['warm', 'friendly', 'neutral', 'calm', 'energetic'];

    /** @var list<string> */
    public const EMOJI_POLICIES = ['none', 'minimal', 'moderate'];

    /** @var list<string> */
    public const GREETING_POLICIES = ['introduce_first_contact', 'greet_only', 'none'];

    /** @var list<string> */
    public const TRAITS = ['friendly', 'professional', 'confident', 'empathetic', 'energetic', 'calm', 'concise', 'helpful'];

    public const MAX_TRAITS = 8;

    /**
     * @return list<string>
     */
    public static function languages(): array
    {
        return [self::AUTO, ...TrainingVocabulary::values(TrainingDimension::Languages)];
    }

    /**
     * @return list<string>
     */
    public static function dialects(): array
    {
        return [self::AUTO, ...TrainingVocabulary::values(TrainingDimension::Dialects)];
    }

    /**
     * @return list<string>
     */
    public static function skills(): array
    {
        return SkillRegistry::enabledKeys();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function toArray(): array
    {
        return [
            'languages' => self::languages(),
            'dialects' => self::dialects(),
            'formality' => self::FORMALITY,
            'tones' => self::TONES,
            'emoji_policies' => self::EMOJI_POLICIES,
            'greeting_policies' => self::GREETING_POLICIES,
            'traits' => self::TRAITS,
            'response_lengths' => array_map(static fn (EmployeeResponseLength $length): string => $length->value, EmployeeResponseLength::cases()),
        ];
    }
}
