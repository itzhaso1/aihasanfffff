<?php

namespace App\Services\AI\Inbox;

use App\Support\Chat\CatalogBrowseQueryDetector;
use App\Support\Chat\GulfArabicInterpreter;

/**
 * Customer-facing text used only when a live inbox run produced no deliverable reply.
 * Never invents offerings, prices, or stock.
 */
final class InboxHonestReplyFallback
{
    public const GREETING = 'هلا، تفضل كيف أقدر أساعدك؟';

    public const OFFERINGS = 'أكيد، خبرني شو نوع الخدمة اللي بتدور عليها وبساعدك.';

    public const GENERIC = 'أكيد، خبرني شو تحتاج وبساعدك.';

    public function __construct(
        private readonly GulfArabicInterpreter $gulfArabic,
        private readonly CatalogBrowseQueryDetector $browseQueries,
    ) {}

    public function forInbound(string $content): string
    {
        $query = trim($content);
        if ($query === '') {
            return self::GENERIC;
        }

        if ($this->isGreeting($query)) {
            return self::GREETING;
        }

        $tokens = $this->gulfArabic->interpret($query)['search_tokens'];
        if ($this->browseQueries->isBrowseCatalogQuery($query, $tokens) || $this->asksAboutOfferings($query)) {
            return self::OFFERINGS;
        }

        return self::GENERIC;
    }

    private function isGreeting(string $query): bool
    {
        $normalized = mb_strtolower($query);

        return (bool) preg_match(
            '/^(?:السلام\s+عليكم|مرحبا|مرحباً|هلا|أهلين|اهلا|أهلا|hi|hello|hey|صباح الخير|مساء الخير)[\s!.؟?]*$/u',
            $normalized,
        );
    }

    private function asksAboutOfferings(string $query): bool
    {
        return (bool) preg_match('/خدمات|خدمة|منتجات|منتج|services?|products?/iu', $query);
    }
}
