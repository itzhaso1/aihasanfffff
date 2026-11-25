<?php

namespace App\Support\Chat;

/**
 * Reads Gulf conversational text for search and intent.
 * The original message is never rewritten.
 */
final class GulfArabicInterpreter
{
    public function __construct(
        private readonly ArabicSearchNormalizer $normalizer,
        private readonly GulfArabicLexicon $lexicon,
    ) {}

    /**
     * @return array{original:string,normalized:string,intents:list<string>,dialect_hint:?string,search_tokens:list<string>}
     */
    public function interpret(string $original): array
    {
        $normalized = $this->normalizer->normalize($original);
        $intentPositions = [];
        $searchText = $normalized;

        $phrases = [];
        foreach ($this->lexicon->groups() as $group) {
            foreach ($group['forms'] as $form) {
                $folded = $this->normalizer->normalize($form);
                if ($folded === '' || ! str_contains($folded, ' ')) {
                    continue;
                }
                $phrases[] = [$folded, $group['id']];
            }
        }
        usort($phrases, fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        foreach ($phrases as [$phrase, $intent]) {
            $pos = mb_strpos($searchText, $phrase);
            if ($pos !== false) {
                $origPos = mb_strpos($normalized, $phrase);
                $intentPositions[$intent] = min($intentPositions[$intent] ?? PHP_INT_MAX, $origPos !== false ? $origPos : $pos);
                $searchText = str_replace($phrase, ' ', $searchText);
            }
        }

        $wordIntents = [];
        foreach ($this->lexicon->groups() as $group) {
            foreach ($group['forms'] as $form) {
                $folded = $this->normalizer->normalize($form);
                if ($folded === '' || str_contains($folded, ' ')) {
                    continue;
                }
                $wordIntents[$folded] = $group['id'];
            }
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $searchText) ?: [];
        $tokens = [];
        $cursor = 0;
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            $origPos = mb_strpos($normalized, $token, $cursor);
            if ($origPos !== false) {
                $cursor = $origPos + mb_strlen($token);
            }
            if (isset($wordIntents[$token])) {
                $intentId = $wordIntents[$token];
                $intentPositions[$intentId] = min($intentPositions[$intentId] ?? PHP_INT_MAX, $origPos !== false ? $origPos : $cursor);

                continue;
            }
            $tokens[] = $token;
        }

        asort($intentPositions, SORT_NUMERIC);
        $intents = array_keys($intentPositions);

        return [
            'original' => $original,
            'normalized' => $normalized,
            'intents' => array_values(array_unique($intents)),
            'dialect_hint' => $this->dialectHint($original, $normalized),
            'search_tokens' => array_values(array_unique($tokens)),
        ];
    }

    private function dialectHint(string $original, string $normalized): ?string
    {
        $seen = [];
        foreach ($this->lexicon->dialectMarkers() as $marker => $dialect) {
            $folded = $this->normalizer->normalize($marker);
            if (($folded !== '' && str_contains($normalized, $folded)) || str_contains($original, $marker)) {
                $seen[$dialect] = true;
            }
        }

        if (count($seen) !== 1) {
            return null;
        }

        return array_key_first($seen);
    }
}
