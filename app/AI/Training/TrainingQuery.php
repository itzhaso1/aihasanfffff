<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * What a run needs training for. Built from employee and context data by a
 * {@see TrainingQueryFactory}; the library never reads employees or conversations.
 */
final readonly class TrainingQuery
{
    private const SELECTOR_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** @var list<string> */
    public array $skills;

    /** @var list<string> */
    public array $intents;

    /** @var list<string> */
    public array $roles;

    /** @var list<string> */
    public array $domains;

    /**
     * @param  list<string>  $skills
     * @param  list<string>  $intents
     * @param  list<string>  $roles
     * @param  list<string>  $domains
     */
    public function __construct(
        public ?string $profile = null,
        array $skills = [],
        array $intents = [],
        array $roles = [],
        public ?string $dialect = null,
        public ?string $language = null,
        array $domains = [],
        public int $budgetChars = TrainingLimits::DEFAULT_CONTEXT_BUDGET_CHARS,
    ) {
        foreach ([$profile, $dialect, $language] as $single) {
            if ($single !== null) {
                self::assertSelector($single);
            }
        }
        $this->skills = self::selectors($skills);
        $this->intents = self::selectors($intents);
        $this->roles = self::selectors($roles);
        $this->domains = self::selectors($domains);

        if ($budgetChars < TrainingLimits::MIN_CONTEXT_BUDGET_CHARS || $budgetChars > TrainingLimits::MAX_CONTEXT_BUDGET_CHARS) {
            throw TrainingException::invalidQuery('budget is outside the allowed range');
        }
    }

    /**
     * @return list<string>
     */
    public function values(TrainingDimension $dimension): array
    {
        return match ($dimension) {
            TrainingDimension::Skills => $this->skills,
            TrainingDimension::Intents => $this->intents,
            TrainingDimension::Roles => $this->roles,
            TrainingDimension::Domains => $this->domains,
            TrainingDimension::Dialects => $this->dialect !== null ? [$this->dialect] : [],
            TrainingDimension::Languages => $this->language !== null ? [$this->language] : [],
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function selectors(array $values): array
    {
        foreach ($values as $value) {
            self::assertSelector($value);
        }

        return array_values(array_unique($values));
    }

    private static function assertSelector(mixed $value): void
    {
        if (! is_string($value) || preg_match(self::SELECTOR_PATTERN, $value) !== 1) {
            throw TrainingException::invalidQuery('selectors must be snake_case identifiers');
        }
    }
}
