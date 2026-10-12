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

        $rows = $this->catalog->searchForPrompt($workspace, $query, 3, $channel);
        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['catalog_item_id'] ?? 0),
            array_filter($rows, static fn (array $row): bool => is_numeric($row['catalog_item_id'] ?? null) && (int) $row['catalog_item_id'] > 0),
        )));

        if (count($ids) !== 1) {
            return new ReferenceResolution;
        }

        return $this->resolved('message_reference', $ids);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function resolved(string $source, array $productIds): ReferenceResolution
    {
        return new ReferenceResolution(
            resolved: true,
            source: $source,
            productIds: $productIds,
            references: array_map(
                static fn (int $id): AiReference => new AiReference('catalog_item', (string) $id),
                $productIds,
            ),
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
        if ($this->catalog->tokens($currentMessage) !== []) {
            return $currentMessage;
        }

        foreach (array_reverse($recentMessages) as $message) {
            if ($message->role !== 'customer' || $this->catalog->tokens($message->content) === []) {
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
