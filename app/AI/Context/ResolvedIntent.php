<?php

declare(strict_types=1);

namespace App\AI\Context;

final readonly class ResolvedIntent
{
    /** @var list<string> */
    public array $rawIntents;

    /** @var list<string> */
    public array $searchTokens;

    /** @var list<string> */
    public array $secondaryIntents;

    /**
     * @param  list<string>  $rawIntents
     * @param  list<string>  $searchTokens
     * @param  list<string>  $secondaryIntents
     */
    public function __construct(
        public ?string $canonical = null,
        array $rawIntents = [],
        array $searchTokens = [],
        public ?string $dialectHint = null,
        public bool $usedConversationState = false,
        array $secondaryIntents = [],
    ) {
        $this->rawIntents = array_values(array_unique(array_filter($rawIntents, static fn (mixed $value): bool => is_string($value) && $value !== '')));
        $this->searchTokens = array_values(array_unique(array_filter($searchTokens, static fn (mixed $value): bool => is_string($value) && $value !== '')));
        $secondary = array_values(array_unique(array_filter($secondaryIntents, static fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== $canonical)));
        $this->secondaryIntents = $secondary;
    }

    /**
     * @return list<string>
     */
    public function allCanonicalIntents(): array
    {
        if ($this->canonical === null) {
            return $this->secondaryIntents;
        }

        return array_values(array_unique([$this->canonical, ...$this->secondaryIntents]));
    }

    /**
     * @return array<string, bool|string|list<string>|null>
     */
    public function toArray(): array
    {
        return [
            'canonical' => $this->canonical,
            'secondary_intents' => $this->secondaryIntents,
            'raw_intents' => $this->rawIntents,
            'search_tokens' => $this->searchTokens,
            'dialect_hint' => $this->dialectHint,
            'used_conversation_state' => $this->usedConversationState,
        ];
    }
}
