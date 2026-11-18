<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

final class AiMediaStagingService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $memoryStore = [];

    /**
     * Stage an attachment to be linked to the outbound message once created.
     *
     * @param  array<string, mixed>  $attachmentData
     */
    public function stageAttachment(int $workspaceId, int $conversationId, array $attachmentData): void
    {
        $key = $this->key($workspaceId, $conversationId);
        $existing = $this->memoryStore[$key] ?? Cache::get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }

        foreach ($existing as $item) {
            if (($item['path'] ?? '') === ($attachmentData['path'] ?? '')) {
                return;
            }
        }

        $existing[] = $attachmentData;
        $this->memoryStore[$key] = $existing;
        Cache::put($key, $existing, 600);
    }

    /**
     * Retrieve and clear all staged attachments for this conversation.
     *
     * @return list<array<string, mixed>>
     */
    public function consumeStagedAttachments(int $workspaceId, int $conversationId): array
    {
        $key = $this->key($workspaceId, $conversationId);
        $staged = $this->memoryStore[$key] ?? Cache::get($key, []);
        $this->clearStagedAttachments($workspaceId, $conversationId);

        return is_array($staged) ? array_values($staged) : [];
    }

    /**
     * Clear staged attachments without returning them.
     */
    public function clearStagedAttachments(int $workspaceId, int $conversationId): void
    {
        $key = $this->key($workspaceId, $conversationId);
        unset($this->memoryStore[$key]);
        Cache::forget($key);
    }

    private function key(int $workspaceId, int $conversationId): string
    {
        return "ai_staged_media:{$workspaceId}:{$conversationId}";
    }
}
