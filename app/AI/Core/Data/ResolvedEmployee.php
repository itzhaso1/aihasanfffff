<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\Support\TypedList;
use InvalidArgumentException;

/**
 * The employee a run acts as, identified by id only. Display data such as the name
 * lives inside the employee prompt sections, so the core cannot branch on it.
 */
final readonly class ResolvedEmployee
{
    /** @var list<string> */
    public array $skillKeys;

    /** @var list<PromptSection> */
    public array $sections;

    /**
     * @param  list<string>  $skillKeys
     * @param  list<PromptSection>  $sections  Employee-layer sections only.
     */
    public function __construct(
        public int $employeeId,
        public ?string $configVersion = null,
        array $skillKeys = [],
        array $sections = [],
    ) {
        if ($employeeId <= 0) {
            throw new InvalidArgumentException('Employee id must be positive.');
        }
        foreach ($skillKeys as $skillKey) {
            if (! is_string($skillKey) || preg_match('/^[a-z][a-z0-9_]*$/', $skillKey) !== 1) {
                throw new InvalidArgumentException('Skill keys must be snake_case identifiers.');
            }
        }
        $this->sections = TypedList::of($sections, PromptSection::class, 'Employee sections');
        foreach ($this->sections as $section) {
            if ($section->layer !== PromptLayer::Employee) {
                throw new InvalidArgumentException('Employee sections must belong to the employee layer.');
            }
        }
        $this->skillKeys = array_values(array_unique($skillKeys));
    }
}
