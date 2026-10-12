<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Flags training sentences that restate a constitution law. Laws live only in the
 * constitution; training explains behavior. Sentences are compared on content words
 * (function words removed) using the larger of word-set similarity and containment,
 * so light rewording of a law, or a law embedded in a longer sentence, is caught.
 */
final class ConstitutionOverlapDetector
{
    public const SIMILARITY_THRESHOLD = 0.8;

    private const MIN_WORDS = 5;

    private const FUNCTION_WORDS = [
        'the', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'is', 'are', 'be', 'it', 'its',
        'as', 'at', 'by', 'from', 'that', 'this', 'these', 'those', 'you', 'your', 'they', 'their', 'them',
        'we', 'our', 'do', 'does', 'if', 'then', 'than', 'so', 'but', 'into', 'up', 'any', 'all', 'each',
        'can', 'may', 'will', 'would', 'should', 'such', 'one', 'there', 'what', 'which', 'who', 'about',
    ];

    /** @var list<array{text: string, words: list<string>}> */
    private array $laws = [];

    /**
     * @param  list<string>  $lawTexts  Constitution section contents.
     */
    public function __construct(array $lawTexts)
    {
        foreach ($lawTexts as $text) {
            foreach ($this->sentences($text) as $sentence) {
                $this->laws[] = $sentence;
            }
        }
    }

    /**
     * @return list<string> Law sentences the document restates.
     */
    public function overlaps(TrainingDocument $document): array
    {
        $found = [];
        foreach ($this->sentences($document->content) as $sentence) {
            foreach ($this->laws as $law) {
                if ($this->similarity($sentence['words'], $law['words']) >= self::SIMILARITY_THRESHOLD) {
                    $found[] = $law['text'];
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<array{text: string, words: list<string>}>
     */
    private function sentences(string $text): array
    {
        $sentences = [];
        foreach (preg_split('/(?<=[.!?؟])\s+|\n+/u', $text) ?: [] as $raw) {
            $clean = trim((string) preg_replace('/^[#>*\-\s]*(\d+\.)?\s*/u', '', $raw));
            $words = $this->words($clean);
            if (count($words) >= self::MIN_WORDS) {
                $sentences[] = ['text' => $clean, 'words' => $words];
            }
        }

        return $sentences;
    }

    /**
     * @return list<string>
     */
    private function words(string $sentence): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($sentence)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) >= 2 && ! in_array($word, self::FUNCTION_WORDS, true),
        )));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function similarity(array $a, array $b): float
    {
        $shared = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));
        $smaller = min(count($a), count($b));
        if ($union === 0 || $smaller === 0) {
            return 0.0;
        }

        return max($shared / $union, $shared / $smaller);
    }
}
