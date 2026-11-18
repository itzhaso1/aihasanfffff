<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\Decision\AiReference;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;

final class ReferenceResolver
{
    public function __construct(
        private readonly ChatCatalogRetrievalService $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $conversationMetadata
     * @param  list<ContextMessage>  $recentMessages
     */
    public function resolve(
        Workspace $workspace,
        array $conversationMetadata,
        ConversationState $state,
        string $currentMessage,
        array $recentMessages = [],
        string $channel = 'chat',
    ): ReferenceResolution {
        $stateIds = $this->knownWorkspaceIds($workspace, $state->referencedProductIds);
        if ($stateIds === []) {
            $factsId = data_get($conversationMetadata, 'conversation_facts.interested_product_id');
            if (is_numeric($factsId) && (int) $factsId > 0 && $this->belongsToWorkspace($workspace, (int) $factsId)) {
                $stateIds = [(int) $factsId];
            }
        }
        $attachedId = $this->attachedProductId($conversationMetadata);

        if ($this->catalog->shouldLookupForAssistant($currentMessage)) {
            $lookup = $this->lookupWithPriorProduct($workspace, $currentMessage, $currentMessage, $recentMessages);
            $items = is_array($lookup['items'] ?? null) ? $lookup['items'] : [];
            $ids = array_values(array_unique(array_map(
                static fn (array $row): int => (int) ($row['catalog_item_id'] ?? 0),
                array_filter($items, static fn (mixed $row): bool => is_array($row) && is_numeric($row['catalog_item_id'] ?? null) && (int) $row['catalog_item_id'] > 0),
            )));
            $status = (string) ($lookup['status'] ?? 'not_loaded');

            if ($status === 'listed' && $ids !== []) {
                return $this->resolved('message_reference', $ids, $status, $items, (bool) ($lookup['has_more'] ?? false));
            }

            if (in_array($status, ['catalog_empty', 'no_matching_type', 'name_not_found', 'search_failed'], true)) {
                if (! $this->isFollowUpOnlyMessage($currentMessage)) {
                    return new ReferenceResolution(retrievalStatus: $status);
                }
            }
        }

        if ($stateIds !== []) {
            $items = $this->catalog->rowsForAssistantIds($workspace, $stateIds);

            return $this->resolved(
                'conversation_state',
                $stateIds,
                $items === [] ? 'name_not_found' : 'listed',
                $items,
            );
        }

        if ($attachedId !== null && $this->belongsToWorkspace($workspace, $attachedId)) {
            $items = $this->catalog->rowsForAssistantIds($workspace, [$attachedId]);

            return $this->resolved(
                'attached_product',
                [$attachedId],
                $items === [] ? 'name_not_found' : 'listed',
                $items,
            );
        }

        $query = $this->explicitReferenceQuery($currentMessage, $recentMessages);
        if ($query !== null && $query !== $currentMessage) {
            $lookup = $this->lookupWithPriorProduct($workspace, $query, $currentMessage, $recentMessages);
            $items = is_array($lookup['items'] ?? null) ? $lookup['items'] : [];
            $ids = array_values(array_unique(array_map(
                static fn (array $row): int => (int) ($row['catalog_item_id'] ?? 0),
                array_filter($items, static fn (mixed $row): bool => is_array($row) && is_numeric($row['catalog_item_id'] ?? null) && (int) $row['catalog_item_id'] > 0),
            )));
            $status = (string) ($lookup['status'] ?? 'not_loaded');

            if ($status === 'listed' && $ids !== []) {
                return $this->resolved('message_reference', $ids, $status, $items, (bool) ($lookup['has_more'] ?? false));
            }

            if (in_array($status, ['catalog_empty', 'no_matching_type', 'name_not_found', 'search_failed'], true)) {
                return new ReferenceResolution(retrievalStatus: $status);
            }
        }

        return new ReferenceResolution;
    }

    private function isFollowUpOnlyMessage(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        $normalized = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $normalized);
        $normalized = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized);

        $followUpPhrases = [
            'كم', 'بكم', 'السعر', 'سعره', 'سعرها', 'اسعارها', 'اسعارهم', 'الاسعار',
            'كم حقه', 'كم حقها', 'كم سعره', 'كم سعرها', 'تكلفه', 'تكلفته',
            'متوفر', 'متوفره', 'موجود', 'موجوده', 'فيه منه', 'في منه',
            'هذا', 'هذي', 'هذه', 'ذا', 'الاول', 'الثاني', 'الثالث',
            'ابي هذا', 'ابغى هذا', 'اريد هذا', 'بدي هذا', 'بدي اياه', 'ابي منه',
            'طيب', 'تمام', 'اوكي', 'اي', 'ايه', 'نعم',
            'ابغاه', 'أبغاه', 'ابيه', 'باخذه', 'باخذها', 'شريت', 'خلاص', 'نفسه', 'نفسها',
            'احجزه', 'احجزلي', 'اعتمد', 'اريده', 'عطني', 'ابيها', 'باخذ', 'خلاص ابغاه',
            'خلاص باخذه', 'ابغاه خلاص', 'باخذه خلاص',
        ];

        foreach ($followUpPhrases as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase.' ') || str_ends_with($normalized, ' '.$phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A follow-up such as "how much is the Arabic copy?" may not repeat the product name.
     * If that question misses, search again with the previous customer message that did refer to a product.
     *
     * @param  list<ContextMessage>  $recentMessages
     * @return array{status: string, items: list<array<string, mixed>>, has_more: bool}
     */
    private function lookupWithPriorProduct(Workspace $workspace, string $query, string $currentMessage, array $recentMessages): array
    {
        $lookup = $this->catalog->lookupForAssistant($workspace, $query, 8);
        if (($lookup['status'] ?? '') === 'listed' || $query !== $currentMessage) {
            return $lookup;
        }

        foreach (array_reverse($recentMessages) as $message) {
            if ($message->role !== 'customer' || $message->content === $currentMessage) {
                continue;
            }
            if (! $this->catalog->shouldLookupForAssistant($message->content)) {
                continue;
            }

            $combined = $this->catalog->lookupForAssistant($workspace, $message->content.' '.$currentMessage, 8);
            if (($combined['status'] ?? '') === 'listed') {
                return $combined;
            }
        }

        return $lookup;
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<array<string, mixed>>  $catalogItems
     */
    private function resolved(
        string $source,
        array $productIds,
        string $retrievalStatus = 'not_loaded',
        array $catalogItems = [],
        bool $hasMore = false,
    ): ReferenceResolution {
        return new ReferenceResolution(
            resolved: true,
            source: $source,
            productIds: $productIds,
            references: array_map(
                static fn (int $id): AiReference => new AiReference('catalog_item', (string) $id),
                $productIds,
            ),
            retrievalStatus: $retrievalStatus,
            catalogItems: $catalogItems,
            hasMore: $hasMore,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function attachedProductId(array $metadata): ?int
    {
        $attached = data_get($metadata, 'catalog.attached_product');
        if (! is_array($attached)) {
            return null;
        }

        $candidate = $attached['catalog_item_id'] ?? $attached['id'] ?? null;

        return is_numeric($candidate) && (int) $candidate > 0 ? (int) $candidate : null;
    }

    /**
     * @param  list<ContextMessage>  $recentMessages
     */
    private function explicitReferenceQuery(string $currentMessage, array $recentMessages): ?string
    {
        if ($this->catalog->shouldLookupForAssistant($currentMessage)) {
            return $currentMessage;
        }

        foreach (array_reverse($recentMessages) as $message) {
            if ($message->role !== 'customer' || ! $this->catalog->shouldLookupForAssistant($message->content)) {
                continue;
            }

            return $message->content;
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function knownWorkspaceIds(Workspace $workspace, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $known = ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($known, SORT_NUMERIC);

        return $known;
    }

    private function belongsToWorkspace(Workspace $workspace, int $itemId): bool
    {
        return ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($itemId)
            ->exists();
    }
}
