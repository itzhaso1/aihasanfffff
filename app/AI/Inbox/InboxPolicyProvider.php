<?php

declare(strict_types=1);

namespace App\AI\Inbox;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\PolicyProvider;
use App\AI\Core\Data\ResolvedEmployee;

/**
 * Inbox runs use the constitution as their policy until a dedicated policy layer exists.
 */
final class InboxPolicyProvider implements PolicyProvider
{
    public function sections(AiRequest $request, ResolvedEmployee $employee): array
    {
        return [];
    }
}
