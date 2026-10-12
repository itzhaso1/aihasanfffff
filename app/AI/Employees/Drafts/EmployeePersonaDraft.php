<?php

declare(strict_types=1);

namespace App\AI\Employees\Drafts;

use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Employees\InvalidEmployeeData;

final readonly class EmployeePersonaDraft
{
    private const MAX_STYLE_NOTES = 500;

    /** @var list<string> */
    public array $traits;

    /**
     * @param  list<string>  $traits
     */
    public function __construct(
        public string $language = 'ar',
        public ?string $dialect = null,
        public string $formality = 'casual_professional',
        public string $tone = 'friendly',
        public string $emojiPolicy = 'minimal',
        public string $greetingPolicy = 'introduce_first_contact',
        array $traits = [],
        public ?string $styleNotes = null,
    ) {
        self::oneOf('persona.language', $language, EmployeePersonaOptions::languages());
        if ($dialect !== null) {
            self::oneOf('persona.dialect', $dialect, EmployeePersonaOptions::dialects());
        }
        self::oneOf('persona.formality', $formality, EmployeePersonaOptions::FORMALITY);
        self::oneOf('persona.tone', $tone, EmployeePersonaOptions::TONES);
        self::oneOf('persona.emoji_policy', $emojiPolicy, EmployeePersonaOptions::EMOJI_POLICIES);
        self::oneOf('persona.greeting_policy', $greetingPolicy, EmployeePersonaOptions::GREETING_POLICIES);
        if (count($traits) > EmployeePersonaOptions::MAX_TRAITS) {
            throw InvalidEmployeeData::field('persona.traits', 'Too many traits.');
        }
        foreach ($traits as $trait) {
            self::oneOf('persona.traits', $trait, EmployeePersonaOptions::TRAITS);
        }
        if ($styleNotes !== null && mb_strlen($styleNotes) > self::MAX_STYLE_NOTES) {
            throw InvalidEmployeeData::field('persona.style_notes', 'Style notes are too long.');
        }
        $this->traits = array_values(array_unique($traits));
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(string $field, mixed $value, array $allowed): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw InvalidEmployeeData::field($field, 'Unsupported value.');
        }
    }
}
