<?php

declare(strict_types=1);

namespace App\AI\Core;

use App\AI\Core\Prompt\PromptComposition;
use App\AI\Core\Prompt\PromptCompositionException;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

/**
 * Orders layer sections into one composition. It never adds, rewrites or decides
 * content: every section must already belong to the layer it is passed for, empty
 * sections are dropped, and order inside a layer is kept as given.
 */
final class PromptComposer
{
    /**
     * @param  list<PromptSection>  $constitution
     * @param  list<PromptSection>  $policy
     * @param  list<PromptSection>  $employee
     * @param  list<PromptSection>  $training
     * @param  list<PromptSection>  $context
     */
    public function compose(
        array $constitution,
        array $policy,
        array $employee,
        array $training,
        array $context,
        PromptSection $userMessage,
    ): PromptComposition {
        if ($userMessage->layer !== PromptLayer::UserMessage || $userMessage->isEmpty()) {
            throw PromptCompositionException::missingUserMessage();
        }

        $byLayer = [
            PromptLayer::Constitution->value => $constitution,
            PromptLayer::Policy->value => $policy,
            PromptLayer::Employee->value => $employee,
            PromptLayer::Training->value => $training,
            PromptLayer::Context->value => $context,
            PromptLayer::UserMessage->value => [$userMessage],
        ];

        $sections = [];
        foreach (PromptLayer::ordered() as $layer) {
            foreach ($byLayer[$layer->value] as $section) {
                $sections = $this->append($sections, $section, $layer);
            }
        }

        return new PromptComposition($sections);
    }

    /**
     * @param  list<PromptSection>  $sections
     * @return list<PromptSection>
     */
    private function append(array $sections, mixed $section, PromptLayer $layer): array
    {
        if (! $section instanceof PromptSection) {
            throw PromptCompositionException::invalidSection($layer);
        }
        if ($section->layer !== $layer) {
            throw PromptCompositionException::layerMismatch($section, $layer);
        }
        if (! $section->isEmpty()) {
            $sections[] = $section;
        }

        return $sections;
    }
}
