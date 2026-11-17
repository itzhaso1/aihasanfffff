<?php

namespace App\Support\Chat;

/**
 * Detects customer messages that ask to browse the catalog rather than match a product name.
 */
final class CatalogBrowseQueryDetector
{
    /** @var list<string> */
    public const BOOK_SURFACE_FORMS = [
        'كتب', 'كتاب', 'كتيب', 'كتيبات', 'مؤلفات', 'مؤلف', 'روايات', 'رواية', 'مجلدات', 'مجلد',
        'اصدارات', 'إصدارات', 'منشورات', 'عناوين',
        'books', 'book', 'novels', 'novel', 'titles',
    ];

    /** @var list<string> */
    public const CLOTHING_SURFACE_FORMS = [
        'فساتين', 'فستان', 'ملابس', 'لبس', 'قمصان', 'قميص', 'عبايات', 'عباية',
        'تنورة', 'بناطيل', 'بنطلون', 'تيشيرت', 'احذية', 'حذاء', 'شوز',
        'dress', 'dresses', 'clothes', 'clothing', 'shirt', 'abaya', 'shoes',
    ];

    /** @var list<string> */
    public const PERFUME_SURFACE_FORMS = [
        'عطور', 'عطر', 'بخور', 'معطرات', 'روائح',
        'perfume', 'perfumes', 'fragrance', 'oud',
    ];

    /** @var list<string> */
    private const PRODUCT_SURFACE_FORMS = [
        'منتجات', 'منتج', 'منتجاتكم', 'منتجاتنا', 'بضائع', 'بضاعة', 'سلع', 'سلعة',
        'كتب', 'كتاب', 'كتيب', 'كتيبات', 'مؤلفات', 'مؤلف', 'روايات', 'رواية', 'مجلدات', 'مجلد',
        'اصدارات', 'إصدارات', 'منشورات', 'عناوين',
        'عناصر', 'عنصر', 'أشياء', 'اشياء',
        'products', 'product', 'items', 'item', 'catalog', 'catalogue', 'menu',
        'books', 'book', 'novels', 'novel', 'titles',
    ];

    /** @var list<string> */
    private const SERVICE_SURFACE_FORMS = [
        'خدمات', 'خدمة', 'خدماتكم', 'خدماتنا',
        'services', 'service',
    ];

    /** @var list<string> */
    private const LISTING_INTENTS = ['availability', 'what', 'request'];

    /** @var list<string> */
    private const FILLER_TOKENS = [
        'اللي', 'الي', 'الذي', 'التي', 'من', 'ل', 'لي', 'لك', 'لكم', 'لنا', 'لي', 'علي', 'على',
        'تقدمونها', 'تقدمون', 'تقدم', 'تعرض', 'تعرضون', 'تعرضلي', 'this', 'that', 'the',
        'which', 'who', 'please', 'ممكن', 'show', 'list', 'display',
        'شو', 'وش', 'ايش', 'شنو', 'what', 'هل', 'عند', 'عندك', 'عندكم', 'عندنا',
        'لديكم', 'لدينا',
        'ما', 'هي', 'هو',
        'قولي', 'قلي', 'قل', 'ورني', 'عطني', 'عطيني', 'اشوف', 'شوف', 'اعرض', 'عرض',
        'بدي', 'ابي', 'ابغى', 'ودنا', 'حابب', 'حاب', 'حااب', 'أريد', 'اريد', 'تبغى', 'تبي', 'بدنا',
        'تعرفني', 'عرفني', 'اخبرني', 'خبرني',
    ];

    public function __construct(
        private readonly GulfArabicInterpreter $gulfArabic,
        private readonly ArabicSearchNormalizer $normalizer,
    ) {}

    public function isBrowseCatalogQuery(string $query, array $searchTokens): bool
    {
        $query = trim($query);
        if ($query === '') {
            return false;
        }

        $gulf = $this->gulfArabic->interpret($query);
        $intents = $gulf['intents'];

        $normalizedTokens = $this->meaningfulListingTokens($searchTokens);

        if ($normalizedTokens !== []) {
            $nonGeneric = array_diff($normalizedTokens, $this->genericSurfaceForms());

            return $nonGeneric === [];
        }

        $listingHits = array_intersect($intents, self::LISTING_INTENTS);

        return count($listingHits) >= 2;
    }

    /**
     * When browsing, optionally restrict to products or services.
     */
    public function browseItemKindFilter(string $query, array $searchTokens): ?string
    {
        $forms = $this->collectNormalizedForms($query, $searchTokens);
        $hasProduct = (bool) array_intersect($forms, self::PRODUCT_SURFACE_FORMS);
        $hasService = (bool) array_intersect($forms, self::SERVICE_SURFACE_FORMS);

        if ($hasService && ! $hasProduct) {
            return 'service';
        }

        if ($hasProduct && ! $hasService) {
            return 'product';
        }

        return null;
    }

    /**
     * @param  list<string>  $searchTokens
     * @return list<string>
     */
    public function browseEntityTokens(string $query, array $searchTokens): array
    {
        $forms = $this->collectNormalizedForms($query, $searchTokens);
        $matches = [];
        foreach ([self::BOOK_SURFACE_FORMS, self::CLOTHING_SURFACE_FORMS, self::PERFUME_SURFACE_FORMS] as $group) {
            $hits = array_intersect($forms, $group);
            if ($hits !== []) {
                $matches = array_merge($matches, $hits);
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @param  list<string>  $entityTokens
     * @return list<string>
     */
    public function conflictingTokensFor(array $entityTokens): array
    {
        $conflicts = [];
        $isBook = (bool) array_intersect($entityTokens, self::BOOK_SURFACE_FORMS);
        $isClothing = (bool) array_intersect($entityTokens, self::CLOTHING_SURFACE_FORMS);
        $isPerfume = (bool) array_intersect($entityTokens, self::PERFUME_SURFACE_FORMS);

        if ($isBook) {
            $conflicts = array_merge($conflicts, self::CLOTHING_SURFACE_FORMS, self::PERFUME_SURFACE_FORMS);
        }
        if ($isClothing) {
            $conflicts = array_merge($conflicts, self::BOOK_SURFACE_FORMS, self::PERFUME_SURFACE_FORMS);
        }
        if ($isPerfume) {
            $conflicts = array_merge($conflicts, self::BOOK_SURFACE_FORMS, self::CLOTHING_SURFACE_FORMS);
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * @return list<string>
     */
    private function genericSurfaceForms(): array
    {
        return array_values(array_unique(array_merge(
            self::PRODUCT_SURFACE_FORMS,
            self::SERVICE_SURFACE_FORMS,
            [
                'معينة', 'معين',
                'متوفرة', 'متوفره', 'متوفر', 'متوف',
                'متاحة', 'متاحه', 'متاح',
                'موجود', 'موجوده', 'موجودة',
                'available', 'offer', 'offers', 'sell', 'selling',
            ],
        )));
    }

    /**
     * @return list<string>
     */
    private function collectNormalizedForms(string $query, array $searchTokens): array
    {
        $forms = [];
        foreach ($searchTokens as $token) {
            $forms[] = $this->normalizeListingToken($token);
        }

        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($query)) ?: [];
        foreach ($words as $word) {
            $surface = trim((string) $word);
            if ($surface === '') {
                continue;
            }
            $forms[] = $this->normalizeListingToken($surface);
        }

        return array_values(array_unique(array_filter($forms)));
    }

    /**
     * @param  list<string>  $searchTokens
     * @return list<string>
     */
    private function meaningfulListingTokens(array $searchTokens): array
    {
        $normalized = [];
        foreach ($searchTokens as $token) {
            if ($this->isFillerToken($token)) {
                continue;
            }
            $folded = $this->normalizeListingToken($token);
            if ($folded === '' || $this->isFillerToken($folded)) {
                continue;
            }
            $normalized[] = $folded;
        }

        return array_values(array_unique($normalized));
    }

    private function isFillerToken(string $token): bool
    {
        $normalized = $this->normalizer->normalize($token);
        $normalized = preg_replace('/^(?:ال|ل)/u', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        if ($normalized === '') {
            return true;
        }
        if (in_array($normalized, self::FILLER_TOKENS, true)) {
            return true;
        }

        $stripped = trim(preg_replace('/(?:كم|نا|ك|ي|ه)$/u', '', $normalized) ?? $normalized);

        return $stripped !== '' && in_array($stripped, self::FILLER_TOKENS, true);
    }

    private function normalizeListingToken(string $token): string
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return '';
        }

        $token = $this->normalizer->normalize($token);
        $token = preg_replace('/^(?:ال|ل)/u', '', $token) ?? $token;
        $token = preg_replace('/(?:كم|نا|ك|ي|ه)$/u', '', $token) ?? $token;

        return trim($token);
    }
}
