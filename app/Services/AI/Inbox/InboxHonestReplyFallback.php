<?php

namespace App\Services\AI\Inbox;

use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
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
        private readonly ChatCatalogRetrievalService $catalogRetrieval,
    ) {}

    public function forInbound(string $content, ?Workspace $workspace = null): string
    {
        $query = trim($content);
        if ($query === '') {
            return self::GENERIC;
        }

        if ($workspace !== null && $this->asksAboutVariant($query)) {
            $catalogReply = $this->catalogFallback($workspace, $query);
            if ($catalogReply !== null) {
                return $catalogReply;
            }
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

    private function asksAboutVariant(string $query): bool
    {
        return preg_match(
            '/مقاس|مقاص|قياس|لون|الوان|نسخه|نسخة|طول|قصير|طويل|صغير|وسط|كبير|size|color|colour|length|variant/iu',
            $query,
        ) === 1;
    }

    private function catalogFallback(Workspace $workspace, string $query): ?string
    {
        try {
            $lookup = $this->catalogRetrieval->lookupForAssistant($workspace, $query, 8);
        } catch (\Throwable) {
            return null;
        }
        if (($lookup['status'] ?? null) !== 'listed') {
            return null;
        }

        $items = is_array($lookup['items'] ?? null) ? $lookup['items'] : [];
        $variantNames = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['variants'] ?? null)) {
                continue;
            }
            foreach ($item['variants'] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $name = trim((string) ($variant['name'] ?? ''));
                if ($name !== '') {
                    $variantNames[] = $name;
                }
            }
        }
        $variantNames = array_values(array_unique($variantNames));
        if ($variantNames === []) {
            return 'ما قدرت أثبت تفاصيل المقاس أو النسخة المطلوبة من الكتالوج حالياً.';
        }

        return 'ما قدرت أثبت التوليفة المطلوبة بالضبط. المسجّل لدينا: '
            .implode('، ', array_slice($variantNames, 0, 8))
            .'، أي واحد تبي؟';
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
