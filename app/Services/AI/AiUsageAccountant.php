<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

/**
 * Deterministic usage-event claims so retries cannot double-count the same generation.
 */
class AiUsageAccountant
{
    public function claim(int $workspaceId, string $eventKey): bool
    {
        $eventKey = trim($eventKey);
        if ($eventKey === '') {
            return true;
        }

        return Cache::add($this->cacheKey($workspaceId, $eventKey), 1, now()->addDay());
    }

    public function cacheKey(int $workspaceId, string $eventKey): string
    {
        return 'ai-usage-event:'.$workspaceId.':'.$eventKey;
    }

    public function incrementPublicBucket(string $surface): void
    {
        $key = 'ai-public-bucket:'.$surface.':'.now()->toDateString();
        if (Cache::add($key, 1, now()->addDay())) {
            return;
        }

        Cache::increment($key);
    }
}
