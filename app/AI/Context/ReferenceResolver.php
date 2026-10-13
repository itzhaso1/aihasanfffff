<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\Decision\AiReference;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;

final class ReferenceResolver
{
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

        return new ReferenceResolution;
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
