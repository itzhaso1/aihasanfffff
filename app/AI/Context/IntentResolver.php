<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\Support\Chat\GulfArabicInterpreter;

final class IntentResolver
{
    /** @var array<string, string> */
    private const DIRECT_MAP = [
        'price' => 'price_question',
        'availability' => 'availability_question',
        'offers' => 'discount_request',
        'budget' => 'price_question',
        'recommended' => 'recommendation_request',
        'comparison' => 'product_comparison',
        'shipping' => 'shipping_question',
        'returns' => 'return_policy',
        'payment' => 'payment_question',
        'order_status' => 'order_status',
        'complaint' => 'complaint',
        'store_info' => 'store_info',
        'human_request' => 'human_request',
        'booking_reschedule' => 'booking_reschedule',
        'booking_cancel' => 'booking_cancel',
        'booking_inquiry' => 'booking_inquiry',
        'booking_request' => 'booking_request',
    ];

    /** @var list<string> */
    private const CONTEXTUAL_MESSAGES = [
        'اي', 'ايه', 'نعم', 'هذا', 'هذي', 'هذه', 'ذا', 'الثاني', 'الثالث',
        'الاول', 'الأول', 'طيب', 'تمام', 'اوكي', 'موجود', 'والأسود', 'والاسود',
        'ابي', 'ابغى', 'كم', 'كم حقه', 'ابغاه', 'أبغاه', 'ابيه', 'باخذه',
        'باخذها', 'شريت', 'خلاص', 'نفسه', 'نفسها', 'احجزه', 'احجزلي', 'اعتمد',
        'اريده', 'منه', 'في منه', 'فيه منه', 'غيره', 'ارخص', 'تفاصيل', 'تفاصيله',
        'صورته', 'صوره',
    ];

    public function __construct(private readonly GulfArabicInterpreter $interpreter) {}

    public function resolve(
        string $message,
        ConversationState $state,
        bool $hasProductContext = false,
    ): ResolvedIntent {
        $reading = $this->interpreter->interpret($message);
        $rawIntents = $reading['intents'];
        $matchedCanonicals = $this->allDirectIntents($rawIntents);
        $canonical = $matchedCanonicals[0] ?? null;
        $secondaryIntents = array_slice($matchedCanonicals, 1);
        $usedState = false;

        if ($canonical === null && in_array('request', $rawIntents, true) && $hasProductContext) {
            $canonical = 'purchase_intent';
        } elseif (in_array('request', $rawIntents, true) && $hasProductContext && $canonical !== 'purchase_intent') {
            $secondaryIntents[] = 'purchase_intent';
        }

        if ($canonical === null && $this->isContextualFollowUp(
            $reading['normalized'],
            $rawIntents,
            $reading['search_tokens'],
            $hasProductContext || $state->referencedProductIds !== [],
        ) && $state->lastIntent !== null) {
            $canonical = $state->lastIntent;
            $usedState = true;
        }

        return new ResolvedIntent(
            canonical: $canonical,
            rawIntents: $rawIntents,
            searchTokens: $reading['search_tokens'],
            dialectHint: $reading['dialect_hint'],
            usedConversationState: $usedState,
            secondaryIntents: $secondaryIntents,
        );
    }

    /**
     * @param  list<string>  $rawIntents
     * @return list<string>
     */
    private function allDirectIntents(array $rawIntents): array
    {
        $resolved = [];
        foreach ($rawIntents as $raw) {
            if (isset(self::DIRECT_MAP[$raw])) {
                $mapped = self::DIRECT_MAP[$raw];
                if (! in_array($mapped, $resolved, true)) {
                    $resolved[] = $mapped;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $rawIntents
     * @param  list<string>  $searchTokens
     */
    private function isContextualFollowUp(
        string $normalized,
        array $rawIntents,
        array $searchTokens,
        bool $hasProductContext,
    ): bool {
        if (in_array('followup', $rawIntents, true)) {
            return true;
        }

        return in_array(trim($normalized), self::CONTEXTUAL_MESSAGES, true)
            || ($hasProductContext && count($searchTokens) <= 3);
    }
}
