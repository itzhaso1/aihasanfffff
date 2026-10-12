<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Core\Decision\TrainingVersion;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\Support\TypedList;

final readonly class TrainingSelection
{
    /** @var list<PromptSection> */
    public array $sections;

    /** @var list<TrainingVersion> */
    public array $versions;

    /**
     * @param  list<PromptSection>  $sections
     * @param  list<TrainingVersion>  $versions
     */
    public function __construct(array $sections = [], array $versions = [])
    {
        $this->sections = TypedList::of($sections, PromptSection::class, 'Training sections');
        $this->versions = TypedList::of($versions, TrainingVersion::class, 'Training versions');
    }
}
