<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiDirective;
use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ContextProvider;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\AiReference;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

final class FakeContextProvider implements ContextProvider
{
    public AiDirective $directive = AiDirective::Respond;

    /** @var list<PromptSection> */
    public array $sections;

    public function __construct()
    {
        $this->sections = [new PromptSection(PromptLayer::Context, 'context.history', 'CONTEXT-LAYER')];
    }

    public function build(AiRequest $request, ResolvedEmployee $employee): ContextPayload
    {
        return new ContextPayload(
            sections: $this->sections,
            intent: 'price_question',
            references: [new AiReference('catalog_item', '42')],
            facts: [new FactRecord('catalog', 'item:42.price', 299)],
            directive: $this->directive,
        );
    }
}
