<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\Support\TypedList;
use InvalidArgumentException;

final readonly class LoadedConstitution
{
    /** @var list<PromptSection> */
    public array $sections;

    /**
     * @param  list<PromptSection>  $sections  Constitution-layer sections only, in load order.
     */
    public function __construct(
        public ?string $version = null,
        array $sections = [],
    ) {
        $this->sections = TypedList::of($sections, PromptSection::class, 'Constitution sections');
        foreach ($this->sections as $section) {
            if ($section->layer !== PromptLayer::Constitution) {
                throw new InvalidArgumentException('Constitution sections must belong to the constitution layer.');
            }
        }
    }
}
