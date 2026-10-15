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
        if ($context->references->retrievalStatus !== 'not_loaded') {
            $sections[] = new PromptSection(
                PromptLayer::Context,
                'context.catalog',
                $this->json([
                    'catalog' => [
                        'retrieval_status' => $context->references->retrievalStatus,
                        'has_more' => $context->references->hasMore,
                        'list_scope' => $context->references->retrievalStatus === 'listed'
                            ? ($context->references->hasMore ? 'partial' : 'complete')
                            : null,
                        'instruction' => $this->catalogInstruction($context->references),
                        'items' => $context->references->catalogItems,
                    ],
                ]),
            );
        }
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

    private function catalogInstruction(ReferenceResolution $references): string
    {
        return match ($references->retrievalStatus) {
            'listed' => ($references->hasMore
                ? 'The items below are a partial list of eligible catalog rows. More eligible items exist. Do not say this is the complete catalog.'
                : 'The items below are the complete list of eligible catalog rows for this question. Do not invent additional items.')
                .' Each item may include variants. A variant price, currency, or availability belongs only to that variant. Do not copy the parent price onto a variant, and do not invent a variant that is not listed. If has_more_variants is true, the variant list is partial. A variant with matches_question true is the requested copy of that product. Do not say that copy is missing, and do not replace the product with a different one only because it shares the requested language.',
            'no_matching_type' => 'The catalog has eligible items, but none of the requested type. Do not say the catalog is empty and do not invent items of the requested type.',
            'catalog_empty' => 'No eligible catalog items are available to show. Do not invent products, services, prices, or availability.',
            'name_not_found' => 'The requested name was not found. Eligible catalog items may still exist. Do not say the catalog is empty.',
            'search_failed' => 'Catalog lookup failed. Do not say the catalog is empty and do not invent items.',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
