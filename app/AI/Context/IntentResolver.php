<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\Support\Chat\GulfArabicInterpreter;

final class IntentResolver
{
    /** @var array<string, string> */
    private const DIRECT_MAP = [
        'price' => 'price_question',
        'availability' => 'availability_question',
    ];

    /** @var list<string> */
    private const CONTEXTUAL_MESSAGES = [
        'هذا', 'هذي', 'هذه', 'ذا', 'الثاني', 'الثالث', 'الاول', 'الأول', 'طيب', 'تمام', 'اوكي',
    ];

    public function __construct(private readonly GulfArabicInterpreter $interpreter) {}

    public function resolve(
        string $message,
        ConversationState $state,
        bool $hasProductContext = false,
    ): ResolvedIntent {
        $reading = $this->interpreter->interpret($message);
        $rawIntents = $reading['intents'];
        $canonical = $this->directIntent($rawIntents);
        $usedState = false;

        if ($canonical === null && in_array('request', $rawIntents, true) && $hasProductContext) {
            $canonical = 'purchase_intent';
        }

        if ($canonical === null && $this->isContextualFollowUp($reading['normalized'], $rawIntents, $reading['search_tokens']) && $state->lastIntent !== null) {
            $canonical = $state->lastIntent;
            $usedState = true;
        }

        return new ResolvedIntent(
            canonical: $canonical,
            rawIntents: $rawIntents,
            searchTokens: $reading['search_tokens'],
            dialectHint: $reading['dialect_hint'],
            usedConversationState: $usedState,
        );
    }

    /**
     * @param  list<string>  $rawIntents
     */
    private function directIntent(array $rawIntents): ?string
    {
        foreach ($rawIntents as $raw) {
            if (isset(self::DIRECT_MAP[$raw])) {
                return self::DIRECT_MAP[$raw];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $rawIntents
     * @param  list<string>  $searchTokens
     */
    private function isContextualFollowUp(string $normalized, array $rawIntents, array $searchTokens): bool
    {
        if ($searchTokens !== []) {
            return false;
        }

        if (in_array('followup', $rawIntents, true)) {
            return true;
        }

        return in_array(trim($normalized), self::CONTEXTUAL_MESSAGES, true);
    }
}
