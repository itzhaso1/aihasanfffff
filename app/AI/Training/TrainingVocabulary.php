<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Skills\SkillRegistry;

/**
 * Compatibility selector vocabulary for `applies_to`. Skills come from the canonical
 * registry; the remaining selector dimensions stay here so training linting can fail
 * closed on typos before a document becomes unreachable.
 */
final class TrainingVocabulary
{
    /** @var array<string, list<string>> */
    private const VALUES = [
        'intents' => [
            'greeting', 'short_message', 'follow_up', 'price_question', 'availability_question',
            'product_search', 'product_details', 'purchase_intent', 'discount_request', 'objection',
            'human_request', 'complaint', 'confusion', 'off_topic',
        ],
        'roles' => [
            'sales_agent', 'customer_support', 'booking_agent', 'receptionist',
            'product_advisor', 'general_business_assistant',
        ],
        'dialects' => [
            'saudi', 'gulf', 'kuwaiti', 'emirati', 'qatari', 'bahraini', 'omani',
            'jordanian', 'egyptian', 'levantine', 'neutral',
        ],
        'languages' => ['ar', 'en'],
        'domains' => ['general', 'fashion', 'electronics', 'restaurant', 'beauty', 'services'],
    ];

    public static function knows(TrainingDimension $dimension, string $value): bool
    {
        if ($dimension === TrainingDimension::Skills) {
            return SkillRegistry::exists($value);
        }

        return in_array($value, self::VALUES[$dimension->value], true);
    }

    /**
     * @return list<string>
     */
    public static function values(TrainingDimension $dimension): array
    {
        if ($dimension === TrainingDimension::Skills) {
            return SkillRegistry::enabledKeys();
        }

        return self::VALUES[$dimension->value];
    }
}
