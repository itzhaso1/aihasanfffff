<?php

declare(strict_types=1);

namespace App\AI\Core\Data;

use App\AI\Context\AiContext;
use App\AI\Core\AiDirective;
use App\AI\Core\Decision\AiReference;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\Support\TypedList;

final readonly class ContextPayload
{
    /** @var list<PromptSection> */
    public array $sections;

    /** @var list<AiReference> */
    public array $references;

    /** @var list<FactRecord> */
    public array $facts;

    /**
     * @param  list<PromptSection>  $sections
     * @param  list<AiReference>  $references
     * @param  list<FactRecord>  $facts  Business truth loaded from trusted services.
     */
    public function __construct(
        array $sections = [],
        public ?string $intent = null,
        array $references = [],
        array $facts = [],
        public AiDirective $directive = AiDirective::Respond,
        public ?AiContext $structuredContext = null,
    ) {
        $this->sections = TypedList::of($sections, PromptSection::class, 'Context sections');
        $this->references = TypedList::of($references, AiReference::class, 'Context references');
        $this->facts = TypedList::of($facts, FactRecord::class, 'Context facts');
    }
}
