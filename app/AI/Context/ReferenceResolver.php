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
        if ($stateIds !== []) {
            return $this->resolved('conversation_state', $stateIds);
        }

        $attachedId = $this->attachedProductId($conversationMetadata);
        if ($attachedId !== null && $this->belongsToWorkspace($workspace, $attachedId)) {
            return $this->resolved('attached_product', [$attachedId]);
        }

        $query = $this->explicitReferenceQuery($currentMessage, $recentMessages);
        if ($query === null) {
            return new ReferenceResolution;
        }

        $lookup = $this->catalog->lookupForAssistant($workspace, $query, 8);
        $items = is_array($lookup['items'] ?? null) ? $lookup['items'] : [];
        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['catalog_item_id'] ?? 0),
            array_filter($items, static fn (mixed $row): bool => is_array($row) && is_numeric($row['catalog_item_id'] ?? null) && (int) $row['catalog_item_id'] > 0),
        )));
        $status = (string) ($lookup['status'] ?? 'not_loaded');

        if ($status === 'listed' && $ids !== []) {
            return $this->resolved('message_reference', $ids, $status, $items);
        }

        if (in_array($status, ['catalog_empty', 'name_not_found', 'search_failed'], true)) {
            return new ReferenceResolution(retrievalStatus: $status);
        }

        return new ReferenceResolution;
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
