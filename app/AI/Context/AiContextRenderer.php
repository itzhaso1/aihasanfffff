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
            $catalog = [
                'retrieval_status' => $context->references->retrievalStatus,
                'has_more' => $context->references->hasMore,
                'list_scope' => $context->references->retrievalStatus === 'listed'
                    ? ($context->references->hasMore ? 'partial' : 'complete')
                    : null,
                'instruction' => $this->catalogInstruction($context->references),
                'items' => $context->references->catalogItems,
            ];
            $requestedCopies = $this->requestedCopies($context->references);
            if ($requestedCopies !== []) {
                $catalog['requested_copies'] = $requestedCopies;
            }
            $sections[] = new PromptSection(
                PromptLayer::Context,
                'context.catalog',
                $this->json(['catalog' => $catalog]),
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
                .' Each item may include variants. A variant price, currency, stock, and availability belongs only to that exact variant. Do not copy the parent price or stock onto a variant, and do not invent a variant that is not listed. If two or more variant rows are listed, never say the product has one size or one variant. If has_more_variants is true, the variant list is partial. When variant_match_status is matched, requested_copies and any variant with matches_question true are the exact requested combinations: use only those rows for the answer. Listed copies exist even when availability is out_of_stock. If that exact copy is out_of_stock, say it is listed and currently out of stock. When variant_match_status is not_found, the requested size, color, length, or type was not verified in one listed variant: do not claim the parent or another variant satisfies it; say the requested combination is not listed or could not be confirmed and offer only the listed alternatives by name. When variant_match_status is not_requested, answer the parent product question without inventing a specific option. Do not say you will check later, do not invent another language, and never say the product is English-only when an Arabic copy is listed. Parent availability and an English word in the product title are not a language.',
            'no_matching_type' => 'The catalog has eligible items, but none of the requested type. Do not say the catalog is empty and do not invent items of the requested type.',
            'catalog_empty' => 'No eligible catalog items are available to show. Do not invent products, services, prices, or availability.',
            'name_not_found' => 'The requested name was not found. Eligible catalog items may still exist. Do not say the catalog is empty.',
            'search_failed' => 'Catalog lookup failed. Do not say the catalog is empty and do not invent items.',
            default => '',
        };
    }

    /**
     * @return list<array{product: string, copy: string, exists: true, availability: string|null}>
     */
    private function requestedCopies(ReferenceResolution $references): array
    {
        if ($references->retrievalStatus !== 'listed') {
            return [];
        }

        $copies = [];
        foreach ($references->catalogItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $product = is_string($item['name'] ?? null) ? trim($item['name']) : '';
            $variants = is_array($item['variants'] ?? null) ? $item['variants'] : [];
            foreach ($variants as $variant) {
                if (! is_array($variant) || ($variant['matches_question'] ?? false) !== true) {
                    continue;
                }
                $copy = is_string($variant['name'] ?? null) ? trim($variant['name']) : '';
                if ($copy === '') {
                    continue;
                }
                $availability = $variant['availability'] ?? null;
                $copies[] = [
                    'product' => $product,
                    'copy' => $copy,
                    'exists' => true,
                    'availability' => is_string($availability) ? $availability : null,
                ];
            }
        }

        return $copies;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
