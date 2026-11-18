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
                'pronoun_resolution' => 'Words like (أبغاه, نعم, تمام, كم؟, الثاني, نفسه, خلاص) refer to the active product in context. Do not ask which product.',
                'catalog_truth' => 'Chat catalog is the sole source of truth. Never hallucinate products, prices, or variants.',
                'no_boilerplate_closings' => 'Do not add unsolicited closing questions (e.g. هل ترغب في معرفة المزيد من التفاصيل؟) to simple answers.',
            ],
            'facts_summary' => [
                'active_product' => $facts['interested_product_name'] ?? null,
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

        return trim($cleaned);
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
        foreach ($closingPatterns as $pattern) {
            $result = (string) preg_replace($pattern, '', $result);
        }

        // Ensure trailing period if sentence ends bare
        $trimmed = trim($result);
        if ($trimmed !== '' && ! in_array(mb_substr($trimmed, -1), ['.', '!', '؟', '?', '،'], true)) {
            $trimmed .= '.';
        }

        return $trimmed;
    }
}
