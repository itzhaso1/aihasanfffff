<?php

namespace App\Support\Chat;

/**
 * Detects customer messages that ask to browse the catalog rather than match a product name.
 */
final class CatalogBrowseQueryDetector
{
    /** @var list<string> */
    private const PRODUCT_SURFACE_FORMS = [
        'منتجات', 'منتج', 'منتجاتكم', 'منتجاتنا', 'بضائع', 'بضاعة', 'سلع', 'سلعة',
        'products', 'product', 'items', 'item', 'catalog', 'catalogue', 'menu',
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
        'تقدمونها', 'تقدمون', 'تقدم', 'تعرض', 'تعرضون', 'تعرضلي', 'تقدمونها', 'this', 'that', 'the',
        'which', 'who', 'please', 'ممكن', 'please', 'show', 'list', 'display',
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
     * @return list<string>
     */
    private function genericSurfaceForms(): array
    {
        return array_values(array_unique(array_merge(
            self::PRODUCT_SURFACE_FORMS,
            self::SERVICE_SURFACE_FORMS,
            ['معينة', 'معين', 'متوفرة', 'متوف', 'available', 'offer', 'offers', 'sell', 'selling'],
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
            $folded = $this->normalizeListingToken($token);
            if ($folded === '' || in_array($folded, self::FILLER_TOKENS, true)) {
                continue;
            }
            $normalized[] = $folded;
        }

        return array_values(array_unique($normalized));
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
