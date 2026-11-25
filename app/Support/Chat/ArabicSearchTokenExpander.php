<?php

namespace App\Support\Chat;

/**
 * Bounded Arabic singular/plural variants for catalog search.
 * At most a few extra tokens per input so SQL stays small.
 */
final class ArabicSearchTokenExpander
{
    public const MAX_VARIANTS_PER_TOKEN = 4;

    public const MAX_TOKENS = 16;

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
        $languageStem = $this->languageStem($folded);
        if ($languageStem !== null) {
            $variants[] = $languageStem;
        }

        $brokenPlurals = [
            'كتب' => 'كتاب',
            'كتاب' => 'كتب',
            'عطور' => 'عطر',
            'عطر' => 'عطور',
            'روايات' => 'رواية',
            'رواية' => 'روايات',
            'ساعات' => 'ساعة',
            'ساعة' => 'ساعات',
            'دورات' => 'دورة',
            'دورة' => 'دورات',
            'كورسات' => 'كورس',
            'كورس' => 'كورسات',
            'ملابس' => 'لبس',
            'حسابات' => 'حساب',
            'حساب' => 'حسابات',
            'احذية' => 'حذاء',
            'حذاء' => 'احذية',
            'شوز' => 'حذاء',
            'اجهزة' => 'جهاز',
            'جهاز' => 'اجهزة',
            'سماعات' => 'سماعة',
            'سماعه' => 'سماعات',
            'سماعة' => 'سماعات',
            'شنط' => 'شنطة',
            'شنطه' => 'شنطة',
            'حقيبة' => 'شنطة',
            'حقيبه' => 'شنطة',
            'حقائب' => 'حقيبة',
            'فساتين' => 'فستان',
            'فستان' => 'فساتين',
            'عبايات' => 'عباية',
            'عبايه' => 'عبايات',
            'عباية' => 'عبايات',
            'قمصان' => 'قميص',
            'قميص' => 'قمصان',
            'هوديات' => 'هودي',
            'هودي' => 'بلوفر',
            'شواحن' => 'شاحن',
            'شاحن' => 'شواحن',
            'باقات' => 'باقة',
            'باقه' => 'باقة',
            'اشتراكات' => 'اشتراك',
            'اشتراك' => 'اشتراكات',
            'بطاقات' => 'بطاقة',
            'بطاقه' => 'بطاقة',
            'جوالات' => 'جوال',
            'جوال' => 'هاتف',
            'موبايل' => 'جوال',
            'لابتوبات' => 'لابتوب',
            'كريمات' => 'كريم',
        ];
        if (isset($brokenPlurals[$folded])) {
            $variants[] = $brokenPlurals[$folded];
        }

        $bilingualSynonyms = [
            'اكسل' => ['excel'],
            'اكسيل' => ['excel'],
            'ايسكل' => ['excel'],
            'ايكسل' => ['excel'],
            'excel' => ['اكسل'],
            'بايثون' => ['python'],
            'بايثن' => ['python', 'بايثون'],
            'python' => ['بايثون'],
            'ايفون' => ['iphone'],
            'iphone' => ['ايفون'],
            'سامسونج' => ['samsung'],
            'سامسونق' => ['samsung', 'سامسونج'],
            'samsung' => ['سامسونج'],
            'ماك' => ['macbook', 'mac'],
            'ماكبوك' => ['macbook'],
            'macbook' => ['ماكبوك'],
            'سماعه' => ['airpods', 'headphones'],
            'سماعة' => ['airpods', 'headphones'],
            'سماعات' => ['airpods', 'headphones'],
            'airpods' => ['سماعه', 'سماعة'],
            'شاحن' => ['charger'],
            'charger' => ['شاحن'],
            'ساعه' => ['watch', 'smartwatch'],
            'ساعة' => ['watch', 'smartwatch'],
            'watch' => ['ساعة', 'ساعه'],
            'فستان' => ['dress'],
            'dress' => ['فستان'],
            'عطر' => ['perfume', 'fragrance'],
            'عطور' => ['perfume', 'fragrance'],
            'perfume' => ['عطر'],
            'هودي' => ['hoodie'],
            'hoodie' => ['هودي'],
            'حذاء' => ['shoes'],
            'شوز' => ['shoes', 'حذاء'],
            'shoes' => ['حذاء', 'شوز'],
            'عبايه' => ['abaya'],
            'عباية' => ['abaya'],
            'abaya' => ['عباية'],
            'شنطه' => ['bag'],
            'شنطة' => ['bag'],
            'حقيبه' => ['bag'],
            'bag' => ['شنطة', 'حقيبة'],
        ];
        foreach ($bilingualSynonyms[$folded] ?? [] as $synonym) {
            $variants[] = $synonym;
        }

        $forms = [$folded];
        $stem = $folded;
        if (preg_match('/^[بوفكل]/u', $stem) === 1 && mb_strlen($stem) >= 5) {
            $stem = mb_substr($stem, 1);
            $variants[] = $stem;
            $forms[] = $stem;
        }
        if (str_starts_with($stem, 'ال') && mb_strlen($stem) > 4) {
            $stem = mb_substr($stem, 2);
            $variants[] = $stem;
            $forms[] = $stem;
        }

        foreach (array_values(array_unique($forms)) as $form) {
            if (preg_match('/^(.)(.)ا(.)ين$/u', $form, $m) === 1) {
                $variants[] = $m[1].$m[2].$m[3].'ان';
            }

            if (preg_match('/^(.)(.)(.)ان$/u', $form, $m) === 1) {
                $variants[] = $m[1].$m[2].'ا'.$m[3].'ين';
            }

            if (str_ends_with($form, 'ات') && mb_strlen($form) >= 5) {
                $variants[] = mb_substr($form, 0, mb_strlen($form) - 2);
            }
        }

        $variants = array_values(array_unique(array_filter(
            $variants,
            fn (string $variant): bool => mb_strlen($variant) >= 2,
        )));

        return array_slice($variants, 0, self::MAX_VARIANTS_PER_TOKEN);
    }

    private function languageStem(string $folded): ?string
    {
        $stem = $folded;
        foreach (['بال', 'لل', 'ال', 'ب', 'ل', 'ف', 'ك', 'و'] as $prefix) {
            if (str_starts_with($stem, $prefix) && mb_strlen($stem) > mb_strlen($prefix) + 2) {
                $stem = mb_substr($stem, mb_strlen($prefix));
                break;
            }
        }

        if (str_starts_with($stem, 'عربي') && $stem !== 'عربي') {
            return 'عربي';
        }
        if (str_starts_with($stem, 'انجليزي') && $stem !== 'انجليزي') {
            return 'انجليزي';
        }

        return null;
    }
}
