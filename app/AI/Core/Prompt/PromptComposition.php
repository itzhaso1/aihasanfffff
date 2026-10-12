<?php

declare(strict_types=1);

namespace App\AI\Core\Prompt;

/**
 * Provider-neutral, ordered prompt. Model gateways render it into their own wire format;
 * nothing here knows about roles, parts or provider syntax.
 */
final readonly class PromptComposition
{
    /** @var list<PromptSection> */
    public array $sections;

    /**
     * @param  list<PromptSection>  $sections  Already in layer order.
     */
    public function __construct(array $sections)
    {
        $seen = [];
        $previousLayer = 0;
        foreach ($sections as $section) {
            if (! $section instanceof PromptSection) {
                throw PromptCompositionException::invalidSection(PromptLayer::Constitution);
            }
            if ($section->layer->value < $previousLayer) {
                throw PromptCompositionException::outOfOrder($section);
            }
            if (isset($seen[$section->key])) {
                throw PromptCompositionException::duplicateKey($section->key);
            }
            $seen[$section->key] = true;
            $previousLayer = $section->layer->value;
        }

        $this->sections = array_values($sections);
    }

    /**
     * @return list<PromptSection>
     */
    public function forLayer(PromptLayer $layer): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (PromptSection $section): bool => $section->layer === $layer,
        ));
    }

    public function userMessage(): ?PromptSection
    {
        return $this->forLayer(PromptLayer::UserMessage)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function sectionKeys(): array
    {
        return array_map(static fn (PromptSection $section): string => $section->key, $this->sections);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{layer: string, key: string, content: string, source: ?string, version: ?string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (PromptSection $section): array => $section->toArray(), $this->sections);
    }
}
