<?php

namespace App\Services\Finance\Assistant;

use App\Services\Finance\Assistant\Handlers\FinancialAssistantGreetingHandler;
use App\Services\Finance\Assistant\Handlers\FinancialAssistantOffTopicHandler;

final class FinancialAssistantIntentRouter
{
    public function __construct(
        private readonly FinancialAssistantGreetingHandler $greetings,
        private readonly FinancialAssistantOffTopicHandler $offTopic,
    ) {}

    /**
     * @return array{
     *     intent: string,
     *     answer_ar: string,
     *     answer_en: string,
     *     reason?: string,
     *     variant?: string
     * }|null
     */
    public function resolve(string $question): ?array
    {
        $greeting = $this->greetings->match($question);
        if ($greeting !== null) {
            return $greeting;
        }

        return $this->offTopic->match($question);
    }
}
