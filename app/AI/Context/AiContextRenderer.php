<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;

final class AiContextRenderer
{
    /**
     * @return list<PromptSection>
     */
    public function sections(AiContext $context): array
    {
        $sections = [];

        if ($context->recentMessages !== []) {
            $sections[] = new PromptSection(
                PromptLayer::Context,
                'context.recent_messages',
                $this->json([
                    'recent_messages' => array_map(static fn (ContextMessage $message): array => $message->toArray(), $context->recentMessages),
                ]),
            );
        }

        if ($context->conversationSummary !== null) {
            $sections[] = new PromptSection(
                PromptLayer::Context,
                'context.summary',
                $this->json(['conversation_summary' => $context->conversationSummary]),
            );
        }

        $sections[] = new PromptSection(
            PromptLayer::Context,
            'context.intent',
            $this->json(['intent' => $context->intent->toArray()]),
        );
        $sections[] = new PromptSection(
            PromptLayer::Context,
            'context.references',
            $this->json(['references' => $context->references->toArray()]),
        );
        $sections[] = new PromptSection(
            PromptLayer::Context,
            'context.state',
            $this->json(['conversation_state' => $context->conversationState->toArray()]),
        );

        if ($context->customerContext['display_name'] !== null || $context->customerContext['customer_id'] !== null) {
            $sections[] = new PromptSection(
                PromptLayer::Context,
                'context.customer',
                $this->json(['customer' => $context->customerContext]),
            );
        }

        $sections[] = new PromptSection(
            PromptLayer::Context,
            'context.employee',
            $this->json(['employee' => $context->employeeContext]),
        );

        if (($context->companyProfile['present'] ?? false) === true) {
            $sections[] = new PromptSection(
                PromptLayer::Context,
                'context.company_profile',
                $this->json(['company_profile' => $context->companyProfile]),
            );
        }

        $sections[] = new PromptSection(
            PromptLayer::Context,
            'context.workspace_policy',
            $this->json(['workspace_policy' => $context->workspacePolicyContext]),
        );

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
