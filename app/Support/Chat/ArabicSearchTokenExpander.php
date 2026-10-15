<?php

namespace App\Support\Chat;

/**
 * Bounded Arabic singular/plural variants for catalog search.
 * At most a few extra tokens per input so SQL stays small.
 */
final class ArabicSearchTokenExpander
{
    public const MAX_VARIANTS_PER_TOKEN = 4;

    public const MAX_TOKENS = 12;

    public function __construct(private readonly ArabicSearchNormalizer $normalizer) {}

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public function expandAll(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            foreach ($this->expand($token) as $variant) {
                $out[] = $variant;
                if (count($out) >= self::MAX_TOKENS) {
                    return array_values(array_unique($out));
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function expand(string $token): array
    {
        $raw = trim($token);
        if ($raw === '') {
            return [];
        }

        $folded = $this->normalizer->normalize($raw);
        $variants = array_values(array_unique(array_filter([$raw, $folded])));

        $stem = $folded;
        if (preg_match('/^[بوفكل]/u', $stem) === 1 && mb_strlen($stem) >= 5) {
            $stem = mb_substr($stem, 1);
            $variants[] = $stem;
        }
        if (str_starts_with($stem, 'ال') && mb_strlen($stem) > 4) {
            $variants[] = mb_substr($stem, 2);
            $stem = mb_substr($stem, 2);
        }

        if (preg_match('/^(.)(.)ا(.)ين$/u', $stem, $m) === 1) {
            $variants[] = $m[1].$m[2].$m[3].'ان';
        }

        if (preg_match('/^(.)(.)(.)ان$/u', $stem, $m) === 1) {
            $variants[] = $m[1].$m[2].'ا'.$m[3].'ين';
        }

        if (str_ends_with($stem, 'ات') && mb_strlen($stem) >= 5) {
            $variants[] = mb_substr($stem, 0, mb_strlen($stem) - 2);
        }

        $variants = array_values(array_unique(array_filter(
            $variants,
            fn (string $variant): bool => mb_strlen($variant) >= 2,
        )));

        return array_slice($variants, 0, self::MAX_VARIANTS_PER_TOKEN);
    }
}
