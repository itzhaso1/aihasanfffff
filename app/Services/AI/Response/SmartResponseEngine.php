<?php

declare(strict_types=1);

namespace App\Services\AI\Response;

use App\Models\Conversation;
use App\Models\Message;

final class SmartResponseEngine
{
    /**
     * Rules as array for structured prompt context (JSON compatible).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function rulesPayload(array $facts, bool $hasRecentMessages): array
    {
        return [
            'mode' => 'smart_concise_response',
            'has_prior_turn' => $hasRecentMessages,
            'rules' => [
                'direct_answer' => 'Answer the customer question directly without introduction or filler.',
                'length_intelligence' => 'Match answer length to question complexity (short question = short direct answer).',
                'no_repeated_greetings' => $hasRecentMessages
                    ? 'Do NOT repeat greetings (أهلاً وسهلاً, مرحباً, حياك الله, etc.) on follow-up turns.'
                    : 'Greet naturally on the initial turn only.',
                'no_repeated_customer_name' => 'Do NOT mention the customer name on every single turn.',
                'anti_repetition_pricing' => ($facts['price_clarified'] ?? false)
                    ? 'Price was already clarified. Do not restate the price or sales pitch unless the customer specifically asks again.'
                    : 'State the price directly from the catalog.',
                'anti_repetition_delivery' => ($facts['delivery_clarified'] ?? false)
                    ? 'Delivery was already clarified. Do not repeat delivery terms unless asked.'
                    : 'Provide delivery info directly if asked.',
                'pronoun_resolution' => 'Words like (أبغاه, نعم, تمام, كم؟, الثاني, نفسه, خلاص, في منه) refer to the active product in context. Do not ask which product.',
                'catalog_truth' => 'Chat catalog and verified tool outputs are the sole source of truth. Never hallucinate products, prices, discounts, stock, shipping fees, or store policies.',
                'multi_intent_coverage' => 'If the customer asks multiple questions in one message (e.g. price + delivery + warranty), answer every part clearly in the same reply without dropping any point.',
                'anti_repetition_identical_answer' => 'Do NOT repeat the exact same sentence, opening phrase, or closing question used in previous AI turns.',
                'no_boilerplate_closings' => 'Do not add unsolicited closing questions (e.g. هل ترغب في معرفة المزيد من التفاصيل؟) to simple answers.',
                'varied_openings' => ! empty($facts['recent_ai_openings'])
                    ? 'Do NOT start your reply with any of these recently used openings: '.implode(' | ', array_slice((array) $facts['recent_ai_openings'], -3))
                    : 'Keep openings varied, natural, and direct.',
            ],
            'facts_summary' => [
                'active_product' => $facts['interested_product_name'] ?? null,
                'selected_variant' => $facts['selected_variant'] ?? null,
                'budget' => $facts['budget'] ?? null,
                'price_already_clarified' => (bool) ($facts['price_clarified'] ?? false),
                'delivery_already_clarified' => (bool) ($facts['delivery_clarified'] ?? false),
                'purchase_intent' => (bool) ($facts['purchase_intent'] ?? false),
            ],
        ];
    }

    /**
     * Sanitizes generated replies to eliminate repetitive greetings and canned boilerplates on follow-ups.
     *
     * @param  array<string, mixed>  $facts
     */
    public function sanitizeReply(
        string $reply,
        Conversation $conversation,
        Message $incomingMessage,
        array $facts = [],
    ): string {
        $cleaned = trim($reply);
        if ($cleaned === '') {
            return '';
        }

        // Check if this is a follow-up turn (conversation has prior messages or incoming has history)
        $hasHistory = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('id', '<', $incomingMessage->id ?? PHP_INT_MAX)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->exists();

        if ($hasHistory) {
            $cleaned = $this->cleanRepetitiveGreetings($cleaned);
            $customerName = $facts['customer_name'] ?? null;
            if (is_string($customerName) && trim($customerName) !== '') {
                $cleaned = $this->cleanCustomerNameVocatives($cleaned, trim($customerName));
            }
        }

        // Clean verbose intros like "يسعدنا اهتمامك بـ[المنتج]، وهو من المنتجات المميزة لدينا، "
        $cleaned = $this->cleanVerboseProductIntros($cleaned);

        // Clean unsolicited trailing questions (e.g. "هل ترغب في معرفة المزيد من التفاصيل؟")
        $isPurchaseIntent = (bool) ($facts['purchase_intent'] ?? false);
        $cleaned = $this->cleanUnsolicitedClosing($cleaned, $isPurchaseIntent);

        // Avoid verbatim repeating of identical consecutive AI responses
        $cleaned = $this->diversifyConsecutiveIdenticalReply($cleaned, $conversation, $incomingMessage);

        return trim($cleaned);
    }

    /**
     * Prevents repeating the exact same reply verbatim across recent AI turns
     * without stripping factual product/price content into generic filler.
     */
    public function diversifyConsecutiveIdenticalReply(
        string $reply,
        Conversation $conversation,
        Message $incomingMessage,
    ): string {
        $recentAiReplies = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('ai_generated', true)
            ->where('id', '<', $incomingMessage->id ?? PHP_INT_MAX)
            ->latest('id')
            ->limit(3)
            ->get();

        if ($recentAiReplies->isEmpty()) {
            return $reply;
        }

        $normCurrent = $this->normalizeArabicComparison($reply);
        if ($normCurrent === '') {
            return $reply;
        }

        $isVerbatimRepeat = false;
        foreach ($recentAiReplies as $priorReply) {
            $normPrior = $this->normalizeArabicComparison((string) $priorReply->content);
            if ($normPrior !== '' && $normPrior === $normCurrent) {
                $isVerbatimRepeat = true;
                break;
            }
        }

        if ($isVerbatimRepeat) {
            $trimmed = ltrim($reply);
            $trimmed = $this->cleanRepetitiveGreetings($trimmed);
            if ($this->normalizeArabicComparison($trimmed) !== $normCurrent && trim($trimmed) !== '') {
                return trim($trimmed);
            }

            if (! str_starts_with($trimmed, 'للتوضيح') && ! str_starts_with($trimmed, 'تأكيداً')) {
                return "تأكيداً لك:\n".$trimmed;
            }
        }

        return $reply;
    }

    private function normalizeArabicComparison(string $text): string
    {
        $normalized = trim($text);
        $normalized = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[إأآآ]/u', 'ا', $normalized) ?? $normalized;
        $normalized = preg_replace('/ة/u', 'ه', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B?!؟.,؛");

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * Remove repetitive greetings at the start of a response.
     */
    public function cleanRepetitiveGreetings(string $text): string
    {
        $patterns = [
            '/^(?:أهلاً\s*و\s*سهلاً(?:\s*بك)?|مرحباً(?:\s*بك)?|أهلاً\s*بك|حياك\s*الله|يا\s*هلا|يسعدنا\s*تواصلك|يسعدني\s*مساعدتك|نحن\s*هنا\s*لخدمتك|صباح\s*الخير|مساء\s*الخير)[\s،,!-]*\s*/u',
            '/^(?:أهلاً|مرحباً)[\s،,!-]+\s*/u',
        ];

        $result = $text;
        foreach ($patterns as $pattern) {
            $result = (string) preg_replace($pattern, '', $result);
        }

        return $result;
    }

    /**
     * Remove customer vocatives at message start (e.g. "أهلاً يا أحمد، ").
     */
    public function cleanCustomerNameVocatives(string $text, string $customerName): string
    {
        $escaped = preg_quote($customerName, '/');
        $pattern = '/^(?:أهلاً(?:\s*وسهلاً)?|مرحباً|حياك\s*الله)?\s*(?:يا\s*)?'.$escaped.'[\s،,!-]+\s*/u';

        return (string) preg_replace($pattern, '', $text);
    }

    /**
     * Clean verbose introductory sales pitches on factual questions.
     * E.g.: "يسعدنا اهتمامك بكتاب Excel، وهو من الكتب المميزة لدينا، وسعره 10 ريال" -> "سعر كتاب Excel هو 10 ريال"
     */
    public function cleanVerboseProductIntros(string $text): string
    {
        // "يسعدنا اهتمامك بـ X، وهو من...، وسعره Y" -> "سعره Y"
        $pattern = '/^يسعدنا\s*اهتمامك\s*ب[^\n،,.]+،?\s*(?:وهو|وهي)\s*من\s*[^\n،,.]+،?\s*و\s*/u';

        return (string) preg_replace($pattern, '', $text);
    }

    /**
     * Remove canned closing questions when not relevant to an ordering step.
     */
    public function cleanUnsolicitedClosing(string $text, bool $isPurchaseIntent = false): string
    {
        // Trailing boilerplate questions
        $closingPatterns = [
            '/(?:[\s،,.-]+|^)(?:هل\s*ترغب\s*في\s*معرفة\s*المزيد(?:\s*من\s*التفاصيل)?|هل\s*تود\s*معرفة\s*المزيد(?:\s*من\s*التفاصيل)?|يسعدني\s*الرد\s*على\s*استفساراتك(?:\s*الأخرى)?|كيف\s*(?:يمكنني|أقدر)\s*أساعدك\s*اليوم|نحن\s*هنا\s*لمساعدتك|يسعدنا\s*خدمتك)\s*[؟?]?\s*$/u',
        ];

        if (! $isPurchaseIntent) {
            // Also clean unsolicited order push if customer just asked for a price
            $closingPatterns[] = '/(?:[\s،,.-]+)(?:هل\s*ترغب\s*في\s*(?:إتمام|طلب)\s*الطلب\s*الآن|هل\s*تود\s*الشراء\s*الآن)\s*[؟?]?\s*$/u';
        }

        $result = $text;
        $modified = false;
        foreach ($closingPatterns as $pattern) {
            $replaced = (string) preg_replace($pattern, '', $result);
            if ($replaced !== $result) {
                $result = $replaced;
                $modified = true;
            }
        }

        $trimmed = trim($result);
        if ($modified && $trimmed !== '' && ! in_array(mb_substr($trimmed, -1), ['.', '!', '؟', '?', '،'], true)) {
            $trimmed .= '.';
        }

        return $trimmed;
    }
}
