<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

/**
 * Picks a bounded set of greeting-style examples for a social turn.
 * Commercial turns always get an empty selection.
 */
final class GreetingStyleSelector
{
    public const CAP = 4;

    public const SECTION_KEY = 'training.social_style_references';

    public function __construct(private readonly GreetingStyleLoader $loader = new GreetingStyleLoader) {}

    /**
     * @return list<GreetingStyleRecord>
     */
    public function select(string $message): array
    {
        $normalized = $this->normalize($message);
        $category = $this->categoryFor($normalized);
        if ($category === null) {
            return [];
        }

        $ranked = [];
        foreach ($this->loader->records() as $index => $record) {
            if ($record->category !== $category) {
                continue;
            }
            $user = $this->normalize($record->user);
            $rank = 2;
            if ($user === $normalized) {
                $rank = 0;
            } elseif ($user !== '' && (str_contains($normalized, $user) || str_contains($user, $normalized))) {
                $rank = 1;
            }
            $ranked[] = [$rank, $index, $record];
        }

        usort($ranked, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        return array_map(
            static fn (array $row): GreetingStyleRecord => $row[2],
            array_slice($ranked, 0, self::CAP),
        );
    }

    /**
     * @param  list<GreetingStyleRecord>  $records
     */
    public function section(array $records): ?PromptSection
    {
        if ($records === []) {
            return null;
        }

        $lines = [
            '## Social Style References',
            'These are STYLE references, not mandatory canned replies. Generate a fresh, context-appropriate reply in the same tone.',
            '',
        ];
        foreach ($records as $record) {
            $lines[] = 'user: '.$record->user;
            $lines[] = 'answer_1: '.$record->answer_1;
            $lines[] = 'answer_2: '.$record->answer_2;
            $lines[] = '';
        }

        return new PromptSection(
            PromptLayer::Training,
            self::SECTION_KEY,
            rtrim(implode("\n", $lines)),
            GreetingStyleLoader::RELATIVE_PATH,
        );
    }

    public function referenceSection(string $message): ?PromptSection
    {
        return $this->section($this->select($message));
    }

    private function categoryFor(string $normalized): ?string
    {
        if ($normalized === '' || $this->isCommercial($normalized)) {
            return null;
        }

        if ($this->isHumanIdentityQuestion($normalized)) {
            return 'التعريف بالنفس';
        }

        foreach ($this->buckets() as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function isCommercial(string $normalized): bool
    {
        foreach (['سعر', 'منتج', 'طلب', 'فاتور', 'كتالوج', 'خصم', 'عروض', 'ايفون', 'متوفر', 'شكوى'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A human-identity question addresses the employee and names a person/machine concept.
     * The concept alone is not enough, so a product mention of a robot does not match.
     */
    private function isHumanIdentityQuestion(string $normalized): bool
    {
        if (! str_contains($normalized, 'انت')) {
            return false;
        }

        foreach (['انسان', 'بشري', 'بشر', 'بني ادم', 'شخص حقيقي', 'روبوت', 'ذكاء اصطناعي'] as $concept) {
            if (str_contains($normalized, $concept)) {
                return true;
            }
        }

        return preg_match('/(?:^|\s)بوت(?:\s|$)/u', $normalized) === 1
            || preg_match('/(?:^|\s)ai(?:\s|$)/', $normalized) === 1;
    }

    /**
     * Trigger stems only. Answer text stays in the frozen dataset.
     *
     * @return array<string, list<string>>
     */
    private function buckets(): array
    {
        return [
            'التعريف بالنفس' => ['اجل انت وش', 'اسمك', 'من انت', 'مين انت', 'عرفني'],
            'الشكر والمجاملات' => ['شكر', 'مشكور', 'تسلم', 'يعطيك العاف', 'الله يعطيك', 'كفو', 'والنعم'],
            'إنهاء المحادثة والوداع' => ['مع السلامه', 'باي', 'اشوفك على خير'],
            'الاعتذار والانتظار' => ['اعتذر', 'اسف'],
            'السؤال عن الحال' => ['كيفك', 'كيف الحال', 'علومك', 'كيف امورك', 'كيف يومك', 'كيف وضعك'],
            'التحيات' => ['سلام', 'هلا', 'مرحبا', 'صباح الخير', 'مساء الخير', 'حي الله'],
            'المزح والطقطقة' => ['امزح', 'اطقطق', 'طقطق'],
            'التردد والحيرة' => ['مدري', 'محتار', 'خلني افكر'],
            'العبارات الاجتماعية القصيرة' => ['الله يسلمك', 'يا بعدي', 'يالطيب'],
        ];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ة', 'ى', 'ؤ', 'ئ'], ['ا', 'ا', 'ا', 'ا', 'ا', 'ي', 'و', 'ي'], $text);
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}{}_]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
